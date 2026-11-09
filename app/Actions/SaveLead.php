<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Support\KenyanPhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveLead
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Lead
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): Lead {
            $this->authorizeLocation((int) $data['location_id'], $actor);
            $data = $this->normalize($data);
            $data['estimated_value_minor'] = $this->toMinorUnits((string) $data['estimated_value']);
            unset($data['estimated_value']);
            $lead = Lead::query()->create([...$data, 'reference' => 'LEAD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'owner_id' => $actor->id]);
            $snapshot = $this->snapshot($lead);
            $this->recordActivity($lead, $actor, 'lead.created', null, $snapshot);
            $this->audit($lead, $actor, null, $snapshot, $ipAddress, $userAgent);

            return $lead;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function update(Lead $lead, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Lead
    {
        return DB::transaction(function () use ($lead, $data, $actor, $ipAddress, $userAgent): Lead {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $this->authorizeLocation((int) $data['location_id'], $actor);
            $before = $this->snapshot($lead);
            $data = $this->normalize($data);
            $data['estimated_value_minor'] = $this->toMinorUnits((string) $data['estimated_value']);
            unset($data['estimated_value']);
            $lead->update($data);
            $after = $this->snapshot($lead);
            $this->recordActivity($lead, $actor, 'lead.updated', $before, $after);
            $this->audit($lead, $actor, $before, $after, $ipAddress, $userAgent);

            return $lead->refresh();
        }, attempts: 3);
    }

    private function authorizeLocation(int $locationId, User $actor): void
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'You are not assigned to the selected location.']);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(array $data): array
    {
        $data['phone'] = isset($data['phone']) && trim((string) $data['phone']) !== '' ? $this->normalizePhone((string) $data['phone']) : null;
        $data['follow_up_at'] = $data['follow_up_at'] ?: null;
        $data['lost_reason'] = $data['stage'] === 'lost' ? ($data['lost_reason'] ?? null) : null;

        return $data;
    }

    private function normalizePhone(string $phone): string
    {
        try {
            return KenyanPhoneNumber::normalize($phone);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** @return array<string, mixed> */
    private function snapshot(Lead $lead): array
    {
        return $lead->only(['reference', 'location_id', 'customer_id', 'institution_id', 'stage', 'estimated_value_minor', 'probability', 'next_action', 'follow_up_at', 'lost_reason']);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function recordActivity(Lead $lead, User $actor, string $type, ?array $before, array $after): void
    {
        LeadActivity::query()->create(['lead_id' => $lead->id, 'actor_id' => $actor->id, 'activity_type' => $type, 'before_state' => $before, 'after_state' => $after, 'happened_at' => now()]);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(Lead $lead, User $actor, ?array $before, array $after, ?string $ipAddress, ?string $userAgent): void
    {
        AuditLog::query()->create(['actor_id' => $actor->id, 'location_id' => $lead->location_id, 'action' => $before === null ? 'lead.created' : 'lead.updated', 'subject_type' => Lead::class, 'subject_id' => $lead->id, 'before_state' => $before, 'after_state' => $after, 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now()]);
    }
}
