<?php

namespace Database\Seeders;

use App\Models\BulkStockBalance;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class E2EBrowserSeeder extends Seeder
{
    public function run(): void
    {
        $location = Location::query()->where('code', 'CENTRAL-WH')->firstOrFail();
        $countLocation = Location::query()->updateOrCreate(
            ['code' => 'E2E-COUNT-BR'],
            ['name' => 'E2E Cycle Count Branch', 'type' => 'branch', 'address' => 'Browser test location', 'timezone' => 'Africa/Nairobi', 'is_active' => true],
        );
        $transferLocation = Location::query()->updateOrCreate(
            ['code' => 'E2E-TRANSFER-BR'],
            ['name' => 'E2E Transfer Branch', 'type' => 'branch', 'address' => 'Browser transfer destination', 'timezone' => 'Africa/Nairobi', 'is_active' => true],
        );
        $transferSource = Location::query()->updateOrCreate(
            ['code' => 'E2E-TRANSFER-SRC'],
            ['name' => 'E2E Transfer Source', 'type' => 'branch', 'address' => 'Browser transfer source', 'timezone' => 'Africa/Nairobi', 'is_active' => true],
        );

        foreach ([
            ['email' => 'browser.acceptance@gownsea.example', 'name' => 'Browser Acceptance Manager', 'role' => 'management'],
            ['email' => 'browser.storekeeper@gownsea.example', 'name' => 'Browser Acceptance Storekeeper', 'role' => 'storekeeper'],
            ['email' => 'browser.finance@gownsea.example', 'name' => 'Browser Acceptance Finance', 'role' => 'finance'],
            ['email' => 'browser.sales@gownsea.example', 'name' => 'Browser Acceptance Sales', 'role' => 'sales_crm'],
            ['email' => 'browser.production@gownsea.example', 'name' => 'Browser Acceptance Production', 'role' => 'production'],
            [
                'email' => 'browser.restricted-manager@gownsea.example',
                'name' => 'Browser Acceptance Restricted Manager',
                'role' => 'management',
                'permissions' => ['dashboard.view', 'inventory.view'],
            ],
        ] as $staff) {
            $user = User::query()->updateOrCreate(
                ['email' => $staff['email']],
                [
                    'name' => $staff['name'],
                    'password' => Hash::make('Gownsea-E2E-Only-Password-2026!'),
                    'role' => $staff['role'],
                    'permissions' => $staff['permissions'] ?? null,
                    'is_active' => true,
                ],
            );
            $user->locations()->syncWithoutDetaching([$location->id => ['is_primary' => true]]);
        }

        $storekeeper = User::query()->where('email', 'browser.storekeeper@gownsea.example')->firstOrFail();
        $storekeeper->locations()->syncWithoutDetaching([$countLocation->id => ['is_primary' => false]]);
        $customer = Customer::factory()->create([
            'name' => 'Browser Hire Customer',
            'phone' => '+254712345680',
            'email' => 'browser.hire-customer@gownsea.example',
        ]);
        $customer->locations()->attach($location->id, ['assigned_by' => $storekeeper->id]);

        $product = Product::factory()->create([
            'sku' => 'E2E-HIRE-GOWN',
            'name' => 'E2E Hire Gown',
            'hire_rate_minor' => 12500,
            'purchase_cost_minor' => 12500,
            'useful_life_months' => 36,
        ]);

        foreach (['E2E-GOWN-0001', 'E2E-GOWN-0002'] as $assetCode) {
            PhysicalAsset::factory()->create([
                'asset_code' => $assetCode,
                'product_id' => $product->id,
                'home_location_id' => $location->id,
                'current_location_id' => $location->id,
                'acquisition_cost_minor' => 12500,
                'acquired_on' => '2026-10-01',
            ]);
        }

        foreach (['E2E-TRANSFER-0001', 'E2E-TRANSFER-0002'] as $assetCode) {
            PhysicalAsset::factory()->create([
                'asset_code' => $assetCode,
                'product_id' => $product->id,
                'home_location_id' => $transferSource->id,
                'current_location_id' => $transferSource->id,
                'acquisition_cost_minor' => 12500,
                'acquired_on' => '2026-10-01',
            ]);
        }

        foreach (['E2E-COUNT-0001', 'E2E-COUNT-0002'] as $assetCode) {
            PhysicalAsset::factory()->create([
                'asset_code' => $assetCode,
                'product_id' => $product->id,
                'home_location_id' => $countLocation->id,
                'current_location_id' => $countLocation->id,
                'acquisition_cost_minor' => 12500,
                'acquired_on' => '2026-10-01',
            ]);
        }

        $bulkProduct = Product::factory()->create([
            'sku' => 'E2E-BULK-HIRE',
            'name' => 'E2E Bulk Hire Gown',
            'tracking_type' => 'bulk',
            'hire_rate_minor' => 50000,
            'purchase_cost_minor' => 12500,
        ]);

        $bulkSaleProduct = Product::factory()->create([
            'sku' => 'E2E-BULK-SALE',
            'name' => 'E2E Bulk Sale Gown',
            'tracking_type' => 'bulk',
            'hire_rate_minor' => 50000,
            'purchase_cost_minor' => 12500,
            'sale_price_minor' => 2500,
        ]);

        foreach ([$bulkProduct, $bulkSaleProduct] as $stockProduct) {
            BulkStockBalance::query()->create([
                'product_id' => $stockProduct->id,
                'location_id' => $location->id,
                'on_hand' => 12,
                'inventory_value_minor' => 150000,
                'valuation_complete' => true,
                'reserved' => 0,
                'pending_inspection' => 0,
                'damaged' => 0,
            ]);

            BulkStockBalance::query()->create([
                'product_id' => $stockProduct->id,
                'location_id' => $countLocation->id,
                'on_hand' => 4,
                'inventory_value_minor' => 50000,
                'valuation_complete' => true,
                'reserved' => 0,
                'pending_inspection' => 0,
                'damaged' => 0,
            ]);
        }
    }
}
