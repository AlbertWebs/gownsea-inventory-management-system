<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConvertLeadToCustomer
{
    public function handle(Lead $lead, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Customer
    {
        return DB::transaction(function () use ($lead, $actor, $ipAddress, $userAgent): Customer {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($lead->location_id)->exists()) {
                abort(403);
            }
            if ($lead->stage !== 'won') {
                throw ValidationException::withMessages(['lead' => 'Move the lead to Won before converting it to a customer.']);
            }
            if ($lead->customer_id) {
                return Customer::query()->findOrFail($lead->customer_id);
            }
            $existing = ($lead->phone || $lead->email) ? Customer::query()->where(function ($query) use ($lead): void {
                if ($lead->phone) {
                    $query->orWhere('phone', $lead->phone);
                }
                if ($lead->email) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($lead->email)]);
                }
            })->first() : null;
            if ($existing) {
                throw ValidationException::withMessages(['customer_id' => 'An existing customer may match this lead. Link the existing profile before conversion to avoid duplicates.']);
            }
            $customer = Customer::query()->create([
                'customer_number' => 'CUS-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'type' => $lead->institution_id ? 'institution' : 'individual',
                'name' => $lead->institution?->name ?? $lead->contact_name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'institution_id' => $lead->institution_id,
                'source' => $lead->source,
                'notes' => $lead->notes,
            ]);
            $customer->locations()->attach($lead->location_id, ['assigned_by' => $actor->id]);
            $lead->update(['customer_id' => $customer->id]);
            LeadActivity::query()->create(['lead_id' => $lead->id, 'actor_id' => $actor->id, 'activity_type' => 'lead.converted', 'details' => 'Converted to '.$customer->customer_number, 'before_state' => ['customer_id' => null], 'after_state' => ['customer_id' => $customer->id], 'happened_at' => now()]);
            AuditLog::query()->create(['actor_id' => $actor->id, 'location_id' => $lead->location_id, 'action' => 'lead.converted_to_customer', 'subject_type' => Lead::class, 'subject_id' => $lead->id, 'before_state' => ['customer_id' => null, 'stage' => 'won'], 'after_state' => ['customer_id' => $customer->id, 'customer_number' => $customer->customer_number], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now()]);

            return $customer;
        }, attempts: 3);
    }
}
