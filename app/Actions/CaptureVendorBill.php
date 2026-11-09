<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\ChartAccount;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaptureVendorBill
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public function handle(array $data, User $actor): VendorBill
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'finance'], true), 403);

        return DB::transaction(function () use ($data, $actor): VendorBill {
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($data['location_id'])->exists()) {
                abort(403);
            }

            $movement = null;
            $assets = collect();
            $account = null;
            if (count($data['physical_asset_ids'] ?? []) > 0) {
                $assetIds = collect($data['physical_asset_ids'])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
                $assets = PhysicalAsset::query()->whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get();
                $assetCost = (int) $assets->sum('acquisition_cost_minor');
                if ($assets->count() !== $assetIds->count() || $assets->contains(fn (PhysicalAsset $asset): bool => $asset->acquisition_cost_minor < 1 || $asset->home_location_id !== (int) $data['location_id'] || $asset->status === 'written_off') || $assetCost !== (int) $data['amount_minor']) {
                    throw ValidationException::withMessages(['physical_asset_ids' => 'Select registered assets at this branch and enter their exact combined acquisition cost.']);
                }
                if (DB::table('vendor_bill_assets')->whereIn('physical_asset_id', $assetIds)->exists()) {
                    throw ValidationException::withMessages(['physical_asset_ids' => 'One or more selected assets already have a vendor bill.']);
                }
            } elseif (filled($data['stock_movement_id'] ?? null)) {
                $movement = StockMovement::query()->lockForUpdate()->whereKey($data['stock_movement_id'])->firstOrFail();
                if ($movement->movement_type !== 'bulk_received' || $movement->cost_minor < 1 || $movement->to_location_id !== (int) $data['location_id'] || $movement->cost_minor !== (int) $data['amount_minor']) {
                    throw ValidationException::withMessages(['stock_movement_id' => 'Select a received bulk-stock movement at this branch and enter its exact capitalized amount.']);
                }
                if (VendorBill::query()->where('stock_movement_id', $movement->id)->exists()) {
                    throw ValidationException::withMessages(['stock_movement_id' => 'This receipt already has a vendor bill.']);
                }
            } else {
                $account = ChartAccount::query()->where('code', $data['expense_account_code'])->where('account_type', 'expense')->where('is_active', true)->first();
                if ($account === null) {
                    throw ValidationException::withMessages(['expense_account_code' => 'Choose an active expense account.']);
                }
            }

            $bill = VendorBill::query()->create([
                'reference' => 'BILL-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'vendor_name' => trim($data['vendor_name']), 'invoice_number' => trim($data['invoice_number']),
                'location_id' => (int) $data['location_id'], 'stock_movement_id' => $movement?->id,
                'created_by' => $actor->id, 'expense_account_code' => $account?->code,
                'amount_minor' => (int) $data['amount_minor'], 'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'], 'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            ]);

            if ($account !== null) {
                $this->journal->handle(VendorBill::class, $bill->id, 'vendor_bill', $bill->reference, 'Vendor bill '.$bill->invoice_number.' · '.$bill->vendor_name, $bill->location_id, $actor->id, $bill->invoice_date->toDateString(), [
                    ['account' => $account->code, 'debit_minor' => $bill->amount_minor, 'credit_minor' => 0],
                    ['account' => '2100', 'debit_minor' => 0, 'credit_minor' => $bill->amount_minor],
                ]);
            } elseif ($assets->isNotEmpty()) {
                $bill->assets()->attach($assets->mapWithKeys(fn (PhysicalAsset $asset): array => [$asset->id => ['acquisition_cost_minor' => $asset->acquisition_cost_minor]])->all());
                $this->journal->handle(VendorBill::class, $bill->id, 'vendor_bill', $bill->reference, 'Vendor bill '.$bill->invoice_number.' · '.$bill->vendor_name, $bill->location_id, $actor->id, $bill->invoice_date->toDateString(), [
                    ['account' => '3000', 'debit_minor' => $bill->amount_minor, 'credit_minor' => 0],
                    ['account' => '2100', 'debit_minor' => 0, 'credit_minor' => $bill->amount_minor],
                ]);
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $bill->location_id, 'action' => 'finance.vendor_bill_captured',
                'subject_type' => VendorBill::class, 'subject_id' => $bill->id,
                'after_state' => ['reference' => $bill->reference, 'vendor' => $bill->vendor_name, 'invoice' => $bill->invoice_number, 'amount_minor' => $bill->amount_minor, 'stock_movement_id' => $movement?->id, 'physical_asset_ids' => $assets->pluck('id')->all()],
                'occurred_at' => now(),
            ]);

            return $bill;
        }, attempts: 3);
    }
}
