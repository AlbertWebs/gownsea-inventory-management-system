<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateCustomerSmsConsent
{
    public function handle(Customer $customer, bool $consent, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Customer
    {
        return DB::transaction(function () use ($customer, $consent, $actor, $ipAddress, $userAgent): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $locationIds = $actor->mayViewAllLocations() ? null : $actor->locations()->pluck('locations.id');
            $locationId = $customer->locations()->when($locationIds !== null, fn ($query) => $query->whereIn('locations.id', $locationIds))
                ->orderBy('locations.id')->value('locations.id')
                ?? $customer->hires()->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                    ->orderBy('location_id')->value('location_id');
            if ($locationIds !== null && $locationId === null) {
                abort(403);
            }
            $before = ['sms_consent' => ! $customer->sms_opt_out && $customer->sms_consent_at !== null];
            $customer->forceFill([
                'sms_opt_out' => ! $consent,
                'sms_consent_at' => $consent ? now() : null,
            ])->save();
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $locationId,
                'action' => $consent ? 'customer.sms_consent_recorded' : 'customer.sms_consent_withdrawn',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'before_state' => $before,
                'after_state' => ['sms_consent' => $consent, 'recorded_at' => $consent ? $customer->sms_consent_at?->toIso8601String() : null],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $customer;
        });
    }
}
