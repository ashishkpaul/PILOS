<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict Saa9vi's machine API to real Sanctum bearer tokens with explicit
 * abilities, issued to a PILOS user holding the dedicated integration grant.
 *
 * Sanctum session authentication returns a TransientToken. Requiring the
 * persisted PersonalAccessToken model prevents browser sessions from using
 * these machine-to-machine endpoints.
 */
class RequirePilosIntegrationToken
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            abort(401, 'A personal access token is required.');
        }

        $requiredPermission = match ($ability) {
            'pilos.entitlements.write' => 'pilos.integration.manageEntitlements',
            default => 'pilos.integration.viewUsage',
        };

        if (! $user->hasPermission($requiredPermission)) {
            abort(403, 'The PILOS integration account is not authorized.');
        }

        $abilities = $token->abilities;

        if (! is_array($abilities) || in_array('*', $abilities, true)) {
            abort(403, 'Integration tokens must not use wildcard abilities.');
        }

        if ($token->expires_at === null) {
            abort(403, 'Integration tokens must have an expiration date.');
        }

        if (! in_array($ability, $abilities, true)) {
            abort(403, 'The access token does not have the required ability.');
        }

        return $next($request);
    }
}
