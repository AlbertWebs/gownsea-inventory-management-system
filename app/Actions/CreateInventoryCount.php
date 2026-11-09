<?php

namespace App\Actions;

use App\Models\InventoryCount;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateInventoryCount
{
    /** @param array{location_id:int,asset_codes:list<string>,idempotency_key:string,notes?:?string} $data */
    public function handle(array $data, User $actor): InventoryCount
    {
        $locationId = (int) $data['location_id'];
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($locationId)->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($data, $actor, $locationId): InventoryCount {
            $existing = InventoryCount::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->location_id !== $locationId || $existing->started_by !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key is already in use.']);
                }

                $submittedCodes = collect($data['asset_codes'])->map(fn (string $code): string => mb_strtoupper(trim($code)))->sort()->values();
                $recordedCodes = $existing->lines()->where('was_counted', true)->with('asset:id,asset_code')->get()
                    ->pluck('asset.asset_code')->map(fn (string $code): string => mb_strtoupper($code))->sort()->values();
                $recordedCodes = $recordedCodes->concat(collect($existing->unexpected_codes ?? []))->sort()->values();
                if ($submittedCodes->all() !== $recordedCodes->all()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different barcode list.']);
                }

                return $existing;
            }

            $location = Location::query()->whereKey($locationId)->where('is_active', true)->firstOrFail();
            $assets = PhysicalAsset::query()->where('current_location_id', $location->id)
                ->whereNull('current_transfer_id')->orderBy('id')->lockForUpdate()->get();
            $codes = collect($data['asset_codes'])->map(fn (string $code): string => mb_strtoupper(trim($code)));
            if ($codes->unique()->count() !== $codes->count()) {
                throw ValidationException::withMessages(['asset_codes' => 'Each barcode may only be scanned once.']);
            }

            $byCode = $assets->keyBy(fn (PhysicalAsset $asset): string => mb_strtoupper($asset->asset_code));
            $unexpectedCodes = $codes->reject(fn (string $code): bool => $byCode->has($code))->values();

            $count = InventoryCount::query()->create([
                'reference' => 'CNT-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'location_id' => $location->id, 'started_by' => $actor->id,
                'status' => 'awaiting_approval', 'expected_count' => $assets->count(), 'counted_count' => $codes->count() - $unexpectedCodes->count(),
                'notes' => $data['notes'] ?? null, 'unexpected_codes' => $unexpectedCodes->all(),
            ]);

            $counted = $codes->diff($unexpectedCodes)->flip();
            $count->lines()->createMany($assets->map(fn (PhysicalAsset $asset): array => [
                'physical_asset_id' => $asset->id, 'expected_status' => $asset->status,
                'was_counted' => $counted->has(mb_strtoupper($asset->asset_code)), 'counted_at' => $counted->has(mb_strtoupper($asset->asset_code)) ? now() : null,
            ])->all());

            return $count;
        }, attempts: 3);
    }
}
