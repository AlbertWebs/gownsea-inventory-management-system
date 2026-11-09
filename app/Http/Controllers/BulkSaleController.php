<?php

namespace App\Http\Controllers;

use App\Actions\ReturnBulkSale;
use App\Actions\SellBulkStock;
use App\Http\Requests\StoreBulkSaleRequest;
use App\Http\Requests\StoreBulkSaleReturnRequest;
use App\Models\BulkSale;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BulkSaleController extends Controller
{
    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'management', 'storekeeper', 'sales_crm', 'finance'], true), 403);
        $canSell = in_array($user->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true);
        $canRefundSales = in_array($user->role, ['super_admin', 'management', 'finance'], true);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $locations = Location::query()->where('is_active', true)->when($locationIds !== null, fn ($query) => $query->whereIn('id', $locationIds))
            ->orderBy('name')->get(['id', 'name']);
        $customers = Customer::query()->whereHas('locations', fn ($query) => $query->whereIn('locations.id', $locations->pluck('id')))
            ->with('locations:id')->orderBy('name')->limit(500)->get(['id', 'name', 'customer_number'])
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id, 'name' => $customer->name, 'number' => $customer->customer_number,
                'locationIds' => $customer->locations->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ]);
        $products = Product::query()->where('tracking_type', 'bulk')->where('is_active', true)->with(['bulkBalances' => fn ($query) => $query
            ->whereIn('location_id', $locations->pluck('id'))->with('location:id,name')])->orderBy('name')->get(['id', 'sku', 'name', 'sale_price_minor'])
            ->map(fn (Product $product): array => [
                'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
                'salePriceMinor' => (int) $product->sale_price_minor,
                'balances' => $product->bulkBalances->map(fn ($balance): array => [
                    'locationId' => $balance->location_id, 'available' => $balance->available,
                ])->keyBy('locationId'),
            ]);
        $defaultLocationId = $user->locations()->wherePivot('is_primary', true)->value('locations.id') ?: $locations->first()?->id;
        $recentSales = BulkSale::query()->with(['customer:id,name', 'location:id,name', 'lines:id,bulk_sale_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor,line_total_minor', 'returns:id,bulk_sale_id', 'returns.lines:id,bulk_sale_return_id,product_id,quantity', 'payment:id,bulk_sale_id,reference,method,amount_minor'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->latest('sold_at')->limit(25)->get()->map(fn (BulkSale $sale): array => [
                'id' => $sale->id, 'reference' => $sale->reference, 'customer' => $sale->customer->name,
                'location' => $sale->location->name, 'totalMinor' => $sale->total_minor,
                'paymentReference' => $sale->payment?->reference, 'soldAt' => $sale->sold_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                'lines' => $sale->lines->map(fn ($line): array => [
                    'productId' => $line->product_id, 'sku' => $line->sku_snapshot, 'product' => $line->product_name_snapshot,
                    'quantity' => $line->quantity, 'unitPriceMinor' => $line->unit_price_minor,
                    'returnedQuantity' => (int) $sale->returns->flatMap->lines->where('product_id', $line->product_id)->sum('quantity'),
                ])->all(),
            ]);

        return Inertia::render('BulkSales/Create', [
            'locations' => $locations, 'customers' => $customers, 'products' => $products,
            'defaultLocationId' => $defaultLocationId,
            'recentSales' => $recentSales,
            'canSell' => $canSell, 'canRefundSales' => $canRefundSales,
        ]);
    }

    public function store(StoreBulkSaleRequest $request, SellBulkStock $sell): RedirectResponse
    {
        $sale = $sell->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Bulk sale {$sale->reference} completed and payment recorded.");
    }

    public function return(StoreBulkSaleReturnRequest $request, ReturnBulkSale $return): RedirectResponse
    {
        $record = $return->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Return {$record->reference} recorded and stock held for inspection.");
    }
}
