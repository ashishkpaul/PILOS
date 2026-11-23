---
title: Saa9vi Entitlement Integration API
description: Write API contract for synchronizing Saa9vi subscription entitlements to PILOS users.
---

# Saa9vi Entitlement Integration API (v1)

This document describes the machine-to-machine API used by Vendure to synchronize the current Saa9vi subscription entitlement for a PILOS OIDC user.

Vendure is the subscription authority. Keycloak remains the identity provider. PILOS stores a local entitlement snapshot so meeting-start authorization does not depend on a user logging in again after a subscription change.

## Endpoint

`PUT /api/integration/v1/entitlements`

The endpoint is idempotent for the same user, version, status, and plan.

### Authentication and authorization

The request must include a persisted Sanctum personal access token:

```http
PUT /api/integration/v1/entitlements
Authorization: Bearer <plain-text-token>
Content-Type: application/json
```

The token must:

- Be a persisted Sanctum personal access token, not a browser session token.
- Have a finite future expiry.
- Have the explicit ability `pilos.entitlements.write`.
- Not contain the wildcard ability `*`.
- Belong to a non-superuser integration account holding the `pilos.integration.manageEntitlements` permission.

The entitlement write token should be separate from the read-only usage-import token. The read-only `pilos.integration.viewUsage` permission does not authorize entitlement changes.

## User identity and plan-role prerequisites

`external_id` is matched only against PILOS users whose `authenticator` is `oidc`. It must contain the immutable OIDC subject (`sub`) mapped to PILOS's `external_id` attribute. Email addresses must not be used for this lookup.

Configure role names using `PILOS_ROLE_STARTER`, `PILOS_ROLE_GROWTH`, and `PILOS_ROLE_PRO` (defaults shown below). The endpoint may modify only the roles in this explicit map. Create these reserved PILOS roles before sending active entitlements:

| Entitlement plan | Required PILOS role |
| ---------------- | ------------------- |
| `STARTER`        | `seller-starter`    |
| `GROWTH`         | `seller-growth`     |
| `PRO`            | `seller-pro`        |

These role names are reserved for Saa9vi entitlement synchronization. The endpoint replaces only these three roles and preserves all other PILOS roles.

The endpoint synchronizes one PILOS user at a time. Vendure must call it for every tenant moderator account that can own a PILOS room. PILOS rooms are owned by individual user IDs; this API does not infer tenant membership from email addresses or room names.

## Request body

Active Starter entitlement:

```json
{
  "external_id": "<keycloak-subject>",
  "status": "ACTIVE",
  "plan": "STARTER",
  "version": 12
}
```

Suspended entitlement:

```json
{
  "external_id": "<keycloak-subject>",
  "status": "SUSPENDED",
  "plan": null,
  "version": 13
}
```

Fields:

| Field         | Type        | Rules                                                                                  |
| ------------- | ----------- | -------------------------------------------------------------------------------------- |
| `external_id` | string      | Required; exact OIDC subject mapped to PILOS `external_id`                             |
| `status`      | string      | Required; `ACTIVE` or `SUSPENDED`                                                      |
| `plan`        | string/null | Required for `ACTIVE`; one of `STARTER`, `GROWTH`, `PRO`; must be null for `SUSPENDED` |
| `version`     | integer     | Required; monotonically increasing per PILOS user, starting at 1                       |

## Update semantics

1. The endpoint resolves exactly one OIDC user using `authenticator = oidc` and `external_id`. No match returns HTTP 200 with `status=NOOP` and `reason=OIDC_USER_NOT_CREATED`; Keycloak supplies the current plan claim on first login. Ambiguous matches return 409.
2. A version lower than the stored version returns 409 and makes no change.
3. Reusing the current version with a different status or plan returns 409.
4. Repeating the current version with the same status and plan is accepted and re-applies the managed role state. This makes retries safe if a previous caller timed out after the database transaction committed.
5. A newer `ACTIVE` update clears `suspended_at`, stores the plan, and replaces any existing Saa9vi plan role with the selected plan role and marks the role association `automatic=true`.
6. A newer `SUSPENDED` update sets `suspended_at`, clears the plan, and removes all Saa9vi plan roles.
7. All database updates and managed-role changes run in one transaction. OIDC login attribute and role synchronization takes the same user-row lock so the writers serialize for existing users.

The version must be incremented by the Vendure entitlement source whenever the authoritative status or plan changes. Reuse the same version for retries of the same event. Do not generate a new version for a transport retry.

## Response

```json
{
  "data": {
    "pilos_user_id": 42,
    "status": "ACTIVE",
    "plan": "STARTER",
    "version": 12,
    "entitlement_managed": true,
    "suspended_at": null
  }
}
```

The OIDC subject is not echoed in the response.

## Meeting-start enforcement

The meeting-start service checks the room owner's entitlement after confirming that the room does not already have a running meeting and before creating a new meeting or contacting BigBlueButton.

For an entitlement-managed owner, a non-null `suspended_at` or a missing plan blocks new meeting creation with custom status `473` (`SUBSCRIPTION_INACTIVE`).

This check is performed in `RoomService`, below the room-start policy. It therefore applies regardless of whether the start request is made by the owner, a moderator, a co-owner, a guest moderator link, or a user affected by `everyone_can_start`. The entitlement is checked against the room owner, not the person who pressed Start.

The check does not terminate an existing meeting and does not block participants from joining a meeting that is already running. The existing Room Type permission check remains responsible for enforcing plan-specific room types after a downgrade.

`entitlement_managed` defaults to false so existing non-Saa9vi PILOS accounts are not silently enrolled in Saa9vi subscription enforcement. Before enabling this integration in production, Vendure must synchronize the initial entitlement for every Saa9vi account that owns rooms. New Saa9vi room owners must be synchronized before they are allowed to create rooms.

## Keycloak and session lifecycle

This endpoint does not change Keycloak roles or terminate Keycloak sessions. Vendure must continue to:

- Replace the seller's Keycloak plan role on upgrades and downgrades.
- Remove the plan role on suspension or expiry.
- Terminate active Keycloak sessions for affected tenant users when access must be revoked.
- Call this PILOS endpoint with the matching authoritative state and a monotonically increasing version.

PILOS's OIDC login role mapping remains useful for normal login synchronization, but it is no longer the only mechanism that updates the local subscription state. The `suspended_at` check is independent of the next login.

## Error responses

| Status | Condition                                                                                   |
| ------ | ------------------------------------------------------------------------------------------- |
| 401    | Unauthenticated, expired token, transient session token, or revoked token                   |
| 403    | Missing entitlement-management permission or token ability; wildcard or non-expiring token  |
| 200    | No OIDC user exists yet; response is a successful `NOOP` for first-login provisioning      |
| 409    | Ambiguous OIDC identity, stale/conflicting version, or required plan role is not configured |
| 422    | Invalid request fields or inconsistent status/plan combination                              |

## Operational guidance

- Provision the three plan roles and their restricted Room Type associations before activating sellers.
- Keep the integration token in a secret store and rotate it using the same controlled procedure as the read-only usage token.
- Use a durable Vendure outbox/job so Keycloak and PILOS updates can be retried and reconciled independently.
- Keep the tenant-to-PILOS-user mapping in Vendure. A subscription belongs to a Vendure Channel, while the entitlement snapshot is applied to each PILOS user who can own rooms.
- Do not use attendee-hour data for Phase 1 flat monthly subscription charges. The read-only usage API remains available for meeting history, reporting, and support.
