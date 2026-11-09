<?php

namespace App\Http\Controllers;

use App\Actions\CaptureVendorBill;
use App\Actions\PayVendorBill;
use App\Models\ChartAccount;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\VendorBill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VendorBillController
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $locationIds = $actor->mayViewAllLocations() ? null : $actor->locations()->pluck('locations.id');
        $bills = VendorBill::query()->with(['location:id,name', 'payments', 'assets:id'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->latest('invoice_date')->paginate(20)->withQueryString();
        $bills->getCollection()->transform(function (VendorBill $bill): array {
            $paid = (int) $bill->payments->sum('amount_minor');

            return ['id' => $bill->id, 'reference' => $bill->reference, 'vendor' => $bill->vendor_name, 'invoiceNumber' => $bill->invoice_number,
                'location' => $bill->location->name, 'amountMinor' => $bill->amount_minor, 'paidMinor' => $paid,
                'balanceMinor' => $bill->amount_minor - $paid, 'invoiceDate' => $bill->invoice_date->toDateString(),
                'dueDate' => $bill->due_date->toDateString(), 'isInventoryReceipt' => $bill->stock_movement_id !== null, 'isHireAsset' => $bill->assets->isNotEmpty()];
        });
        $locations = Location::query()->where('is_active', true)->when($locationIds !== null, fn ($query) => $query->whereIn('id', $locationIds))->orderBy('name')->get(['id', 'name']);
        $movements = StockMovement::query()->with('product:id,name,sku')->where('movement_type', 'bulk_received')->where('cost_minor', '>', 0)
            ->whereDoesntHave('vendorBill')->when($locationIds !== null, fn ($query) => $query->whereIn('to_location_id', $locationIds))
            ->latest('occurred_at')->limit(250)->get()->map(fn (StockMovement $movement): array => [
                'id' => $movement->id, 'locationId' => $movement->to_location_id, 'product' => $movement->product->name,
                'sku' => $movement->product->sku, 'quantity' => $movement->quantity, 'amountMinor' => $movement->cost_minor,
            ]);
        $assets = PhysicalAsset::query()->with('product:id,name,sku')->where('acquisition_cost_minor', '>', 0)->where('status', '!=', 'written_off')
            ->whereDoesntHave('vendorBills')->when($locationIds !== null, fn ($query) => $query->whereIn('home_location_id', $locationIds))
            ->orderBy('asset_code')->limit(500)->get()->map(fn (PhysicalAsset $asset): array => [
                'id' => $asset->id, 'locationId' => $asset->home_location_id, 'assetCode' => $asset->asset_code,
                'product' => $asset->product->name, 'sku' => $asset->product->sku, 'amountMinor' => $asset->acquisition_cost_minor,
            ]);

        return Inertia::render('Finance/VendorBills', [
            'bills' => $bills, 'locations' => $locations,
            'movements' => $movements, 'assets' => $assets,
            'expenseAccounts' => ChartAccount::query()->where('account_type', 'expense')->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'canManage' => in_array($actor->role, ['super_admin', 'management', 'finance'], true),
            'paymentMethods' => ['cash', 'bank_transfer', 'mpesa', 'other'],
        ]);
    }

    public function store(Request $request, CaptureVendorBill $capture): RedirectResponse
    {
        $data = $request->validate([
            'vendor_name' => ['required', 'string', 'max:160'], 'invoice_number' => ['required', 'string', 'max:120'],
            'location_id' => ['required', 'integer', 'exists:locations,id'], 'amount' => ['required', 'gt:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'invoice_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'stock_movement_id' => ['nullable', 'integer', 'exists:stock_movements,id'],
            'physical_asset_ids' => ['nullable', 'array', 'max:200'],
            'physical_asset_ids.*' => ['integer', 'distinct', 'exists:physical_assets,id'],
            'expense_account_code' => ['nullable', 'string', 'max:16', Rule::requiredIf(! $request->filled('stock_movement_id') && count((array) $request->input('physical_asset_ids', [])) === 0)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($request->filled('stock_movement_id') && count((array) $request->input('physical_asset_ids', [])) > 0) {
            return back()->withErrors(['stock_movement_id' => 'Link a single stock receipt or serialized asset to the bill.'])->withInput();
        }
        $duplicate = VendorBill::query()->whereRaw('lower(vendor_name) = ?', [mb_strtolower(trim($data['vendor_name']))])->whereRaw('lower(invoice_number) = ?', [mb_strtolower(trim($data['invoice_number']))])->exists();
        if ($duplicate) {
            return back()->withErrors(['invoice_number' => 'A bill with this vendor and invoice number has already been captured.'])->withInput();
        }
        $data['amount_minor'] = self::toMinor($data['amount']);
        $bill = $capture->handle($data, $request->user());

        return back()->with('success', 'Vendor bill '.$bill->reference.' captured.');
    }

    public function pay(Request $request, VendorBill $bill, PayVendorBill $pay): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'gt:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'mpesa', 'other'])],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'],
        ]);
        $data['amount_minor'] = self::toMinor($data['amount']);
        $pay->handle($bill, $data, $request->user());

        return back()->with('success', 'Vendor bill payment recorded and posted to the ledger.');
    }

    private static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
