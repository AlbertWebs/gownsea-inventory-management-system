<?php

namespace Database\Seeders;

use App\Models\AcademicClassification;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class GownseaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $isLocalOrTesting = app()->environment(['local', 'testing']);
        $isExplicitProductionRun = $this->command?->option('force') === true;
        if (! $isLocalOrTesting && ! $isExplicitProductionRun) {
            throw new LogicException('Outside local/testing, run this demo seeder only with the explicit --force option.');
        }

        DB::transaction(function () use ($isLocalOrTesting): void {
            $now = now();
            $classificationIds = [];
            foreach ([
                ['code' => 'CERTIFICATE', 'label' => 'Certificate', 'sort_order' => 2],
                ['code' => 'DIPLOMA', 'label' => 'Diploma', 'sort_order' => 3],
                ['code' => 'BACHELORS', 'label' => "Bachelor's Degree", 'sort_order' => 4],
                ['code' => 'MASTERS', 'label' => "Master's Degree", 'sort_order' => 5],
                ['code' => 'PHD', 'label' => 'PhD / Doctoral', 'sort_order' => 6],
            ] as $classification) {
                $record = AcademicClassification::query()->updateOrCreate(['code' => $classification['code']], [...$classification, 'is_active' => true]);
                $classificationIds[$record->code] = $record->id;
            }

            $roles = [
                ['name' => 'Demo Local Administrator', 'email' => 'demo.admin@gownsea.test', 'role' => 'super_admin'],
                ['name' => 'Demo Storekeeper', 'email' => 'demo.storekeeper@gownsea.test', 'role' => 'storekeeper'],
                ['name' => 'Demo Sales Officer', 'email' => 'demo.sales@gownsea.test', 'role' => 'sales_crm'],
                ['name' => 'Demo Finance Officer', 'email' => 'demo.finance@gownsea.test', 'role' => 'finance'],
                ['name' => 'Demo Production Officer', 'email' => 'demo.production@gownsea.test', 'role' => 'production'],
            ];
            $users = [];
            if ($isLocalOrTesting) {
                foreach ($roles as $staff) {
                    $users[$staff['role']] = User::query()->updateOrCreate(
                        ['email' => $staff['email']],
                        ['name' => $staff['name'], 'password' => Hash::make('GownseaDemo2026!'), 'role' => $staff['role'], 'is_active' => true, 'email_verified_at' => $now],
                    );
                }
            } else {
                $operator = User::query()->where('is_active', true)
                    ->orderByRaw("CASE WHEN role = 'super_admin' THEN 0 WHEN role = 'management' THEN 1 ELSE 2 END")
                    ->first();
                if ($operator === null) {
                    throw new LogicException('Production demo seeding requires an active existing user.');
                }
                $users = array_fill_keys(array_column($roles, 'role'), $operator);
            }

            $warehouse = Location::query()->updateOrCreate(['code' => 'DEMO-WH'], ['name' => 'Demo Central Warehouse', 'type' => 'warehouse', 'address' => 'Valji Building, Moktar Daddah Street, Nairobi', 'phone' => '+254728311537', 'timezone' => 'Africa/Nairobi', 'manager_id' => $users['super_admin']->id, 'is_active' => true]);
            $nairobi = Location::query()->updateOrCreate(['code' => 'DEMO-NAIROBI'], ['name' => 'Demo Nairobi Branch', 'type' => 'branch', 'address' => 'Nairobi CBD', 'phone' => '+254728311537', 'timezone' => 'Africa/Nairobi', 'manager_id' => $users['storekeeper']->id, 'is_active' => true]);
            $mombasa = Location::query()->updateOrCreate(['code' => 'DEMO-MOMBASA'], ['name' => 'Demo Mombasa Branch', 'type' => 'branch', 'address' => 'Mombasa', 'phone' => '+254728311537', 'timezone' => 'Africa/Nairobi', 'manager_id' => $users['storekeeper']->id, 'is_active' => true]);
            if ($isLocalOrTesting) {
                foreach ($users as $user) {
                    $user->locations()->syncWithoutDetaching([
                        $warehouse->id => ['is_primary' => $user->role === 'super_admin'],
                        $nairobi->id => ['is_primary' => $user->role !== 'super_admin'],
                        $mombasa->id => ['is_primary' => false],
                    ]);
                }
            }

            $institutions = [];
            foreach ([
                ['name' => 'Demo University of Nairobi', 'type' => 'university', 'county' => 'Nairobi'],
                ['name' => 'Demo Kenya Institute of Technology', 'type' => 'tvet', 'county' => 'Nairobi'],
                ['name' => 'Demo Coast College', 'type' => 'college', 'county' => 'Mombasa'],
            ] as $institution) {
                $institutions[] = Institution::query()->updateOrCreate(['name' => $institution['name']], [...$institution, 'email' => 'registry@gownsea.test', 'phone' => '+254728311537', 'address' => $institution['county'], 'is_active' => true]);
            }

            $customers = [];
            foreach ([
                ['customer_number' => 'DEMO-CUS-0001', 'name' => 'Amina Wanjiku', 'type' => 'individual', 'institution_id' => $institutions[0]->id],
                ['customer_number' => 'DEMO-CUS-0002', 'name' => 'Brian Kiptoo', 'type' => 'individual', 'institution_id' => $institutions[1]->id],
                ['customer_number' => 'DEMO-CUS-0003', 'name' => 'Cynthia Achieng', 'type' => 'individual', 'institution_id' => $institutions[2]->id],
                ['customer_number' => 'DEMO-CUS-0004', 'name' => 'University Events Office', 'type' => 'institution', 'institution_id' => $institutions[0]->id],
                ['customer_number' => 'DEMO-CUS-0005', 'name' => 'Coast College Procurement', 'type' => 'institution', 'institution_id' => $institutions[2]->id],
            ] as $index => $customerData) {
                $customers[] = Customer::query()->updateOrCreate(
                    ['customer_number' => $customerData['customer_number']],
                    [...$customerData, 'phone' => '+254700000'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'email' => 'demo.customer'.($index + 1).'@gownsea.test', 'source' => 'demo', 'notes' => 'Local-only sample record for workflow testing.'],
                );
                foreach ([$warehouse, $nairobi, $mombasa] as $location) {
                    $customers[$index]->locations()->syncWithoutDetaching([$location->id => ['assigned_by' => $users['super_admin']->id]]);
                }
            }

            $catalog = [
                ['prefix' => 'CERT', 'name' => 'Certificate Gown', 'classification' => 'CERTIFICATE', 'sale' => 450000],
                ['prefix' => 'DIPL', 'name' => 'Diploma Graduation Gown', 'classification' => 'DIPLOMA', 'sale' => 450000],
                ['prefix' => 'BACH', 'name' => "Bachelor's Graduation Gown, Cap & Hood Set", 'classification' => 'BACHELORS', 'sale' => 650000],
                ['prefix' => 'MAST', 'name' => "Master's Gown", 'classification' => 'MASTERS', 'sale' => 750000],
                ['prefix' => 'PHD', 'name' => 'PhD Graduation Gown', 'classification' => 'PHD', 'sale' => 2000000],
            ];
            $sizes = ['S', 'M', 'L', 'XL'];
            $products = [];
            foreach ($catalog as $style) {
                foreach ($sizes as $sizeIndex => $size) {
                    $sku = 'DEMO-'.$style['prefix'].'-'.$size;
                    $products[$sku] = Product::query()->updateOrCreate(
                        ['sku' => $sku],
                        [
                            'name' => $style['name'].' · '.$size,
                            'category' => 'gown',
                            'tracking_type' => 'serialized',
                            'academic_classification_id' => $classificationIds[$style['classification']],
                            'qualification_level' => $style['classification'],
                            'size' => $size,
                            'color' => ['Black', 'Navy', 'Maroon', 'Black'][$sizeIndex],
                            'description' => 'Local demo catalog entry based on GOWNSEA graduation attire. Size '.$size.'.',
                            'purchase_cost_minor' => (int) round($style['sale'] * 0.6),
                            'sale_price_minor' => $style['sale'],
                            'hire_rate_minor' => 12500,
                            'useful_life_months' => 36,
                            'reorder_threshold' => 2,
                            'is_active' => true,
                        ],
                    );
                }
            }

            $assets = [];
            $productList = array_values($products);
            for ($index = 0; $index < 20; $index++) {
                $assetCode = 'DEMO-GOWN-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                $status = match ($index) {
                    0, 1 => 'reserved',
                    2, 3 => 'hired',
                    4 => 'returned_pending_inspection',
                    5 => 'missing',
                    6 => 'damaged',
                    default => 'available',
                };
                $currentLocationId = in_array($status, ['hired', 'missing'], true) ? null : ($index % 2 === 0 ? $nairobi->id : $warehouse->id);
                $asset = PhysicalAsset::query()->updateOrCreate(
                    ['asset_code' => $assetCode],
                    [
                        'product_id' => $productList[$index]->id,
                        'home_location_id' => $nairobi->id,
                        'current_location_id' => $currentLocationId,
                        'custodian_customer_id' => in_array($status, ['hired', 'missing', 'returned_pending_inspection'], true) ? $customers[0]->id : null,
                        'custody_type' => match ($status) {
                            'hired' => 'customer', 'missing' => 'missing', 'returned_pending_inspection' => 'inspection', default => 'branch'
                        },
                        'status' => $status,
                        'condition' => $status === 'damaged' ? 'damaged' : 'good',
                        'last_verified_at' => $now,
                        'acquisition_cost_minor' => (int) round($productList[$index]->sale_price_minor * 0.6),
                        'acquired_on' => $now->copy()->subMonths(4)->toDateString(),
                        'accumulated_depreciation_minor' => 0,
                    ],
                );
                $assets[] = $asset;
                $this->upsert('stock_movements', ['idempotency_key' => 'demo:stock-received:'.$assetCode], [
                    'product_id' => $asset->product_id, 'physical_asset_id' => $asset->id,
                    'to_location_id' => $nairobi->id, 'actor_id' => $users['storekeeper']->id,
                    'movement_type' => 'received', 'to_status' => 'available', 'quantity' => 1,
                    'notes' => 'Demo opening stock for local workflow testing.', 'occurred_at' => $now->copy()->subMonths(4),
                ]);
            }

            $events = [];
            foreach ([
                ['name' => 'Demo University Graduation', 'institution' => $institutions[0], 'location' => $nairobi, 'date' => $now->copy()->addWeeks(3)],
                ['name' => 'Demo Coast College Commencement', 'institution' => $institutions[2], 'location' => $mombasa, 'date' => $now->copy()->addWeeks(6)],
            ] as $event) {
                $events[] = $this->upsert('graduation_events', ['name' => $event['name']], [
                    'institution_id' => $event['institution']->id, 'location_id' => $event['location']->id,
                    'owner_id' => $users['sales_crm']->id, 'event_date' => $event['date']->toDateString(),
                    'venue' => $event['location']->address, 'expected_quantity' => 180,
                    'collection_date' => $event['date']->copy()->subDay()->toDateString(),
                    'delivery_date' => $event['date']->toDateString(), 'notes' => 'Local demo event.', 'status' => 'planned',
                ]);
            }

            $hireIssued = $this->upsert('hires', ['reference' => 'DEMO-HIRE-ISSUED'], [
                'customer_id' => $customers[0]->id, 'institution_id' => $institutions[0]->id, 'graduation_event_id' => $events[0],
                'location_id' => $nairobi->id, 'created_by' => $users['storekeeper']->id, 'status' => 'issued',
                'event_date' => $now->copy()->subDay()->toDateString(), 'issued_at' => $now->copy()->subDays(5),
                'due_at' => $now->copy()->addDays(2), 'deposit_minor' => 50000, 'notes' => 'Sample issued hire.',
            ]);
            foreach ([2, 3] as $assetIndex) {
                $this->upsert('hire_items', ['physical_asset_id' => $assets[$assetIndex]->id], [
                    'hire_id' => $hireIssued, 'product_id' => $assets[$assetIndex]->product_id,
                    'product_name_snapshot' => $assets[$assetIndex]->product->name,
                    'classification_code_snapshot' => $assets[$assetIndex]->product->qualification_level,
                    'qualification_snapshot' => $assets[$assetIndex]->product->qualification_level,
                    'quantity' => 1, 'returned_quantity' => 0, 'unit_rate_minor' => $assets[$assetIndex]->product->hire_rate_minor,
                    'penalty_assessed_minor' => 0, 'status' => 'issued',
                ]);
                $this->upsert('asset_custody_events', ['idempotency_key' => 'demo:hire-issued:'.$assets[$assetIndex]->asset_code], [
                    'physical_asset_id' => $assets[$assetIndex]->id, 'actor_id' => $users['storekeeper']->id,
                    'from_location_id' => $nairobi->id, 'customer_id' => $customers[0]->id,
                    'event_type' => 'hire_issued', 'from_status' => 'available', 'to_status' => 'hired',
                    'from_custody_type' => 'branch', 'to_custody_type' => 'customer',
                    'notes' => 'Demo hire issue.', 'occurred_at' => $now->copy()->subDays(5),
                ]);
            }

            $hireReserved = $this->upsert('hires', ['reference' => 'DEMO-HIRE-RESERVED'], [
                'customer_id' => $customers[1]->id, 'institution_id' => $institutions[1]->id, 'graduation_event_id' => $events[0],
                'location_id' => $nairobi->id, 'created_by' => $users['storekeeper']->id, 'status' => 'reserved',
                'event_date' => $now->copy()->addWeeks(2)->toDateString(), 'due_at' => $now->copy()->addWeeks(3),
                'deposit_minor' => 25000, 'notes' => 'Sample reservation awaiting collection.',
            ]);
            foreach ([0, 1] as $assetIndex) {
                $this->upsert('hire_items', ['physical_asset_id' => $assets[$assetIndex]->id], [
                    'hire_id' => $hireReserved, 'product_id' => $assets[$assetIndex]->product_id,
                    'product_name_snapshot' => $assets[$assetIndex]->product->name,
                    'classification_code_snapshot' => $assets[$assetIndex]->product->qualification_level,
                    'qualification_snapshot' => $assets[$assetIndex]->product->qualification_level,
                    'quantity' => 1, 'returned_quantity' => 0, 'unit_rate_minor' => $assets[$assetIndex]->product->hire_rate_minor,
                    'penalty_assessed_minor' => 0, 'status' => 'reserved',
                ]);
                $this->upsert('asset_custody_events', ['idempotency_key' => 'demo:hire-reserved:'.$assets[$assetIndex]->asset_code], [
                    'physical_asset_id' => $assets[$assetIndex]->id, 'actor_id' => $users['storekeeper']->id,
                    'from_location_id' => $nairobi->id, 'to_location_id' => $nairobi->id,
                    'customer_id' => $customers[1]->id, 'event_type' => 'hire_reserved',
                    'from_status' => 'available', 'to_status' => 'reserved',
                    'from_custody_type' => 'branch', 'to_custody_type' => 'branch',
                    'notes' => 'Demo reservation.', 'occurred_at' => $now->copy()->subHours(6),
                ]);
            }

            $hireReturned = $this->upsert('hires', ['reference' => 'DEMO-HIRE-RETURNED'], [
                'customer_id' => $customers[2]->id, 'institution_id' => $institutions[2]->id,
                'location_id' => $mombasa->id, 'created_by' => $users['storekeeper']->id, 'status' => 'returned_pending_inspection',
                'event_date' => $now->copy()->subWeeks(2)->toDateString(), 'issued_at' => $now->copy()->subWeeks(3),
                'due_at' => $now->copy()->subWeeks(1), 'deposit_minor' => 15000, 'notes' => 'Sample return awaiting inspection.',
            ]);
            $returnedAsset = $assets[4];
            $this->upsert('hire_items', ['physical_asset_id' => $returnedAsset->id], [
                'hire_id' => $hireReturned, 'product_id' => $returnedAsset->product_id,
                'product_name_snapshot' => $returnedAsset->product->name,
                'classification_code_snapshot' => $returnedAsset->product->qualification_level,
                'qualification_snapshot' => $returnedAsset->product->qualification_level,
                'quantity' => 1, 'returned_quantity' => 1, 'unit_rate_minor' => $returnedAsset->product->hire_rate_minor,
                'penalty_assessed_minor' => 0, 'returned_at' => $now->copy()->subHours(4),
                'return_condition' => 'good', 'status' => 'returned_pending_inspection',
            ]);

            $hireException = $this->upsert('hires', ['reference' => 'DEMO-HIRE-EXCEPTION'], [
                'customer_id' => $customers[0]->id, 'institution_id' => $institutions[0]->id,
                'location_id' => $nairobi->id, 'created_by' => $users['storekeeper']->id, 'status' => 'partially_returned',
                'event_date' => $now->copy()->subWeeks(4)->toDateString(), 'issued_at' => $now->copy()->subWeeks(5),
                'due_at' => $now->copy()->subWeeks(3), 'deposit_minor' => 30000, 'notes' => 'Sample overdue and missing-item exception.',
            ]);
            $missingAsset = $assets[5];
            $this->upsert('hire_items', ['physical_asset_id' => $missingAsset->id], [
                'hire_id' => $hireException, 'product_id' => $missingAsset->product_id,
                'product_name_snapshot' => $missingAsset->product->name,
                'classification_code_snapshot' => $missingAsset->product->qualification_level,
                'qualification_snapshot' => $missingAsset->product->qualification_level,
                'quantity' => 1, 'returned_quantity' => 0, 'unit_rate_minor' => $missingAsset->product->hire_rate_minor,
                'penalty_assessed_minor' => 25000, 'status' => 'missing',
            ]);
            $this->upsert('asset_custody_events', ['idempotency_key' => 'demo:hire-missing:'.$missingAsset->asset_code], [
                'physical_asset_id' => $missingAsset->id, 'actor_id' => $users['storekeeper']->id,
                'from_location_id' => $nairobi->id, 'customer_id' => $customers[0]->id,
                'event_type' => 'asset_reported_missing', 'from_status' => 'hired', 'to_status' => 'missing',
                'from_custody_type' => 'customer', 'to_custody_type' => 'missing',
                'notes' => 'Demo exception state for local testing.', 'occurred_at' => $now->copy()->subWeeks(3),
            ]);

            foreach ([[$hireIssued, $customers[0], 100000, 'hire_charge'], [$hireReserved, $customers[1], 25000, 'deposit']] as [$hireId, $customer, $amount, $purpose]) {
                $this->upsert('payments', ['reference' => 'DEMO-PAY-'.$hireId], [
                    'customer_id' => $customer->id, 'hire_id' => $hireId, 'location_id' => $nairobi->id,
                    'recorded_by' => $users['finance']->id, 'amount_minor' => $amount, 'currency' => 'KES',
                    'method' => 'mpesa', 'purpose' => $purpose, 'status' => 'received',
                    'provider_reference' => 'DEMO-MPESA-'.$hireId, 'received_at' => $now->copy()->subDay(),
                ]);
            }

            $countId = $this->upsert('inventory_counts', ['reference' => 'DEMO-COUNT-OPEN'], [
                'idempotency_key' => 'demo-count-open-idempotency-key', 'location_id' => $nairobi->id,
                'started_by' => $users['storekeeper']->id, 'status' => 'awaiting_approval',
                'expected_count' => 8, 'counted_count' => 7, 'notes' => 'Demo cycle count awaiting approval.',
            ]);
            foreach (array_slice($assets, 8, 8) as $asset) {
                DB::table('inventory_count_lines')->updateOrInsert(
                    ['inventory_count_id' => $countId, 'physical_asset_id' => $asset->id],
                    ['expected_status' => $asset->status, 'was_counted' => $asset->id !== $assets[15]->id, 'counted_at' => $now],
                );
            }

            $bulkCaps = Product::query()->updateOrCreate(['sku' => 'DEMO-BULK-CAP'], ['name' => 'Graduation Cap (Bulk Stock)', 'category' => 'cap', 'tracking_type' => 'bulk', 'sale_price_minor' => 490000, 'hire_rate_minor' => 0, 'purchase_cost_minor' => 250000, 'reorder_threshold' => 10, 'is_active' => true]);
            $bulkTassels = Product::query()->updateOrCreate(['sku' => 'DEMO-BULK-TASSEL'], ['name' => 'Graduation Tassel (Bulk Stock)', 'category' => 'tassel', 'tracking_type' => 'bulk', 'sale_price_minor' => 35000, 'hire_rate_minor' => 0, 'purchase_cost_minor' => 20000, 'reorder_threshold' => 15, 'is_active' => true]);
            foreach ([[$bulkCaps, 24, 12000000], [$bulkTassels, 8, 160000]] as [$product, $onHand, $value]) {
                $this->upsert('bulk_stock_balances', ['product_id' => $product->id, 'location_id' => $nairobi->id], [
                    'on_hand' => $onHand, 'inventory_value_minor' => $value, 'valuation_complete' => true,
                    'reserved' => 2, 'pending_inspection' => 1, 'damaged' => 1,
                ]);
            }

            $leadId = $this->upsert('leads', ['reference' => 'DEMO-LEAD-0001'], [
                'location_id' => $nairobi->id, 'owner_id' => $users['sales_crm']->id, 'customer_id' => $customers[3]->id,
                'institution_id' => $institutions[0]->id, 'contact_name' => 'University Events Office',
                'phone' => '+254700000100', 'email' => 'events@u-demo.ac.ke', 'source' => 'website', 'stage' => 'qualified',
                'estimated_value_minor' => 117000000, 'probability' => 65, 'next_action' => 'Confirm size schedule',
                'follow_up_at' => $now->copy()->addDays(3), 'notes' => 'Demo institutional bulk gown enquiry.',
            ]);
            $this->upsert('lead_activities', ['lead_id' => $leadId, 'activity_type' => 'call', 'details' => 'Discussed ceremony date, sizes and delivery plan.'], [
                'actor_id' => $users['sales_crm']->id, 'happened_at' => $now->copy()->subDay(),
            ]);
            $this->upsert('lead_tasks', ['lead_id' => $leadId, 'title' => 'Send graduation gown size schedule'], [
                'assigned_to' => $users['sales_crm']->id, 'created_by' => $users['sales_crm']->id,
                'description' => 'Send the sample size schedule and confirm quantities.', 'due_at' => $now->copy()->addDays(3),
            ]);
            $quotationId = $this->upsert('quotations', ['reference' => 'DEMO-QUOTE-0001'], [
                'lead_id' => $leadId, 'customer_id' => $customers[3]->id, 'location_id' => $nairobi->id,
                'created_by' => $users['sales_crm']->id, 'revision' => 1, 'status' => 'sent',
                'valid_until' => $now->copy()->addDays(30)->toDateString(), 'subtotal_minor' => 117000000,
                'tax_minor' => 0, 'total_minor' => 117000000, 'notes' => 'Demo quotation for a graduation gown package.',
            ]);
            $this->upsert('quotation_lines', ['quotation_id' => $quotationId, 'revision' => 1, 'description' => 'Bachelor gown, cap and hood set', 'quantity' => 180], [
                'unit_price_minor' => 650000, 'line_total_minor' => 117000000,
            ]);

            $orderId = $this->upsert('custom_orders', ['reference' => 'DEMO-ORDER-0001'], [
                'location_id' => $nairobi->id, 'customer_id' => $customers[4]->id, 'institution_id' => $institutions[0]->id,
                'created_by' => $users['sales_crm']->id, 'owner_id' => $users['production']->id, 'status' => 'in_production',
                'item_type' => 'Custom faculty hood', 'size' => 'M', 'color' => 'Navy and gold', 'quantity' => 12,
                'measurements' => json_encode(['hood_length_cm' => 90, 'head_size_cm' => 58]),
                'design_specs' => 'Demo faculty colours and lining.', 'special_requirements' => 'Match institution colours.',
                'agreed_total_minor' => 18000000, 'deposit_required_minor' => 6000000,
                'order_date' => $now->copy()->subDays(5)->toDateString(), 'expected_completion_date' => $now->copy()->addWeeks(2)->toDateString(),
                'priority' => 'normal', 'delivery_method' => 'collection', 'notes' => 'Local sample order.',
            ]);
            $this->upsert('custom_order_events', ['custom_order_id' => $orderId, 'event_type' => 'status_changed', 'reason' => 'Sample order moved into production.'], [
                'actor_id' => $users['production']->id, 'reason' => 'Sample order moved into production.',
                'occurred_at' => $now->copy()->subDay(),
                'after_state' => json_encode(['status' => 'in_production']),
            ]);
            $this->upsert('custom_order_tasks', ['custom_order_id' => $orderId, 'title' => 'Complete hood lining'], [
                'assigned_to' => $users['production']->id, 'task_type' => 'production', 'description' => 'Finish and inspect the custom hood lining.',
                'due_date' => $now->copy()->addWeek()->toDateString(), 'status' => 'todo',
            ]);
        });
    }

    /** @param array<string, mixed> $keys @param array<string, mixed> $values */
    private function upsert(string $table, array $keys, array $values): int
    {
        $now = now();
        DB::table($table)->updateOrInsert($keys, [...$values, 'created_at' => $now, 'updated_at' => $now]);

        return (int) DB::table($table)->where($keys)->value('id');
    }
}
