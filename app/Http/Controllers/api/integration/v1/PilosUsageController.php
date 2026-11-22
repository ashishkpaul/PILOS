<?php

declare(strict_types=1);

namespace App\Http\Controllers\api\integration\v1;

use App\Enums\CustomStatusCodes;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Room;
use App\Settings\GeneralSettings;
use App\Settings\RecordingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Narrow, read-only API used by Saa9vi to import PILOS room and meeting usage.
 * Attendee names, email addresses and individual sessions are never returned.
 */
class PilosUsageController extends Controller
{
    /**
     * List rooms using only the identifiers and labels needed for Vendure
     * room-to-channel binding. The normal PILOS room resource is intentionally
     * not used because it contains additional user-facing fields.
     */
    public function rooms(Request $request): JsonResponse
    {
        $perPage = $this->paginationSize($request);

        $paginator = Room::query()
            ->select(['id', 'name', 'user_id', 'updated_at'])
            ->orderBy('id')
            ->paginate($perPage)
            ->through(fn (Room $room): array => [
                'id' => (string) $room->id,
                'name' => $room->name,
                'owner_id' => $room->user_id,
                'updated_at' => $room->updated_at?->toISOString(),
            ]);

        return response()->json($paginator);
    }

    /**
     * List completed meeting history for one room. Running and not-yet-ended
     * meetings are excluded so they cannot be mistaken for final usage facts.
     */
    public function meetings(Request $request, Room $room): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $perPage = $this->paginationSize($request);
        $direction = $validated['sort_direction'] ?? 'desc';
        $statisticsEnabled = app(RecordingSettings::class)->meeting_usage_enabled;

        $paginator = $room->meetings()
            ->whereNotNull('start')
            ->whereNotNull('end')
            ->withCount(['attendees', 'stats'])
            ->orderBy('start', $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage)
            ->through(fn (Meeting $meeting): array => [
                'id' => (string) $meeting->id,
                'room_id' => (string) $meeting->room_id,
                'start' => $meeting->start?->toISOString(),
                'end' => $meeting->end?->toISOString(),
                'updated_at' => $meeting->updated_at?->toISOString(),
                'attendance_recording_enabled' => (bool) $meeting->record_attendance,
                'attendance_available' => (bool) $meeting->record_attendance && $meeting->attendees_count > 0,
                'statistics_available' => (bool) $statisticsEnabled && $meeting->stats_count > 0,
            ]);

        return response()->json($paginator);
    }

    /**
     * Return an aggregate of recorded attendee time. Personal identifiers and
     * per-attendee session details remain inside PILOS.
     */
    public function attendance(Meeting $meeting): JsonResponse
    {
        if (! $meeting->record_attendance) {
            abort(CustomStatusCodes::MEETING_ATTENDANCE_DISABLED->value, 'Attendance recording is disabled for this meeting.');
        }

        if ($meeting->end === null) {
            abort(CustomStatusCodes::MEETING_ATTENDANCE_NOT_ENDED->value, 'Attendance is available only after the meeting has ended.');
        }

        $attendees = $meeting->attendees()->get(['user_id', 'session_id', 'join', 'leave']);

        if ($attendees->contains(fn (MeetingAttendee $attendee): bool => $attendee->leave === null)) {
            abort(409, 'Attendance records are incomplete because one or more attendee sessions have no leave time.');
        }

$participantKeys = $attendees->map(function (MeetingAttendee $attendee): ?string {
    if ($attendee->user_id !== null) {
        return 'user:'.$attendee->user_id;
    }

    if ($attendee->session_id !== null) {
        return 'guest:'.$attendee->session_id;
    }

    return null;
});

if ($participantKeys->contains(fn (?string $key): bool => $key === null)) {
    abort(409, 'Attendance records contain an attendee without a stable PILOS identity key.');
}

$participantKeys = $participantKeys->unique();

        $attendeeSeconds = (int) round($attendees->sum(
            fn (MeetingAttendee $attendee): float => max(0, $attendee->join->diffInSeconds($attendee->leave))
        ));

        return response()->json([
            'data' => [
                'meeting_id' => (string) $meeting->id,
                'room_id' => (string) $meeting->room_id,
                'start' => $meeting->start?->toISOString(),
                'end' => $meeting->end?->toISOString(),
                'attendance_available' => $attendees->isNotEmpty(),
                'participant_count' => $participantKeys->count(),
                'attendee_seconds' => $attendeeSeconds,
            ],
        ]);
    }

    /** Return meeting utilisation time-series without participant identity. */
    public function statistics(Meeting $meeting): JsonResponse
    {
        if ($meeting->end === null) {
            abort(409, 'Meeting statistics are final only after the meeting has ended.');
        }

        if (! app(RecordingSettings::class)->meeting_usage_enabled) {
            abort(CustomStatusCodes::FEATURE_DISABLED->value, 'Meeting usage statistics are disabled.');
        }

        $statistics = $meeting->stats()
            ->orderBy('created_at')
            ->get([
                'id',
                'participant_count',
                'listener_count',
                'voice_participant_count',
                'video_count',
                'created_at',
            ]);

        return response()->json([
            'data' => [
                'meeting_id' => (string) $meeting->id,
                'room_id' => (string) $meeting->room_id,
                'statistics' => $statistics->map(fn ($statistic): array => [
                    'id' => $statistic->id,
                    'participant_count' => $statistic->participant_count,
                    'listener_count' => $statistic->listener_count,
                    'voice_participant_count' => $statistic->voice_participant_count,
                    'video_count' => $statistic->video_count,
                    'created_at' => $statistic->created_at?->toISOString(),
                ])->values(),
            ],
        ]);
    }

    private function paginationSize(Request $request): int
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $configured = (int) app(GeneralSettings::class)->pagination_page_size;
        $default = min(20, max(1, $configured));

        return (int) ($validated['per_page'] ?? $default);
    }
}
