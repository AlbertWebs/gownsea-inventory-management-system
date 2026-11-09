<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\GraduationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveGraduationEvent
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): GraduationEvent
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): GraduationEvent {
            $this->authorizeLocation((int) $data['location_id'], $actor);
            $event = GraduationEvent::query()->create([...$data, 'owner_id' => $actor->id]);
            $this->audit($event, $actor, null, $data, $ipAddress, $userAgent);

            return $event;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function update(GraduationEvent $event, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): GraduationEvent
    {
        return DB::transaction(function () use ($event, $data, $actor, $ipAddress, $userAgent): GraduationEvent {
            $event = GraduationEvent::query()->lockForUpdate()->findOrFail($event->id);
            $this->authorizeLocation((int) $data['location_id'], $actor);
            $before = $event->only(array_keys($data));
            $event->update($data);
            $this->audit($event, $actor, $before, $data, $ipAddress, $userAgent);

            return $event->refresh();
        }, attempts: 3);
    }

    private function authorizeLocation(int $locationId, User $actor): void
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'You are not assigned to the selected location.']);
        }
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(GraduationEvent $event, User $actor, ?array $before, array $after, ?string $ipAddress, ?string $userAgent): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id, 'location_id' => $event->location_id,
            'action' => $before === null ? 'graduation_event.created' : 'graduation_event.updated',
            'subject_type' => GraduationEvent::class, 'subject_id' => $event->id,
            'before_state' => $before, 'after_state' => $after, 'ip_address' => $ipAddress,
            'user_agent' => $userAgent, 'occurred_at' => now(),
        ]);
    }
}
