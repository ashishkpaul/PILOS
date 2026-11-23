---
title: Saa9vi Usage Integration API
description: Read-only API contract for Saa9vi to import PILOS room and meeting usage data.
---

# Saa9vi Usage Integration API (v1)

This document describes the read-only integration API that Saa9vi uses to import PILOS room and meeting usage data. The API is intentionally narrow: it exposes only identifiers, timestamps, and aggregate counts. Attendee names, email addresses, individual session details, and other personally identifiable information are never returned.

## Overview

| Property           | Value                                                                          |
| ------------------ | ------------------------------------------------------------------------------ |
| Base path          | `/api/integration/v1`                                                          |
| Authentication     | Sanctum bearer token (`Authorization: Bearer <token>`)                         |
| Token type         | Persisted Sanctum `PersonalAccessToken` only                                   |
| Authorization      | Dedicated `pilos.integration.viewUsage` permission plus per-endpoint abilities |
| Token expiry       | Must be a finite, future timestamp; null expiry is rejected                    |
| Wildcard abilities | Rejected (`*` is not allowed)                                                  |
| Access pattern     | Read-only GET endpoints                                                        |

All routes are grouped under the `integration/v1` prefix and require Sanctum authentication. Machine-to-machine session cookies (transient tokens) are not accepted.

## Authentication and authorization

Every request must include a valid Sanctum personal access token as a bearer token:

```http
GET /api/integration/v1/rooms
Authorization: Bearer <plain-text-token>
```

The integration middleware enforces the following checks, in order:

1. **Authenticated user** — The request must be authenticated as a PILOS `User`. Session-based authentication returns 401.
2. **Persisted token** — The current access token must be a persisted `PersonalAccessToken`. Transient browser-session tokens return 401.
3. **Integration permission** — The authenticated user must hold the `pilos.integration.viewUsage` permission. Returns 403 if missing.
4. **No wildcard abilities** — The token's abilities array must not contain `*`. Returns 403.
5. **Finite expiry** — The token's `expires_at` must not be null. Returns 403.
6. **Endpoint ability** — The token must have the ability required by the specific endpoint (see below). Returns 403 if missing.

### Token abilities

| Endpoint                             | Required ability        |
| ------------------------------------ | ----------------------- |
| `GET /rooms`                         | `pilos.rooms.read`      |
| `GET /rooms/{room}/meetings`         | `pilos.meetings.read`   |
| `GET /meetings/{meeting}/attendance` | `pilos.attendance.read` |
| `GET /meetings/{meeting}/stats`      | `pilos.statistics.read` |

### Token provisioning (operational)

Provisioning is an administrative operation outside this read-only API. Recommended lifecycle:

1. **Provision** — Create a dedicated non-superuser PILOS user account. Assign only the `pilos.integration.viewUsage` permission.
2. **Issue** — Create a named Sanctum token with the four explicit abilities above and a finite expiry.
3. **Store** — Reveal the plaintext token once and store it in a secure secret store. Never log or commit it.
4. **Use** — Saa9vi sends the token as a Bearer header to the read-only endpoints.
5. **Rotate** — Issue a replacement token, update Saa9vi's secret, verify the new token works, then revoke the old one.
6. **Revoke** — Delete the old token record. Sanctum immediately stops accepting it.
7. **Clean up** — A scheduled `sanctum:prune-expired --hours=24` command runs daily to remove expired token records from the database.

## Endpoints

### List rooms

`GET /api/integration/v1/rooms`

Returns rooms using only the identifiers and labels needed for room-to-channel binding. Results are paginated.

**Query parameters:**

| Parameter  | Type    | Default                                         | Constraints   |
| ---------- | ------- | ----------------------------------------------- | ------------- |
| `page`     | integer | 1                                               | Min 1         |
| `per_page` | integer | `general.pagination_page_size` (see note below) | Min 1, max 20 |

**Response (200):**

```json
{
  "data": [
    {
      "id": "room-uuid",
      "name": "Saa9vi importer test room",
      "owner_id": 1,
      "updated_at": "2026-11-22T10:00:00+00:00"
    }
  ],
  "current_page": 1,
  "per_page": 20,
  "from": 1,
  "to": 20,
  "total": 1,
  "last_page": 1
}
```

### List meetings for a room

`GET /api/integration/v1/rooms/{room}/meetings`

Returns completed meeting history for one room. Only meetings with both a `start` and `end` timestamp are included; running and not-yet-ended meetings are excluded so they cannot be mistaken for final usage facts. Results are paginated.

**Path parameters:**

| Parameter | Type | Description           |
| --------- | ---- | --------------------- |
| `room`    | UUID | PILOS room identifier |

**Query parameters:**

| Parameter        | Type    | Default                                         | Constraints     |
| ---------------- | ------- | ----------------------------------------------- | --------------- |
| `page`           | integer | 1                                               | Min 1           |
| `per_page`       | integer | `general.pagination_page_size` (see note below) | Min 1, max 20   |
| `sort_direction` | string  | `desc`                                          | `asc` or `desc` |

**Response (200):**

```json
{
  "data": [
    {
      "id": "meeting-uuid",
      "room_id": "room-uuid",
      "start": "2026-11-22T09:00:00+00:00",
      "end": "2026-11-22T10:00:00+00:00",
      "updated_at": "2026-11-22T10:05:00+00:00",
      "attendance_recording_enabled": true,
      "attendance_data_status": "AVAILABLE",
      "attendance_available": true,
      "statistics_available": true
    }
  ],
  "current_page": 1,
  "per_page": 20,
  "from": 1,
  "to": 20,
  "total": 1,
  "last_page": 1
}
```

Meeting records are sorted by `start` timestamp (descending by default), with `id` as a secondary sort key for deterministic ordering when timestamps are equal.

### Get meeting attendance

`GET /api/integration/v1/meetings/{meeting}/attendance`

Returns an aggregate of recorded attendee time for a completed meeting. Personal identifiers and per-attendee session details remain inside PILOS.

**Path parameters:**

| Parameter | Type | Description              |
| --------- | ---- | ------------------------ |
| `meeting` | UUID | PILOS meeting identifier |

**Response (200):**

A successful response is returned only when `attendance_data_status` is `EMPTY` or `AVAILABLE`. A zero-length session (`leave` equals `join`) is valid and contributes zero seconds, so `attendee_seconds` can legitimately be `0` for a meeting that had attendees.

```json
{
  "data": {
    "meeting_id": "meeting-uuid",
    "room_id": "room-uuid",
    "start": "2026-11-22T09:00:00+00:00",
    "end": "2026-11-22T10:00:00+00:00",
    "attendance_data_status": "AVAILABLE",
    "attendance_available": true,
    "participant_count": 3,
    "attendee_seconds": 7200
  }
}
```

**Error responses:**

| Status | Condition                                                                               |
| ------ | --------------------------------------------------------------------------------------- |
| 404    | Unknown meeting                                                                         |
| 409    | Incomplete attendance records (a row is missing `leave`, or has no stable identity key) |
| 469    | Meeting has not ended yet                                                               |
| 470    | Attendance recording is disabled for this meeting                                       |

Clients must not expect a response body with `attendance_data_status` of `DISABLED` or `INCOMPLETE` from this endpoint. Those states are rejected with 470 and 409. Use the meetings list endpoint to see all four statuses before requesting attendance.

### Get meeting statistics

`GET /api/integration/v1/meetings/{meeting}/stats`

Returns meeting utilisation snapshots without participant identity. Requires the `meeting_usage_enabled` recording setting to be active.

**Path parameters:**

| Parameter | Type | Description              |
| --------- | ---- | ------------------------ |
| `meeting` | UUID | PILOS meeting identifier |

**Response (200):**

```json
{
  "data": {
    "meeting_id": "meeting-uuid",
    "room_id": "room-uuid",
    "statistics": [
      {
        "id": 123,
        "participant_count": 5,
        "listener_count": 3,
        "voice_participant_count": 2,
        "video_count": 1,
        "created_at": "2026-11-22T09:30:00+00:00"
      }
    ]
  }
}
```

**Response (404):** Unknown meeting.

**Response (468):** Statistics feature disabled (`CustomStatusCodes::FEATURE_DISABLED`).

**Response (409):** Meeting not yet ended.

## Attendance data status

The meetings list and attendance endpoint report attendance readiness through two fields:

| Field                    | Type    | Meaning                                           |
| ------------------------ | ------- | ------------------------------------------------- |
| `attendance_data_status` | string  | `DISABLED`, `EMPTY`, `AVAILABLE`, or `INCOMPLETE` |
| `attendance_available`   | boolean | `true` only when status is `AVAILABLE`            |

| Status       | Meaning                                           | `attendance_available` |
| ------------ | ------------------------------------------------- | ---------------------- |
| `DISABLED`   | Attendance recording is disabled for this meeting | `false`                |
| `EMPTY`      | Recording enabled, but no attendee rows exist     | `false`                |
| `AVAILABLE`  | At least one row exists and every row is valid    | `true`                 |
| `INCOMPLETE` | One or more rows have invalid or incomplete data  | `false`                |

A row is invalid when any of the following holds:

- `leave` is null
- Both `user_id` and `session_id` are null (no stable identity key)
- `leave` is earlier than `join`

A zero-length session (`leave` equals `join`) is valid and contributes zero seconds.

## Pagination

Both list endpoints (`rooms` and `rooms/{room}/meetings`) use Laravel's paginator. The response root includes:

| Field          | Type    | Description                     |
| -------------- | ------- | ------------------------------- |
| `current_page` | integer | Current page number             |
| `per_page`     | integer | Records per page                |
| `from`         | integer | First record index on this page |
| `to`           | integer | Last record index on this page  |
| `total`        | integer | Total matching records          |
| `last_page`    | integer | Total number of pages           |

**Validation rules:**

| Parameter                        | Rule                   | Violation response |
| -------------------------------- | ---------------------- | ------------------ |
| `page`                           | integer, min 1         | 422                |
| `per_page`                       | integer, min 1, max 20 | 422                |
| `sort_direction` (meetings only) | `asc` or `desc`        | 422                |

Requests for a page beyond the final page return 200 with an empty `data` array.

**Default page size:** The default `per_page` value is taken from the `general.pagination_page_size` setting and clamped to the range 1 to 20. The maximum page size is always 20.

## Error responses

PILOS intentionally returns custom status codes for some business conditions, not standard HTTP codes:

| Code | Condition                                                           | Endpoint(s)                           |
| ---- | ------------------------------------------------------------------- | ------------------------------------- |
| 401  | Unauthenticated, expired token, or revoked token                    | All                                   |
| 403  | Missing permission, missing ability, wildcard token, or null expiry | All                                   |
| 404  | Unknown room or meeting                                             | Meetings list, attendance, statistics |
| 409  | Meeting not yet ended                                               | Statistics                            |
| 409  | Incomplete attendance records (missing leave or missing identity)   | Attendance                            |
| 468  | Statistics feature disabled                                         | Statistics                            |
| 469  | Meeting not yet ended                                               | Attendance                            |
| 470  | Attendance recording disabled                                       | Attendance                            |
| 422  | Invalid pagination parameters                                       | Rooms list, meetings list             |

All error responses use Laravel's standard JSON validation/error format.

## Privacy and data limitations

- **No attendee PII:** Names, email addresses, and individual session details are never returned.
- **No role classification:** Attendees are not classified as trainers, moderators, guests, etc.
- **Distinct identity count:** `participant_count` is a count of distinct PILOS identity keys (`user_id` or `session_id`), not necessarily a count of distinct human beings. It must not be used as proof of unique billable people.
- **Attendee seconds:** Raw session duration sums should not be converted directly into billable attendee-hours until the treatment of trainers, moderators, guests, and overlapping sessions has been agreed.

## Synchronization guidance

PILOS should be treated as a source of usage facts, not as a billing authority. Saa9vi should retain its own immutable usage ledger, import watermarks, reconciliation records, and tenant/channel association.

Recommended importer behavior:

1. Request `sort_direction=asc` for deterministic, forward-only pagination.
2. Deduplicate by the PILOS meeting UUID.
3. Retain a per-room watermark with an overlap/reconciliation strategy, since new meetings inserted while reading pages can shift page boundaries with offset-based pagination.
4. Only treat meetings with both `start` and `end` non-null as final usage facts; the meetings list already excludes unfinished meetings.
5. Check `attendance_data_status` before importing attendance data; `INCOMPLETE` status means some rows cannot be safely aggregated.

## Architecture boundary

The current attendance contract does not provide attendee roles or stable human identities. Therefore, attendee counts or seconds cannot yet distinguish billable students from trainers, moderators, or guests. That billing policy and the data needed to enforce it must be settled before mapping PILOS usage into Saa9vi's billing ledger.
