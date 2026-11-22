<?php

declare(strict_types=1);

namespace Tests\Backend\Feature\api\integration;

use App\Models\Meeting;
use App\Models\MeetingStat;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Settings\RecordingSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Backend\TestCase;

class PilosUsageApiTest extends TestCase
{
    use RefreshDatabase;

    private User $integrationUser;

    protected function setUp(): void
    {
        parent::setUp();

        // The PILOS deployment sets this in .env.pilos. Set it explicitly for
        // the isolated test application so Sanctum resolves a real guard.
        config(['sanctum.guard' => 'users']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->integrationUser = User::factory()->create();
        $role = Role::factory()->create(['name' => 'Saa9vi integration']);
        $permission = Permission::query()
            ->where('name', 'pilos.integration.viewUsage')
            ->firstOrFail();

        $role->permissions()->attach($permission->id);
        $this->integrationUser->roles()->attach($role->id);
    }

    private function token(array $abilities = ['pilos.rooms.read']): string
    {
        return $this->integrationUser
            ->createToken('saa9vi-test', $abilities, now()->addHour())
            ->plainTextToken;
    }

    public function test_integration_endpoint_requires_authentication(): void
    {
        $this->getJson(route('api.integration.v1.rooms.index'))
            ->assertUnauthorized();
    }

    public function test_session_authentication_is_not_accepted_for_machine_routes(): void
    {
        $this->actingAs($this->integrationUser, 'users')
            ->getJson(route('api.integration.v1.rooms.index'))
            ->assertUnauthorized();
    }

    public function test_token_must_have_the_route_ability(): void
    {
        $this->withToken($this->token(['pilos.meetings.read']))
            ->getJson(route('api.integration.v1.rooms.index'))
            ->assertForbidden();
    }

    public function test_expired_personal_access_token_is_rejected(): void
    {
        $expiredToken = $this->integrationUser
            ->createToken('saa9vi-expired-test', ['pilos.rooms.read'], now()->subMinute())
            ->plainTextToken;

        $this->withToken($expiredToken)
            ->getJson(route('api.integration.v1.rooms.index'))
            ->assertUnauthorized();
    }

    public function test_room_list_returns_only_minimal_binding_fields(): void
    {
        $room = Room::factory()->create(['name' => 'Saa9vi importer test room']);

        $this->withToken($this->token(['pilos.rooms.read']))
            ->getJson(route('api.integration.v1.rooms.index'))
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', (string) $room->id)
            ->assertJsonPath('data.0.name', 'Saa9vi importer test room')
            ->assertJsonPath('data.0.owner_id', $room->user_id)
            ->assertJsonMissingPath('data.0.access_code')
            ->assertJsonMissingPath('data.0.owner_email');
    }

    public function test_meeting_history_returns_only_completed_meetings(): void
    {
        $room = Room::factory()->create();
        $completed = Meeting::factory()->create([
            'room_id' => $room->id,
            'start' => now()->subHour(),
            'end' => now()->subMinutes(30),
        ]);
        Meeting::factory()->create([
            'room_id' => $room->id,
            'start' => now()->subMinutes(10),
            'end' => null,
        ]);

        $this->withToken($this->token(['pilos.meetings.read']))
            ->getJson(route('api.integration.v1.rooms.meetings', ['room' => $room->id]))
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $completed->id)
            ->assertJsonPath('data.0.room_id', (string) $room->id);
    }

    public function test_attendance_endpoint_returns_aggregate_seconds_without_personal_data(): void
    {
        $meeting = Meeting::factory()->create([
            'record_attendance' => true,
            'start' => now()->subHour(),
            'end' => now(),
        ]);

        $meeting->attendees()->create([
            'user_id' => $this->integrationUser->id,
            'session_id' => null,
            'join' => now()->subMinutes(15),
            'leave' => now()->subMinutes(5),
        ]);
        $meeting->attendees()->create([
            'user_id' => null,
            'session_id' => 'guest-session-test-1',
            'join' => now()->subMinutes(5),
            'leave' => now(),
        ]);

        $this->withToken($this->token(['pilos.attendance.read']))
            ->getJson(route('api.integration.v1.meetings.attendance', ['meeting' => $meeting->id]))
            ->assertSuccessful()
            ->assertJsonPath('data.meeting_id', (string) $meeting->id)
            ->assertJsonPath('data.participant_count', 2)
            ->assertJsonPath('data.attendee_seconds', 900)
            ->assertJsonMissingPath('data.name')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.sessions');
    }

public function test_account_without_integration_permission_is_rejected(): void
{
    $user = User::factory()->create();

    $token = $user->createToken(
        'unauthorized-test',
        ['pilos.rooms.read'],
        now()->addHour()
    )->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.integration.v1.rooms.index'))
        ->assertForbidden();
}

public function test_multiple_sessions_for_one_user_count_once_and_sum_duration(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $participant = User::factory()->create();

    $meeting->attendees()->create([
        'user_id' => $participant->id,
        'session_id' => null,
        'join' => now()->subMinutes(30),
        'leave' => now()->subMinutes(20),
    ]);

    $meeting->attendees()->create([
        'user_id' => $participant->id,
        'session_id' => null,
        'join' => now()->subMinutes(10),
        'leave' => now(),
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.participant_count', 1)
        ->assertJsonPath('data.attendee_seconds', 1200);
}

public function test_attendance_with_missing_leave_time_is_rejected(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $meeting->attendees()->create([
        'user_id' => $this->integrationUser->id,
        'session_id' => null,
        'join' => now()->subMinutes(10),
        'leave' => null,
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertStatus(409);
}

public function test_attendance_without_stable_identity_is_rejected(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $meeting->attendees()->create([
        'user_id' => null,
        'session_id' => null,
        'join' => now()->subMinutes(10),
        'leave' => now(),
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertStatus(409);
}

public function test_attendance_disabled_for_meeting_is_rejected(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => false,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertStatus(470);
}

public function test_attendance_for_meeting_that_has_not_ended_is_rejected(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => true,
        'start' => now()->subMinutes(10),
        'end' => null,
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertStatus(469);
}

public function test_statistics_for_meeting_that_has_not_ended_are_rejected(): void
{
    $meeting = Meeting::factory()->create([
        'start' => now()->subMinutes(10),
        'end' => null,
    ]);

    $this->withToken($this->token(['pilos.statistics.read']))
        ->getJson(route('api.integration.v1.meetings.statistics', [
            'meeting' => $meeting->id,
        ]))
        ->assertStatus(409);
}

public function test_statistics_returns_meeting_and_room_association(): void
{
    $room = Room::factory()->create();
    $meeting = Meeting::factory()->create([
        'room_id' => $room->id,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    MeetingStat::create([
        'meeting_id' => $meeting->id,
        'participant_count' => 5,
        'listener_count' => 3,
        'voice_participant_count' => 2,
        'video_count' => 1,
        'created_at' => now()->subMinutes(10),
    ]);

    $this->withToken($this->token(['pilos.statistics.read']))
        ->getJson(route('api.integration.v1.meetings.statistics', [
            'meeting' => $meeting->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.meeting_id', (string) $meeting->id)
        ->assertJsonPath('data.room_id', (string) $room->id)
        ->assertJsonPath('data.statistics.0.participant_count', 5)
        ->assertJsonPath('data.statistics.0.listener_count', 3)
        ->assertJsonPath('data.statistics.0.voice_participant_count', 2)
        ->assertJsonPath('data.statistics.0.video_count', 1);
}

public function test_rooms_pagination_accepts_max_page_size(): void
{
    Room::factory()->count(21)->create();

    $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['per_page' => 20]))
        ->assertSuccessful()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('per_page', 20)
        ->assertJsonPath('total', 21);
}

public function test_rooms_pagination_across_pages(): void
{
    $rooms = Room::factory()->count(21)->create();
    $roomIds = $rooms->pluck('id');

    $pageOne = $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['per_page' => 20]))
        ->assertSuccessful()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('per_page', 20)
        ->assertJsonPath('total', 21)
        ->assertJsonPath('last_page', 2);

    $pageTwo = $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['per_page' => 20, 'page' => 2]))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('per_page', 20)
        ->assertJsonPath('total', 21)
        ->assertJsonPath('last_page', 2);

    $pageOneIds = collect($pageOne->json('data'))->pluck('id');
    $pageTwoIds = collect($pageTwo->json('data'))->pluck('id');

    $this->assertEmpty($pageOneIds->intersect($pageTwoIds));
    $this->assertSame(count($roomIds), $pageOneIds->merge($pageTwoIds)->unique()->count());
}

public function test_rooms_pagination_single_record(): void
{
    Room::factory()->count(5)->create();

    $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['per_page' => 1]))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('total', 5)
        ->assertJsonPath('last_page', 5);
}

public function test_rooms_pagination_rejects_per_page_over_max(): void
{
    $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['per_page' => 21]))
        ->assertStatus(422);
}

public function test_rooms_pagination_rejects_zero_per_page(): void
{
    $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['per_page' => 0]))
        ->assertStatus(422);
}

public function test_rooms_pagination_rejects_zero_page(): void
{
    $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['page' => 0]))
        ->assertStatus(422);
}

public function test_rooms_pagination_rejects_non_integer_pagination(): void
{
    $this->withToken($this->token(['pilos.rooms.read']))
        ->getJson(route('api.integration.v1.rooms.index', ['page' => 'abc', 'per_page' => 'xyz']))
        ->assertStatus(422);
}

public function test_statistics_feature_disabled_returns_468(): void
{
    $meeting = Meeting::factory()->create([
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    app(RecordingSettings::class)->meeting_usage_enabled = false;
    app(RecordingSettings::class)->save();

    $this->withToken($this->token(['pilos.statistics.read']))
        ->getJson(route('api.integration.v1.meetings.statistics', [
            'meeting' => $meeting->id,
        ]))
        ->assertStatus(468);
}

public function test_meetings_pagination_accepts_max_page_size(): void
{
    $room = Room::factory()->create();
    Meeting::factory()->count(21)->create(['room_id' => $room->id]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', ['room' => $room->id, 'per_page' => 20]))
        ->assertSuccessful()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('per_page', 20)
        ->assertJsonPath('total', 21);
}

public function test_meetings_pagination_across_pages(): void
{
    $room = Room::factory()->create();
    $meetings = Meeting::factory()->count(21)->create(['room_id' => $room->id]);
    $meetingIds = $meetings->pluck('id');

    $pageOne = $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', ['room' => $room->id, 'per_page' => 20]))
        ->assertSuccessful()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('total', 21)
        ->assertJsonPath('last_page', 2);

    $pageTwo = $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', ['room' => $room->id, 'per_page' => 20, 'page' => 2]))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('total', 21)
        ->assertJsonPath('last_page', 2);

    $pageOneIds = collect($pageOne->json('data'))->pluck('id');
    $pageTwoIds = collect($pageTwo->json('data'))->pluck('id');

    $this->assertEmpty($pageOneIds->intersect($pageTwoIds));
    $this->assertSame(count($meetingIds), $pageOneIds->merge($pageTwoIds)->unique()->count());
}

public function test_meetings_pagination_sort_asc(): void
{
    $room = Room::factory()->create();
    $earlier = Meeting::factory()->create([
        'room_id' => $room->id,
        'start' => now()->subHour(),
        'end' => now()->subMinutes(30),
    ]);
    $later = Meeting::factory()->create([
        'room_id' => $room->id,
        'start' => now()->subMinutes(10),
        'end' => now(),
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
            'sort_direction' => 'asc',
            'per_page' => 20,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', (string) $earlier->id)
        ->assertJsonPath('data.1.id', (string) $later->id);
}

public function test_meetings_pagination_sort_desc(): void
{
    $room = Room::factory()->create();
    $earlier = Meeting::factory()->create([
        'room_id' => $room->id,
        'start' => now()->subHour(),
        'end' => now()->subMinutes(30),
    ]);
    $later = Meeting::factory()->create([
        'room_id' => $room->id,
        'start' => now()->subMinutes(10),
        'end' => now(),
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
            'sort_direction' => 'desc',
            'per_page' => 20,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', (string) $later->id)
        ->assertJsonPath('data.1.id', (string) $earlier->id);
}

public function test_meetings_pagination_rejects_invalid_sort_direction(): void
{
    $room = Room::factory()->create();

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
            'sort_direction' => 'invalid',
        ]))
        ->assertStatus(422);
}

public function test_meetings_pagination_rejects_per_page_over_max(): void
{
    $room = Room::factory()->create();

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
            'per_page' => 21,
        ]))
        ->assertStatus(422);
}

public function test_meetings_pagination_rejects_zero_page(): void
{
    $room = Room::factory()->create();

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
            'page' => 0,
        ]))
        ->assertStatus(422);
}

public function test_meetings_pagination_beyond_final_page_returns_empty(): void
{
    $room = Room::factory()->create();
    Meeting::factory()->count(3)->create(['room_id' => $room->id]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
            'per_page' => 1,
            'page' => 5,
        ]))
        ->assertSuccessful()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('total', 3);
}

public function test_meetings_for_unknown_room_returns_not_found(): void
{
    $unknownRoomId = '00000000-0000-4000-8000-000000000001';

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $unknownRoomId,
        ]))
        ->assertNotFound();
}

public function test_attendance_for_unknown_meeting_returns_not_found(): void
{
    $unknownMeetingId = '00000000-0000-4000-8000-000000000002';

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $unknownMeetingId,
        ]))
        ->assertNotFound();
}

public function test_statistics_for_unknown_meeting_returns_not_found(): void
{
    $unknownMeetingId = '00000000-0000-4000-8000-000000000003';

    $this->withToken($this->token(['pilos.statistics.read']))
        ->getJson(route('api.integration.v1.meetings.statistics', [
            'meeting' => $unknownMeetingId,
        ]))
        ->assertNotFound();
}

public function test_meetings_list_attendance_data_status_disabled(): void
{
    $room = Room::factory()->create();
    $meeting = Meeting::factory()->create([
        'room_id' => $room->id,
        'record_attendance' => false,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.attendance_data_status', 'DISABLED')
        ->assertJsonPath('data.0.attendance_available', false);
}

public function test_meetings_list_attendance_data_status_empty(): void
{
    $room = Room::factory()->create();
    $meeting = Meeting::factory()->create([
        'room_id' => $room->id,
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.attendance_data_status', 'EMPTY')
        ->assertJsonPath('data.0.attendance_available', false);
}

public function test_meetings_list_attendance_data_status_available(): void
{
    $room = Room::factory()->create();
    $meeting = Meeting::factory()->create([
        'room_id' => $room->id,
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $meeting->attendees()->create([
        'user_id' => $this->integrationUser->id,
        'session_id' => null,
        'join' => now()->subMinutes(30),
        'leave' => now()->subMinutes(10),
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.attendance_data_status', 'AVAILABLE')
        ->assertJsonPath('data.0.attendance_available', true);
}

public function test_meetings_list_attendance_data_status_incomplete_missing_leave(): void
{
    $room = Room::factory()->create();
    $meeting = Meeting::factory()->create([
        'room_id' => $room->id,
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $meeting->attendees()->create([
        'user_id' => $this->integrationUser->id,
        'session_id' => null,
        'join' => now()->subMinutes(30),
        'leave' => null,
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.attendance_data_status', 'INCOMPLETE')
        ->assertJsonPath('data.0.attendance_available', false);
}

public function test_meetings_list_attendance_data_status_incomplete_missing_identity(): void
{
    $room = Room::factory()->create();
    $meeting = Meeting::factory()->create([
        'room_id' => $room->id,
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $meeting->attendees()->create([
        'user_id' => null,
        'session_id' => null,
        'join' => now()->subMinutes(30),
        'leave' => now()->subMinutes(10),
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.attendance_data_status', 'INCOMPLETE')
        ->assertJsonPath('data.0.attendance_available', false);
}

public function test_meetings_list_attendance_data_status_incomplete_reversed_interval(): void
{
    $room = Room::factory()->create();
    $meeting = Meeting::factory()->create([
        'room_id' => $room->id,
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $meeting->attendees()->create([
        'user_id' => $this->integrationUser->id,
        'session_id' => null,
        'join' => now()->subMinutes(10),
        'leave' => now()->subMinutes(30),
    ]);

    $this->withToken($this->token(['pilos.meetings.read']))
        ->getJson(route('api.integration.v1.rooms.meetings', [
            'room' => $room->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.attendance_data_status', 'INCOMPLETE')
        ->assertJsonPath('data.0.attendance_available', false);
}

public function test_attendance_endpoint_no_rows_returns_empty(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.attendance_data_status', 'EMPTY')
        ->assertJsonPath('data.attendance_available', false)
        ->assertJsonPath('data.participant_count', 0)
        ->assertJsonPath('data.attendee_seconds', 0);
}

public function test_attendance_endpoint_reversed_interval_rejected(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $meeting->attendees()->create([
        'user_id' => $this->integrationUser->id,
        'session_id' => null,
        'join' => now()->subMinutes(10),
        'leave' => now()->subMinutes(30),
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertStatus(409);
}

public function test_attendance_endpoint_equal_join_leave_returns_zero_seconds(): void
{
    $meeting = Meeting::factory()->create([
        'record_attendance' => true,
        'start' => now()->subHour(),
        'end' => now(),
    ]);

    $now = now();
    $meeting->attendees()->create([
        'user_id' => $this->integrationUser->id,
        'session_id' => null,
        'join' => $now,
        'leave' => $now,
    ]);

    $this->withToken($this->token(['pilos.attendance.read']))
        ->getJson(route('api.integration.v1.meetings.attendance', [
            'meeting' => $meeting->id,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.attendance_data_status', 'AVAILABLE')
        ->assertJsonPath('data.attendance_available', true)
        ->assertJsonPath('data.participant_count', 1)
        ->assertJsonPath('data.attendee_seconds', 0);
}

}
