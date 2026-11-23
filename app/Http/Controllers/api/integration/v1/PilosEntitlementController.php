<?php

declare(strict_types=1);

namespace App\Http\Controllers\api\integration\v1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative, idempotent entitlement synchronization for Saa9vi-managed
 * PILOS users. The OIDC subject is resolved through the configured external_id
 * mapping; email addresses are deliberately not used as identifiers.
 */
class PilosEntitlementController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_id' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED'])],
            'plan' => ['present', 'nullable', 'string', Rule::in(['STARTER', 'GROWTH', 'PRO'])],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        $status = $validated['status'];
        $plan = $validated['plan'] ?? null;
        $version = (int) $validated['version'];

        if ($status === 'ACTIVE' && $plan === null) {
            throw ValidationException::withMessages([
                'plan' => 'An active entitlement requires a plan.',
            ]);
        }

        if ($status === 'SUSPENDED' && $plan !== null) {
            throw ValidationException::withMessages([
                'plan' => 'A suspended entitlement must not include a plan.',
            ]);
        }

        return DB::transaction(function () use ($validated, $status, $plan, $version): JsonResponse {
            // external_id is unique for the configured OIDC issuer in normal
            // operation, but the schema's composite unique key does not enforce
            // that invariant. Fail closed if the mapping is ambiguous.
            $matches = User::query()
                ->where('authenticator', 'oidc')
                ->where('external_id', $validated['external_id'])
                ->limit(2)
                ->get();

            if ($matches->isEmpty()) {
                // An entitlement can precede the first PILOS login. Keycloak
                // supplies the authoritative plan claim when the user appears.
                return response()->json([
                    'data' => [
                        'status' => 'NOOP',
                        'reason' => 'OIDC_USER_NOT_CREATED',
                    ],
                ]);
            }

            if ($matches->count() !== 1) {
                abort(409, 'The supplied external_id matches more than one OIDC user.');
            }

            /** @var User $candidate */
            $candidate = $matches->first();
            /** @var User $user */
            $user = User::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            $currentVersion = (int) $user->entitlement_version;

            if ($version < $currentVersion) {
                abort(409, 'The entitlement update is older than the stored version.');
            }

            if ($version === $currentVersion && $user->entitlement_managed) {
                $currentStatus = $user->suspended_at === null && $user->entitlement_plan !== null
                    ? 'ACTIVE'
                    : 'SUSPENDED';

                if ($currentStatus !== $status || $user->entitlement_plan !== $plan) {
                    abort(409, 'The entitlement version has already been used for a different state.');
                }
            }

            $planRoleMap = (array) config('services.pilos_entitlements.plan_roles', []);
            $allowedRoleNames = array_values(array_unique(array_filter($planRoleMap, 'is_string')));
            if (count($allowedRoleNames) !== count($planRoleMap) || $allowedRoleNames === []) {
                abort(500, 'Saa9vi plan-role mapping is not configured safely.');
            }

            $configuredRoleCount = Role::query()->whereIn('name', $allowedRoleNames)->count();
            if ($configuredRoleCount !== count($allowedRoleNames)) {
                abort(409, 'One or more required Saa9vi plan roles are not configured in PILOS.');
            }

            $planRoles = Role::query()->whereIn('name', $allowedRoleNames)->get();

            $targetRole = null;
            if ($status === 'ACTIVE') {
                $targetRoleName = $planRoleMap[$plan];
                $targetRole = $planRoles->firstWhere('name', $targetRoleName);

                if ($targetRole === null) {
                    abort(409, 'The required Saa9vi plan role is not configured in PILOS.');
                }
            }

            // Saa9vi plan roles are a reserved, integration-managed role set.
            // Other manually or automatically assigned PILOS roles are preserved.
            $user->roles()->detach($planRoles->modelKeys());

            if ($targetRole !== null) {
                $user->roles()->syncWithoutDetaching([
                    $targetRole->id => ['automatic' => true],
                ]);
            }

            $user->entitlement_managed = true;
            $user->entitlement_plan = $plan;
            $user->entitlement_version = $version;

            if ($status === 'SUSPENDED') {
                // Preserve the original timestamp on an identical retry.
                $user->suspended_at = $user->suspended_at ?? now();
            } else {
                $user->suspended_at = null;
            }

            $user->save();

            return response()->json([
                'data' => [
                    'pilos_user_id' => $user->id,
                    'status' => $status,
                    'plan' => $user->entitlement_plan,
                    'version' => $user->entitlement_version,
                    'entitlement_managed' => $user->entitlement_managed,
                    'suspended_at' => $user->suspended_at?->toISOString(),
                ],
            ]);
        });
    }
}
