<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\AuditLog;
use App\Models\Location;
use App\Support\KenyanPhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    public function store(StoreLocationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        if (! empty($data['phone'])) {
            $data['phone'] = KenyanPhoneNumber::normalize($data['phone']);
        }

        $location = DB::transaction(function () use ($data, $request): Location {
            $location = Location::query()->create($data + ['is_active' => true]);
            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'location_id' => $location->id,
                'action' => 'location.created',
                'subject_type' => Location::class,
                'subject_id' => $location->id,
                'after_state' => $location->only(['code', 'name', 'type', 'address', 'phone', 'timezone', 'is_active']),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'occurred_at' => now(),
            ]);

            return $location;
        });

        return back()->with('success', "Location {$location->name} was created.");
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        $data = $request->validated();
        if (! empty($data['phone'])) {
            $data['phone'] = KenyanPhoneNumber::normalize($data['phone']);
        }

        DB::transaction(function () use ($data, $request, $location): void {
            $locations = Location::query()->orderBy('id')->lockForUpdate()->get();
            $locked = $locations->firstWhere('id', $location->id);
            abort_if($locked === null, 404);

            if (! $data['is_active']) {
                $activeWarehouses = $locations->where('type', 'warehouse')->where('is_active', true)->count();
                if ($locked->type === 'warehouse' && $activeWarehouses <= 1) {
                    throw ValidationException::withMessages(['is_active' => 'At least one active warehouse must remain configured.']);
                }

                $hasAssets = $locked->assets()->exists();
                $hasOpenTransfers = $locked->outgoingTransfers()->whereIn('status', ['draft', 'approved', 'in_transit', 'partially_received'])->exists()
                    || $locked->incomingTransfers()->whereIn('status', ['draft', 'approved', 'in_transit', 'partially_received'])->exists();
                $hasOpenHires = $locked->hires()->whereIn('status', ['reserved', 'issued', 'partially_returned', 'returned_pending_inspection'])->exists();

                if ($hasAssets || $hasOpenTransfers || $hasOpenHires) {
                    throw ValidationException::withMessages(['is_active' => 'Move current stock and resolve open transfers and hires before deactivating this location.']);
                }
            }

            $before = $locked->only(['name', 'address', 'phone', 'timezone', 'is_active']);
            $locked->update($data);
            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'location_id' => $locked->id,
                'action' => 'location.updated',
                'subject_type' => Location::class,
                'subject_id' => $locked->id,
                'before_state' => $before,
                'after_state' => $locked->fresh()->only(['name', 'address', 'phone', 'timezone', 'is_active']),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'occurred_at' => now(),
            ]);
        }, attempts: 3);

        return back()->with('success', "Location {$location->fresh()->name} was updated.");
    }
}
