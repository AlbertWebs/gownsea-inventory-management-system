<?php

namespace App\Http\Controllers;

use App\Actions\BulkInventoryAccounting;
use App\Actions\PostFinancialJournal;
use App\Models\AcademicClassification;
use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\BulkStockBalance;
use App\Models\Institution;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductController extends Controller
{
    public function image(Request $request, Product $product): StreamedResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'management', 'storekeeper', 'finance', 'sales_crm', 'production'], true), 403);
        abort_unless($product->image_path !== null && Storage::disk('local')->exists($product->image_path), 404);

        return Storage::disk('local')->response($product->image_path, basename($product->image_path), [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'management', 'storekeeper'], true), 403);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();

        return Inertia::render('Inventory/Create', [
            'locations' => Location::query()->where('is_active', true)->when($locationIds !== null, fn ($q) => $q->whereIn('id', $locationIds))->orderBy('name')->get(['id', 'name']),
            'classifications' => AcademicClassification::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'label', 'code']),
            'institutions' => Institution::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'defaultLocationId' => $user->locations()->wherePivot('is_primary', true)->value('locations.id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'management', 'storekeeper'], true), 403);
        $request->mergeIfMissing(['tracking_type' => 'serialized']);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['gown', 'cap', 'hood', 'stole', 'tassel', 'accessory', 'other'])],
            'tracking_type' => ['required', Rule::in(['serialized', 'bulk'])],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'classification_id' => ['nullable', 'integer', Rule::exists('academic_classifications', 'id')->where('is_active', true)],
            'qualification_level' => ['nullable', 'string', 'max:32', Rule::in(['certificate', 'diploma', 'bachelors', 'masters', 'phd'])],
            'institution_id' => ['nullable', 'integer', Rule::exists('institutions', 'id')->where('is_active', true)],
            'faculty' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:max_width=6000,max_height=6000', 'max:5120'],
            'size' => ['nullable', 'string', 'max:24'], 'color' => ['nullable', 'string', 'max:48'],
            'hire_rate' => ['required', 'string', 'regex:/^(?:\d{1,7}(?:\.\d{1,2})?|10000000(?:\.00)?)$/'],
            'purchase_cost' => [Rule::requiredIf($request->input('tracking_type') === 'bulk' && (int) $request->input('initial_quantity', 0) > 0), 'nullable', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'sale_price' => ['nullable', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'initial_quantity' => ['required_if:tracking_type,bulk', 'integer', 'min:0', 'max:1000000'],
            'asset_codes' => ['required_if:tracking_type,serialized', 'array', 'min:1', 'max:200'],
            'asset_codes.*' => ['required', 'string', 'max:64', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', 'unique:physical_assets,asset_code'],
        ]);
        if ($data['tracking_type'] === 'bulk' && (int) ($data['initial_quantity'] ?? 0) > 0) {
            abort_unless(in_array($user->role, ['super_admin', 'management'], true), 403);
        }
        $location = Location::query()->findOrFail($data['location_id']);
        if (! $user->mayViewAllLocations() && ! $user->locations()->whereKey($location->id)->exists()) {
            abort(403);
        }

        $imagePath = $request->file('image')?->store('products', 'local');
        try {
            DB::transaction(function () use ($data, $location, $user, $imagePath): void {
                $product = Product::query()->create([
                    'sku' => $data['sku'], 'name' => $data['name'], 'category' => $data['category'], 'tracking_type' => $data['tracking_type'],
                    'academic_classification_id' => $data['classification_id'] ?? null, 'qualification_level' => $data['qualification_level'] ?? null,
                    'institution_id' => $data['institution_id'] ?? null, 'faculty' => $data['faculty'] ?? null, 'description' => $data['description'] ?? null,
                    'size' => $data['size'] ?? null, 'color' => $data['color'] ?? null,
                    'image_path' => $imagePath,
                    'purchase_cost_minor' => self::toMinor((string) ($data['purchase_cost'] ?? '0')),
                    'sale_price_minor' => self::toMinor((string) ($data['sale_price'] ?? '0')),
                    'hire_rate_minor' => self::toMinor((string) $data['hire_rate']),
                    'reorder_threshold' => (int) ($data['reorder_threshold'] ?? 0),
                    'useful_life_months' => (int) ($data['useful_life_months'] ?? 36),
                ]);
                if ($product->tracking_type === 'bulk') {
                    $quantity = (int) $data['initial_quantity'];
                    BulkStockBalance::query()->create([
                        'product_id' => $product->id, 'location_id' => $location->id, 'on_hand' => $quantity,
                        'inventory_value_minor' => 0, 'valuation_complete' => true,
                    ]);
                    if ($quantity > 0) {
                        $costMinor = $product->purchase_cost_minor * $quantity;
                        $balance = BulkStockBalance::query()->where('product_id', $product->id)->where('location_id', $location->id)->lockForUpdate()->firstOrFail();
                        $balance->update(['inventory_value_minor' => $costMinor, 'valuation_complete' => true]);
                        $movement = StockMovement::query()->create([
                            'product_id' => $product->id, 'to_location_id' => $location->id, 'actor_id' => $user->id,
                            'movement_type' => 'bulk_opening_stock', 'quantity' => $quantity, 'cost_minor' => $costMinor,
                            'idempotency_key' => 'bulk-opening:'.$product->id,
                            'notes' => 'Opening bulk stock registration', 'occurred_at' => now(),
                        ]);
                        app(BulkInventoryAccounting::class)->postMovement($movement, $costMinor, $user);
                    }
                    AuditLog::query()->create([
                        'actor_id' => $user->id, 'location_id' => $location->id, 'action' => 'inventory.bulk_product_registered',
                        'subject_type' => Product::class, 'subject_id' => $product->id,
                        'after_state' => ['sku' => $product->sku, 'on_hand' => $quantity, 'location_id' => $location->id],
                        'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'occurred_at' => now(),
                    ]);

                    return;
                }
                foreach ($data['asset_codes'] as $code) {
                    $asset = PhysicalAsset::query()->create([
                        'asset_code' => trim($code), 'product_id' => $product->id, 'home_location_id' => $location->id,
                        'current_location_id' => $location->id, 'custody_type' => 'location', 'status' => 'available', 'condition' => 'good', 'last_verified_at' => now(),
                        'acquisition_cost_minor' => $product->purchase_cost_minor, 'acquired_on' => now()->toDateString(), 'accumulated_depreciation_minor' => 0,
                    ]);
                    if ($asset->acquisition_cost_minor > 0) {
                        app(PostFinancialJournal::class)->handle(
                            PhysicalAsset::class, $asset->id, 'asset_capitalization', 'ASSET-'.$asset->asset_code,
                            'Serialized hire asset opening value · '.$asset->asset_code, $location->id, $user->id, now()->toDateString(), [
                                ['account' => '1500', 'debit_minor' => $asset->acquisition_cost_minor, 'credit_minor' => 0],
                                ['account' => '3000', 'debit_minor' => 0, 'credit_minor' => $asset->acquisition_cost_minor],
                            ],
                        );
                    }
                    AssetCustodyEvent::query()->create([
                        'physical_asset_id' => $asset->id, 'actor_id' => $user->id, 'to_location_id' => $location->id,
                        'event_type' => 'stock_received', 'to_status' => 'available', 'to_custody_type' => 'location',
                        'idempotency_key' => 'stock-in:'.$asset->id, 'notes' => 'Opening stock registration', 'occurred_at' => now(),
                    ]);
                    StockMovement::query()->create([
                        'product_id' => $product->id, 'physical_asset_id' => $asset->id, 'to_location_id' => $location->id,
                        'actor_id' => $user->id, 'movement_type' => 'stock_received', 'to_status' => 'available',
                        'idempotency_key' => 'stock-in:'.$asset->id, 'notes' => 'Opening stock registration', 'occurred_at' => now(),
                    ]);
                }
                AuditLog::query()->create([
                    'actor_id' => $user->id, 'location_id' => $location->id, 'action' => 'inventory.product_registered',
                    'subject_type' => Product::class, 'subject_id' => $product->id,
                    'after_state' => ['sku' => $product->sku, 'registered_assets' => count($data['asset_codes']), 'location_id' => $location->id],
                    'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'occurred_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                Storage::disk('local')->delete($imagePath);
            }

            throw $exception;
        }

        $message = $data['tracking_type'] === 'bulk'
            ? 'Bulk stock product registered at '.$location->name.'.'
            : count($data['asset_codes']).' serialized assets registered at '.$location->name.'.';

        return redirect()->route('inventory.index')->with('success', $message);
    }

    private static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
