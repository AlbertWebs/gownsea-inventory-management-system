<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Hire;
use App\Models\PhysicalAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        if (mb_strlen($term) < 2 || mb_strlen($term) > 80) {
            return response()->json(['results' => []]);
        }

        $user = $request->user();
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();
        $wildcard = '%'.$term.'%';
        $assets = PhysicalAsset::query()
            ->with(['product:id,name,sku,size,color', 'currentLocation:id,name', 'customerCustodian:id,name'])
            ->when($locationIds !== null, fn ($query) => $query->where(function ($assets) use ($locationIds): void {
                $assets->whereIn('current_location_id', $locationIds)
                    ->orWhereHas('hireItems', fn ($items) => $items->whereIn('status', ['issued', 'partially_returned'])->whereHas('hire', fn ($hire) => $hire->whereIn('location_id', $locationIds)))
                    ->orWhereHas('transfer', fn ($transfer) => $transfer->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds));
            }))
            ->where(fn ($query) => $query->where('asset_code', 'like', $wildcard)
                ->orWhereHas('product', fn ($product) => $product->where('sku', 'like', $wildcard)->orWhere('name', 'like', $wildcard)))
            ->limit(8)->get()
            ->map(fn (PhysicalAsset $asset): array => [
                'type' => 'asset', 'id' => $asset->id, 'label' => $asset->asset_code,
                'detail' => $asset->product->name.' · '.$asset->status,
                'location' => $asset->currentLocation?->name ?? $asset->customerCustodian?->name ?? 'In transit / unverified',
                'url' => route('inventory.index', ['q' => $asset->asset_code]),
            ]);

        $customers = Customer::query()->when($locationIds !== null, fn ($query) => $query->where(fn ($customers) => $customers
            ->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds))
            ->orWhereHas('hires', fn ($hires) => $hires->whereIn('location_id', $locationIds))))
            ->where(fn ($query) => $query
                ->where('name', 'like', $wildcard)->orWhere('phone', 'like', $wildcard)->orWhere('customer_number', 'like', $wildcard))
            ->limit(5)->get(['id', 'name', 'customer_number', 'phone'])
            ->map(fn (Customer $customer): array => [
                'type' => 'customer', 'id' => $customer->id, 'label' => $customer->name,
                'detail' => $customer->customer_number.' · '.($customer->phone ?? 'No phone'),
                'location' => '', 'url' => route('customers.index', ['q' => $customer->name]),
            ]);

        $hires = Hire::query()->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->where('reference', 'like', $wildcard)->limit(5)->get(['id', 'reference', 'customer_id', 'location_id']);

        return response()->json(['results' => $assets->concat($customers)->concat($hires->map(fn (Hire $hire): array => [
            'type' => 'hire', 'id' => $hire->id, 'label' => $hire->reference,
            'detail' => 'Hiring transaction', 'location' => '', 'url' => route('hiring.index', ['q' => $hire->reference]),
        ]))->values()]);
    }
}
