<?php

declare(strict_types=1);

namespace Tests\Backend\Feature\api\integration;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Backend\TestCase;

class PilosEntitlementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $integrationUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.guard' => 'users']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->integrationUser = User::factory()->create();
        $role = Role::factory()->create(['name' => 'Saa9vi entitlement integration']);
        $permission = Permission::query()
            ->where('name', 'pilos.integration.manageEntitlements')
            ->firstOrFail();

        $role->permissions()->attach($permission->id);
        $this->integrationUser->roles()->attach($role->id);
    }

    private function token(array $abilities = ['pilos.entitlements.write']): string
    {
        return $this->integrationUser
            ->createToken('saa9vi-entitlement-test', $abilities, now()->addHour())
            ->plainTextToken;
    }

    private function createPlanRoles(): void
    {
        foreach (['seller-starter', 'seller-growth', 'seller-pro'] as $name) {
            Role::factory()->create(['name' => $name]);
        }
    }

    private function oidcUser(string $externalId = 'keycloak-subject-1'): User
    {
        return User::factory()->create([
            'authenticator' => 'oidc',
            'external_id' => $externalId,
        ]);
    }

    private function putEntitlement(array $payload, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token())
            ->putJson(route('api.integration.v1.entitlements.update'), $payload);
    }

    public function test_entitlement_endpoint_requires_authentication(): void
    {
        $this->putJson(route('api.integration.v1.entitlements.update'), [
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 1,
        ])->assertUnauthorized();
    }

    public function test_entitlement_endpoint_requires_write_ability(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => 'Usage reader only']);
        $permission = Permission::query()
            ->where('name', 'pilos.integration.viewUsage')
            ->firstOrFail();
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $token = $user->createToken(
            'usage-only',
            ['pilos.rooms.read'],
            now()->addHour()
        )->plainTextToken;

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 1,
        ], $token)->assertForbidden();
    }

    public function test_entitlement_endpoint_requires_dedicated_permission(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken(
            'without-entitlement-permission',
            ['pilos.entitlements.write'],
            now()->addHour()
        )->plainTextToken;

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 1,
        ], $token)->assertForbidden();
    }

    public function test_active_entitlement_replaces_only_saa9vi_plan_roles(): void
    {
        $this->createPlanRoles();
        $user = $this->oidcUser();

        $growth = Role::query()->where('name', 'seller-growth')->firstOrFail();
        $otherRole = Role::factory()->create(['name' => 'Tenant support']);
        $user->roles()->attach($growth->id, ['automatic' => true]);
        $user->roles()->attach($otherRole->id, ['automatic' => false]);

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 4,
        ])->assertOk()
            ->assertJsonPath('data.pilos_user_id', $user->id)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.plan', 'STARTER')
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.entitlement_managed', true)
            ->assertJsonPath('data.suspended_at', null);

        $user->refresh();
        $roleNames = $user->roles()->pluck('name')->all();

        $this->assertContains('seller-starter', $roleNames);
        $this->assertNotContains('seller-growth', $roleNames);
        $this->assertNotContains('seller-pro', $roleNames);
        $this->assertContains('Tenant support', $roleNames);
        $this->assertTrue((bool) $user->roles()->where('name', 'seller-starter')->firstOrFail()->pivot->automatic);
        $this->assertTrue($user->entitlement_managed);
        $this->assertNull($user->suspended_at);
        $this->assertSame('STARTER', $user->entitlement_plan);
        $this->assertSame(4, $user->entitlement_version);
    }

    public function test_suspended_entitlement_sets_timestamp_and_removes_plan_roles(): void
    {
        $this->createPlanRoles();
        $user = $this->oidcUser();
        $pro = Role::query()->where('name', 'seller-pro')->firstOrFail();
        $otherRole = Role::factory()->create(['name' => 'Room moderator']);
        $user->roles()->attach($pro->id, ['automatic' => true]);
        $user->roles()->attach($otherRole->id, ['automatic' => false]);

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'SUSPENDED',
            'plan' => null,
            'version' => 5,
        ])->assertOk()
            ->assertJsonPath('data.status', 'SUSPENDED')
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.version', 5);

        $user->refresh();
        $roleNames = $user->roles()->pluck('name')->all();

        $this->assertTrue($user->entitlement_managed);
        $this->assertNotNull($user->suspended_at);
        $this->assertNull($user->entitlement_plan);
        $this->assertNotContains('seller-starter', $roleNames);
        $this->assertNotContains('seller-growth', $roleNames);
        $this->assertNotContains('seller-pro', $roleNames);
        $this->assertContains('Room moderator', $roleNames);
    }

    public function test_identical_entitlement_retry_is_idempotent(): void
    {
        $this->createPlanRoles();
        $user = $this->oidcUser();
        $payload = [
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'GROWTH',
            'version' => 7,
        ];

        $this->putEntitlement($payload)->assertOk();
        $this->putEntitlement($payload)->assertOk();

        $user->refresh();
        $this->assertSame(1, $user->roles()->where('name', 'seller-growth')->count());
        $this->assertSame(1, $user->roles()->whereIn('name', [
            'seller-starter',
            'seller-growth',
            'seller-pro',
        ])->count());
        $this->assertSame(7, $user->entitlement_version);
    }

    public function test_stale_entitlement_version_is_rejected_without_changing_state(): void
    {
        $this->createPlanRoles();
        $user = $this->oidcUser();

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 8,
        ])->assertOk();

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'PRO',
            'version' => 7,
        ])->assertStatus(409);

        $user->refresh();
        $this->assertSame('STARTER', $user->entitlement_plan);
        $this->assertSame(8, $user->entitlement_version);
        $this->assertSame(1, $user->roles()->where('name', 'seller-starter')->count());
        $this->assertSame(0, $user->roles()->where('name', 'seller-pro')->count());
    }

    public function test_same_version_cannot_be_reused_for_a_different_state(): void
    {
        $this->createPlanRoles();
        $user = $this->oidcUser();

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 9,
        ])->assertOk();

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'GROWTH',
            'version' => 9,
        ])->assertStatus(409);

        $user->refresh();
        $this->assertSame('STARTER', $user->entitlement_plan);
        $this->assertSame(9, $user->entitlement_version);
    }

    public function test_missing_oidc_user_returns_successful_noop_before_first_login(): void
    {
        $this->putEntitlement([
            'external_id' => 'unknown-subject',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'NOOP')
            ->assertJsonPath('data.reason', 'OIDC_USER_NOT_CREATED');
    }

    public function test_local_user_is_not_matched_by_external_id(): void
    {
        User::factory()->create([
            'authenticator' => 'local',
            'external_id' => 'local-external-id',
        ]);

        $this->putEntitlement([
            'external_id' => 'local-external-id',
            'status' => 'SUSPENDED',
            'plan' => null,
            'version' => 1,
        ])->assertNotFound();
    }

    public function test_active_entitlement_requires_configured_plan_role(): void
    {
        $user = $this->oidcUser();

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => 'STARTER',
            'version' => 1,
        ])->assertStatus(409);

        $user->refresh();
        $this->assertFalse($user->entitlement_managed);
        $this->assertNull($user->entitlement_plan);
        $this->assertNull($user->suspended_at);
    }

    public function test_invalid_status_and_plan_combinations_are_rejected(): void
    {
        $this->oidcUser();

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'ACTIVE',
            'plan' => null,
            'version' => 1,
        ])->assertUnprocessable();

        $this->putEntitlement([
            'external_id' => 'keycloak-subject-1',
            'status' => 'SUSPENDED',
            'plan' => 'STARTER',
            'version' => 1,
        ])->assertUnprocessable();
    }

    public function test_suspended_room_owner_cannot_start_a_new_meeting(): void
    {
        $room = Room::factory()->create();
        $owner = $room->owner;
        $owner->entitlement_managed = true;
        $owner->entitlement_plan = null;
        $owner->suspended_at = now();
        $owner->save();

        try {
            (new RoomService($room))->start();
            $this->fail('A suspended room owner must not start a new meeting.');
        } catch (HttpException $exception) {
            $this->assertSame(473, $exception->getStatusCode());
        }

        $this->assertSame(0, $room->meetings()->count());
    }

    public function test_managed_owner_without_a_plan_fails_closed(): void
    {
        $room = Room::factory()->create();
        $owner = $room->owner;
        $owner->entitlement_managed = true;
        $owner->entitlement_plan = null;
        $owner->suspended_at = null;
        $owner->save();

        try {
            (new RoomService($room))->start();
            $this->fail('A managed account without a plan must not start a new meeting.');
        } catch (HttpException $exception) {
            $this->assertSame(473, $exception->getStatusCode());
        }

        $this->assertSame(0, $room->meetings()->count());
    }
}
