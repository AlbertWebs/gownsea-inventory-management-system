<?php

namespace App\Reports;

use App\Models\Hire;
use App\Models\LateReturnPolicy;
use App\Services\HirePenaltyBalance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OperationalReportCsv
{
    public function __construct(private readonly HirePenaltyBalance $penaltyBalance) {}

    /** @param resource $stream @param list<int>|null $locationIds */
    public function write($stream, string $report, string $from, string $to, ?array $locationIds, ?int $customerId = null): void
    {
        [$headers, $queries] = $this->queries($report, $from, $to, $locationIds, $customerId);
        $accruedByHire = collect();
        if ($report === 'outstanding-balances') {
            $activeHireIds = Hire::query()->whereIn('status', ['issued', 'partially_returned'])
                ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))->pluck('id');
            $accruedByHire = $this->penaltyBalance->accruedByHireIds($activeHireIds, CarbonImmutable::parse($to)->endOfDay());
        }
        fputcsv($stream, $headers, ',', '"', '');
        $statementTotals = ['revenue' => 0, 'expense' => 0];

        foreach ($queries as $query) {
            foreach ($query->cursor() as $row) {
                $values = array_values((array) $row);
                if ($report === 'overdue-returns') {
                    $days = max(1, CarbonImmutable::parse($row->due_at)->startOfDay()->diffInDays(CarbonImmutable::parse($to)->startOfDay()));
                    $policy = json_decode($row->penalty_policy_snapshot, true) ?: config('gownsea.penalties');
                    $accrued = LateReturnPolicy::assess(Carbon::parse($row->due_at), Carbon::parse($to)->endOfDay(), $policy)['amount_minor'];
                    $values = [$row->hire_reference, $row->customer, $row->asset_code, $row->product, $row->due_at, $days, (int) $row->penalty_assessed_minor + $accrued, $row->location];
                } elseif ($report === 'outstanding-balances') {
                    $accrued = $row->entity_type === 'hire' ? (int) $accruedByHire->get($row->hire_id, 0) : 0;
                    $billed = (int) $row->billed_minor + $accrued;
                    $values = [$row->entity_type, $row->reference, $row->customer, $billed, $row->received_minor, max(0, $billed - $row->received_minor), $row->due_at, $row->location];
                } elseif ($report === 'inventory-valuation') {
                    $values[5] = $row->on_hand > 0 ? intdiv((int) $row->inventory_value_minor, (int) $row->on_hand) : 0;
                    $values[6] = $row->valuation_complete ? 'confirmed' : 'needs valuation';
                } elseif ($report === 'vendor-payables') {
                    $asOf = CarbonImmutable::parse($to)->startOfDay();
                    $due = CarbonImmutable::parse($row->due_date)->startOfDay();
                    $daysOverdue = (int) $row->balance_minor > 0 && $due->lessThan($asOf) ? (int) $due->diffInDays($asOf) : 0;
                    $bucket = match (true) {
                        (int) $row->balance_minor <= 0 => 'Paid / settled',
                        $daysOverdue === 0 => 'Current / not yet due',
                        $daysOverdue <= 30 => '1-30 days overdue',
                        $daysOverdue <= 60 => '31-60 days overdue',
                        $daysOverdue <= 90 => '61-90 days overdue',
                        default => 'Over 90 days overdue',
                    };
                    $values = [$row->vendor, $row->invoice_number, $row->bill_reference, $row->invoice_date, $row->due_date, $row->location, $row->amount_minor, $row->paid_minor, $row->balance_minor, $row->payment_state, $daysOverdue, $bucket];
                } elseif ($report === 'journaled-income-statement') {
                    $statementTotals[$row->account_type] += (int) $row->net_minor;
                } elseif ($report === 'custom-order-margin') {
                    $contribution = (int) $row->agreed_total_minor - (int) $row->direct_cost_minor;
                    $marginPercent = (int) $row->agreed_total_minor > 0 ? number_format($contribution / (int) $row->agreed_total_minor * 100, 2, '.', '') : '0.00';
                    $values = [$row->reference, $row->customer, $row->status, $row->item_type, $row->order_date, $row->due_date, $row->location, $row->agreed_total_minor, $row->direct_cost_minor, $contribution, $marginPercent];
                }
                fputcsv($stream, array_map([$this, 'safeCell'], $values), ',', '"', '');
            }
        }

        if ($report === 'journaled-income-statement') {
            foreach ([
                ['revenue', '', 'Total journaled revenue', '', '', $statementTotals['revenue']],
                ['expense', '', 'Total journaled expenses', '', '', $statementTotals['expense']],
                ['result', '', 'Net journaled result', '', '', $statementTotals['revenue'] - $statementTotals['expense']],
            ] as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
            fputcsv($stream, ['Basis', 'Posted journal activity only; cash-basis revenue, unposted items, tax and statutory adjustments are excluded.'], ',', '"', '');
        }
    }

    /** @param list<int>|null $locationIds @return array{list<string>,list<Builder>} */
    private function queries(string $report, string $from, string $to, ?array $locationIds, ?int $customerId): array
    {
        return match ($report) {
            'available-inventory' => [[
                'Asset code', 'Product', 'SKU', 'Category', 'Classification', 'Size', 'Color', 'Condition', 'Location',
            ], [$this->stockQuery($locationIds)]],
            'bulk-stock' => [[
                'Product', 'SKU', 'Category', 'Location', 'On hand', 'Reserved', 'Available', 'Reorder threshold', 'Reorder needed',
            ], [$this->bulkStockQuery($locationIds)]],
            'inventory-valuation' => [[
                'Product', 'SKU', 'Location', 'On hand', 'Inventory value KES minor', 'Average unit cost KES minor', 'Valuation status', 'Valuation method',
            ], [$this->inventoryValuationQuery($locationIds)]],
            'cost-of-goods-sold' => [[
                'Date', 'Journal reference', 'Source reference', 'Entry type', 'Location', 'Debit KES minor', 'Credit KES minor', 'Description',
            ], [$this->costOfGoodsSoldQuery($locationIds, $from, $to)]],
            'bulk-movements' => [[
                'Movement ID', 'Occurred at', 'Product', 'SKU', 'Movement type', 'Quantity', 'Source', 'Destination', 'Staff', 'Reason',
            ], [$this->bulkMovementQuery($locationIds, $from, $to)]],
            'hired-out' => [[
                'Hire reference', 'Customer', 'Institution', 'Asset code', 'Product', 'Issued at', 'Due at', 'Location',
            ], [$this->hireItemsQuery($locationIds, false, $to, $customerId)]],
            'overdue-returns' => [[
                'Hire reference', 'Customer', 'Asset code', 'Product', 'Due at', 'Days overdue', 'Current assessed/accrued penalty KES minor', 'Location',
            ], [$this->hireItemsQuery($locationIds, true, $to, $customerId)]],
            'customer-hiring-history' => [[
                'Customer', 'Hire reference', 'Status', 'Asset code', 'Product', 'Issued at', 'Due at', 'Returned at', 'Service rate KES minor',
            ], [$this->historyQuery($locationIds, $from, $to, $customerId)]],
            'transfers-custody' => [[
                'Transfer reference', 'Status', 'Source', 'Destination', 'Requested by', 'Carrier', 'Waybill', 'Dispatched at', 'Received at',
            ], [$this->transferQuery($locationIds, $from, $to)]],
            'sales-revenue' => [[
                'Receipt or application', 'Customer', 'Hire', 'Custom order', 'Bulk sale', 'Purpose', 'Method', 'Gross KES minor', 'Refunded KES minor', 'Net KES minor', 'Received or applied at', 'Location',
            ], [$this->paymentQuery($locationIds, $from, $to, $customerId), $this->hireDepositApplicationQuery($locationIds, $from, $to, $customerId)]],
            'outstanding-balances' => [[
                'Entity type', 'Reference', 'Customer', 'Billed KES minor', 'Net charge receipts KES minor', 'Outstanding KES minor', 'Due date', 'Location',
            ], [$this->hireBalancesQuery($locationIds, $customerId), $this->customOrderBalancesQuery($locationIds, $customerId)]],
            'approved-expenses' => [[
                'Expense reference', 'Date incurred', 'Location', 'Category', 'Vendor', 'Receipt reference', 'Description', 'Amount KES minor', 'Reviewed by',
            ], [$this->expenseQuery($locationIds, $from, $to)]],
            'cash-basis-activity' => [[
                'Date', 'Entry type', 'Reference', 'Customer or vendor', 'Location', 'Charges received KES minor', 'Refunds KES minor', 'Approved expenses KES minor', 'Net period activity KES minor',
            ], [$this->cashBasisActivityQuery($locationIds, $from, $to)]],
            'vendor-payables' => [[
                'Vendor', 'Supplier invoice', 'Bill reference', 'Invoice date', 'Due date', 'Location', 'Billed KES minor', 'Paid by as-of date KES minor', 'Outstanding KES minor', 'Payment state', 'Days overdue', 'Aging bucket',
            ], [$this->vendorPayablesQuery($locationIds, $from, $to)]],
            'journaled-income-statement' => [[
                'Account type', 'Account code', 'Account', 'Debit KES minor', 'Credit KES minor', 'Net activity KES minor',
            ], [$this->journaledIncomeStatementQuery($locationIds, $from, $to)]],
            'tax-detail' => [[
                'Invoice date', 'Invoice type', 'Invoice number', 'Customer', 'Location', 'Tax code', 'Treatment', 'Rate percent', 'Taxable value KES minor', 'Tax KES minor', 'Invoice line total KES minor',
            ], [$this->taxDetailQuery($locationIds, $from, $to)]],
            'loss-damage-repair' => [[
                'Asset code', 'Product', 'Status', 'Condition', 'Current location', 'Home location', 'Last verified', 'Updated at',
            ], [$this->lossQuery($locationIds)]],
            'leads-quotations' => [[
                'Lead reference', 'Contact', 'Institution', 'Owner', 'Stage', 'Source', 'Estimated value KES minor', 'Probability', 'Follow-up at', 'Quote count', 'Latest quote status', 'Latest quote value KES minor',
            ], [$this->leadQuery($locationIds, $from, $to, $customerId)]],
            'custom-orders' => [[
                'Order reference', 'Customer', 'Institution', 'Status', 'Item', 'Quantity', 'Agreed total KES minor', 'Deposit required KES minor', 'Order date', 'Due date', 'Delivery method', 'Tracking number', 'Location',
            ], [$this->customOrderQuery($locationIds, $from, $to, $customerId)]],
            'custom-order-margin' => [[
                'Order reference', 'Customer', 'Status', 'Item', 'Order date', 'Due date', 'Location', 'Agreed total KES minor', 'Direct costs through period end KES minor', 'Contribution estimate KES minor', 'Contribution margin percent',
            ], [$this->customOrderMarginQuery($locationIds, $from, $to, $customerId)]],
            'staff-activity' => [[
                'Occurred at', 'Staff member', 'Action', 'Subject type', 'Subject ID', 'Location', 'Reason',
            ], [$this->auditQuery($locationIds, $from, $to)]],
            default => abort(404),
        };
    }

    /** @param list<int>|null $locationIds */
    private function stockQuery(?array $locationIds): Builder
    {
        return DB::table('physical_assets as a')->join('products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('academic_classifications as c', 'c.id', '=', 'p.academic_classification_id')
            ->leftJoin('locations as l', 'l.id', '=', 'a.current_location_id')->where('a.status', 'available')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('a.current_location_id', $locationIds))
            ->select('a.asset_code', 'p.name as product', 'p.sku', 'p.category', 'c.label as classification', 'p.size', 'p.color', 'a.condition', 'l.name as location')
            ->orderBy('a.id');
    }

    /** @param list<int>|null $locationIds */
    private function bulkStockQuery(?array $locationIds): Builder
    {
        return DB::table('bulk_stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('locations as l', 'l.id', '=', 'b.location_id')->where('p.tracking_type', 'bulk')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('b.location_id', $locationIds))
            ->select('p.name as product', 'p.sku', 'p.category', 'l.name as location', 'b.on_hand', 'b.reserved', 'b.pending_inspection', 'b.damaged')
            ->selectRaw('CASE WHEN b.on_hand > b.reserved + b.pending_inspection + b.damaged THEN b.on_hand - b.reserved - b.pending_inspection - b.damaged ELSE 0 END as available')
            ->addSelect('p.reorder_threshold')->selectRaw('CASE WHEN b.on_hand - b.reserved - b.pending_inspection - b.damaged <= p.reorder_threshold THEN ? ELSE ? END as reorder_needed', ['yes', 'no'])
            ->orderBy('p.name')->orderBy('l.name');
    }

    /** @param list<int>|null $locationIds */
    private function inventoryValuationQuery(?array $locationIds): Builder
    {
        return DB::table('bulk_stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')->join('locations as l', 'l.id', '=', 'b.location_id')
            ->where('p.tracking_type', 'bulk')->when($locationIds !== null, fn ($query) => $query->whereIn('b.location_id', $locationIds))
            ->select('p.name as product', 'p.sku', 'l.name as location', 'b.on_hand', 'b.inventory_value_minor', 'b.valuation_complete')
            ->selectRaw('CASE WHEN b.on_hand > 0 THEN b.inventory_value_minor / b.on_hand ELSE 0 END as average_unit_cost_minor')
            ->selectRaw('? as valuation_method', ['Moving weighted average by product and location'])
            ->orderBy('p.name')->orderBy('l.name');
    }

    /** @param list<int>|null $locationIds */
    private function costOfGoodsSoldQuery(?array $locationIds, string $from, string $to): Builder
    {
        return DB::table('financial_journal_lines as jl')->join('financial_journal_entries as je', 'je.id', '=', 'jl.financial_journal_entry_id')
            ->join('chart_accounts as ca', 'ca.id', '=', 'jl.chart_account_id')->leftJoin('locations as l', 'l.id', '=', 'jl.location_id')
            ->where('ca.code', '5000')->whereDate('je.occurred_on', '>=', $from)->whereDate('je.occurred_on', '<=', $to)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('jl.location_id', $locationIds))
            ->select('je.occurred_on as entry_date', 'je.reference as journal_reference', 'je.source_reference', 'je.entry_type', 'l.name as location', 'jl.debit_minor', 'jl.credit_minor', 'je.description')
            ->orderBy('je.occurred_on')->orderBy('je.id')->orderBy('jl.line_number');
    }

    /** @param list<int>|null $locationIds */
    private function bulkMovementQuery(?array $locationIds, string $from, string $to): Builder
    {
        return DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('locations as src', 'src.id', '=', 'm.from_location_id')->leftJoin('locations as dst', 'dst.id', '=', 'm.to_location_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.actor_id')->whereNull('m.physical_asset_id')->where('m.movement_type', 'like', 'bulk_%')
            ->whereBetween('m.occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($locationIds !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereIn('m.from_location_id', $locationIds)->orWhereIn('m.to_location_id', $locationIds)))
            ->select('m.id', 'm.occurred_at', 'p.name as product', 'p.sku', 'm.movement_type', 'm.quantity', 'src.name as source', 'dst.name as destination', 'u.name as staff', 'm.notes as reason')
            ->orderBy('m.occurred_at');
    }

    /** @param list<int>|null $locationIds */
    private function hireItemsQuery(?array $locationIds, bool $overdue, string $asOf, ?int $customerId): Builder
    {
        $query = DB::table('hire_items as hi')->join('hires as h', 'h.id', '=', 'hi.hire_id')->join('customers as c', 'c.id', '=', 'h.customer_id')
            ->join('physical_assets as a', 'a.id', '=', 'hi.physical_asset_id')->leftJoin('products as p', 'p.id', '=', 'hi.product_id')->leftJoin('locations as l', 'l.id', '=', 'h.location_id')
            ->where('hi.status', 'issued')->when($locationIds !== null, fn ($q) => $q->whereIn('h.location_id', $locationIds))
            ->when($customerId !== null, fn ($q) => $q->where('h.customer_id', $customerId))
            ->orderBy('h.due_at')->orderBy('hi.id');

        if ($overdue) {
            $query->where('h.due_at', '<', $asOf.' 00:00:00')->select('h.reference as hire_reference', 'c.name as customer', 'a.asset_code', 'hi.product_name_snapshot as product', 'h.due_at', 'hi.penalty_assessed_minor', 'hi.penalty_policy_snapshot', 'l.name as location');
        } else {
            $query->select('h.reference as hire_reference', 'c.name as customer')
                ->selectRaw('(SELECT i.name FROM institutions i WHERE i.id = h.institution_id) as institution')
                ->addSelect('a.asset_code', 'hi.product_name_snapshot as product', 'h.issued_at', 'h.due_at', 'l.name as location');
        }

        return $query;
    }

    /** @param list<int>|null $locationIds */
    private function historyQuery(?array $locationIds, string $from, string $to, ?int $customerId): Builder
    {
        return DB::table('hire_items as hi')->join('hires as h', 'h.id', '=', 'hi.hire_id')->join('customers as c', 'c.id', '=', 'h.customer_id')
            ->leftJoin('physical_assets as a', 'a.id', '=', 'hi.physical_asset_id')->leftJoin('locations as l', 'l.id', '=', 'h.location_id')
            ->where('h.issued_at', '>=', $from.' 00:00:00')->where('h.issued_at', '<=', $to.' 23:59:59')->when($locationIds !== null, fn ($q) => $q->whereIn('h.location_id', $locationIds))
            ->when($customerId !== null, fn ($q) => $q->where('h.customer_id', $customerId))
            ->select('c.name as customer', 'h.reference as hire_reference', 'h.status', 'a.asset_code', 'hi.product_name_snapshot as product', 'h.issued_at', 'h.due_at', 'hi.returned_at', 'hi.unit_rate_minor')
            ->orderBy('h.issued_at')->orderBy('hi.id');
    }

    /** @param list<int>|null $locationIds */
    private function transferQuery(?array $locationIds, string $from, string $to): Builder
    {
        return DB::table('stock_transfers as t')->join('locations as src', 'src.id', '=', 't.source_location_id')->join('locations as dst', 'dst.id', '=', 't.destination_location_id')
            ->join('users as u', 'u.id', '=', 't.requested_by')->where('t.created_at', '>=', $from.' 00:00:00')->where('t.created_at', '<=', $to.' 23:59:59')
            ->when($locationIds !== null, fn ($q) => $q->where(fn ($inner) => $inner->whereIn('t.source_location_id', $locationIds)->orWhereIn('t.destination_location_id', $locationIds)))
            ->select('t.reference', 't.status', 'src.name as source', 'dst.name as destination', 'u.name as requested_by', 't.carrier', 't.waybill', 't.dispatched_at', 't.received_at')->orderBy('t.created_at');
    }

    /** @param list<int>|null $locationIds */
    private function paymentQuery(?array $locationIds, string $from, string $to, ?int $customerId): Builder
    {
        return DB::table('payments as p')->join('customers as c', 'c.id', '=', 'p.customer_id')->leftJoin('hires as h', 'h.id', '=', 'p.hire_id')
            ->leftJoin('custom_orders as co', 'co.id', '=', 'p.custom_order_id')->leftJoin('bulk_sales as bs', 'bs.id', '=', 'p.bulk_sale_id')->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
            ->leftJoinSub(DB::table('payment_refunds')->selectRaw('payment_id, SUM(amount_minor) as refunded_minor')->groupBy('payment_id'), 'r', 'r.payment_id', '=', 'p.id')
            ->where('p.status', 'received')->where('p.received_at', '>=', $from.' 00:00:00')->where('p.received_at', '<=', $to.' 23:59:59')->when($locationIds !== null, fn ($q) => $q->whereIn('p.location_id', $locationIds))
            ->when($customerId !== null, fn ($q) => $q->where('p.customer_id', $customerId))
            ->select('p.reference', 'c.name as customer', 'h.reference as hire_reference', 'co.reference as custom_order_reference', 'bs.reference as bulk_sale_reference', 'p.purpose', 'p.method', 'p.amount_minor as gross_minor')
            ->selectRaw('COALESCE(r.refunded_minor, 0) as refunded_minor')->selectRaw('p.amount_minor - COALESCE(r.refunded_minor, 0) as net_minor')
            ->addSelect('p.received_at', 'l.name as location')->orderBy('p.received_at');
    }

    /** @param list<int>|null $locationIds */
    private function hireDepositApplicationQuery(?array $locationIds, string $from, string $to, ?int $customerId): Builder
    {
        return DB::table('hire_deposit_applications as a')->join('hires as h', 'h.id', '=', 'a.hire_id')
            ->join('customers as c', 'c.id', '=', 'h.customer_id')->leftJoin('locations as l', 'l.id', '=', 'h.location_id')
            ->whereBetween('a.applied_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('h.location_id', $locationIds))
            ->when($customerId !== null, fn ($query) => $query->where('h.customer_id', $customerId))
            ->select('a.reference', 'c.name as customer', 'h.reference as hire_reference')
            ->selectRaw('NULL as custom_order_reference, NULL as bulk_sale_reference, ? as purpose, ? as method', ['deposit_applied_to_service_charge', 'internal_deposit_application'])
            ->selectRaw('a.amount_minor as gross_minor, 0 as refunded_minor, a.amount_minor as net_minor, a.applied_at as received_at, l.name as location')
            ->orderBy('a.applied_at');
    }

    /** @param list<int>|null $locationIds */
    private function hireBalancesQuery(?array $locationIds, ?int $customerId): Builder
    {
        return DB::table('hires as h')->join('customers as c', 'c.id', '=', 'h.customer_id')->leftJoin('locations as l', 'l.id', '=', 'h.location_id')
            ->whereIn('h.status', ['issued', 'partially_returned', 'returned_pending_inspection', 'completed'])->when($locationIds !== null, fn ($q) => $q->whereIn('h.location_id', $locationIds))
            ->when($customerId !== null, fn ($q) => $q->where('h.customer_id', $customerId))
            ->selectRaw("'hire' as entity_type, h.id as hire_id, h.reference, c.name as customer, COALESCE((SELECT SUM(hi.quantity * hi.unit_rate_minor) FROM hire_items hi WHERE hi.hire_id = h.id), 0) + COALESCE((SELECT SUM(hi.penalty_assessed_minor) FROM hire_items hi WHERE hi.hire_id = h.id), 0) - COALESCE((SELECT SUM(pa.amount_minor) FROM penalty_adjustments pa WHERE pa.hire_id = h.id AND pa.kind = 'waiver'), 0) as billed_minor")
            ->selectRaw("COALESCE((SELECT SUM(p.amount_minor - COALESCE((SELECT SUM(pr.amount_minor) FROM payment_refunds pr WHERE pr.payment_id = p.id), 0)) FROM payments p WHERE p.hire_id = h.id AND p.status = 'received' AND p.purpose IN ('service_charge', 'penalty')), 0) + COALESCE((SELECT SUM(da.amount_minor) FROM hire_deposit_applications da WHERE da.hire_id = h.id), 0) as received_minor")
            ->addSelect('h.due_at', 'l.name as location')->orderBy('h.due_at');
    }

    /** @param list<int>|null $locationIds */
    private function customOrderBalancesQuery(?array $locationIds, ?int $customerId): Builder
    {
        return DB::table('custom_order_invoices as i')->join('custom_orders as co', 'co.id', '=', 'i.custom_order_id')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')->leftJoin('locations as l', 'l.id', '=', 'i.location_id')
            ->where('co.status', '!=', 'cancelled')->when($locationIds !== null, fn ($q) => $q->whereIn('i.location_id', $locationIds))
            ->when($customerId !== null, fn ($q) => $q->where('i.customer_id', $customerId))
            ->selectRaw("'custom_order' as entity_type, co.reference, c.name as customer, i.total_minor as billed_minor")
            ->selectRaw("COALESCE((SELECT SUM(CASE WHEN a.kind = 'refund' THEN -a.amount_minor ELSE a.amount_minor END) FROM custom_order_invoice_allocations a WHERE a.custom_order_invoice_id = i.id), 0) as received_minor")
            ->addSelect('i.due_on as due_at', 'l.name as location')->orderBy('i.due_on')->orderBy('i.id');
    }

    /** @param list<int>|null $locationIds */
    private function expenseQuery(?array $locationIds, string $from, string $to): Builder
    {
        return DB::table('operating_expenses as e')->join('locations as l', 'l.id', '=', 'e.location_id')->leftJoin('users as u', 'u.id', '=', 'e.decided_by')
            ->where('e.status', 'approved')->whereBetween('e.incurred_on', [$from, $to])->when($locationIds !== null, fn ($q) => $q->whereIn('e.location_id', $locationIds))
            ->select('e.reference', 'e.incurred_on', 'l.name as location', 'e.category', 'e.vendor', 'e.receipt_reference', 'e.description', 'e.amount_minor', 'u.name as reviewed_by')->orderBy('e.incurred_on');
    }

    /** @param list<int>|null $locationIds */
    private function cashBasisActivityQuery(?array $locationIds, string $from, string $to): Builder
    {
        $refunds = DB::table('payment_refunds')->selectRaw('payment_id, SUM(amount_minor) as refunded_minor')->groupBy('payment_id');
        $charges = DB::table('payments as p')->join('customers as c', 'c.id', '=', 'p.customer_id')->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
            ->leftJoinSub($refunds, 'r', 'r.payment_id', '=', 'p.id')
            ->where('p.status', 'received')->whereIn('p.purpose', ['service_charge', 'penalty', 'custom_order_charge', 'product_sale'])
            ->where('p.received_at', '>=', $from.' 00:00:00')->where('p.received_at', '<=', $to.' 23:59:59')
            ->when($locationIds !== null, fn ($q) => $q->whereIn('p.location_id', $locationIds))
            ->selectRaw('p.received_at as entry_date, p.purpose as entry_type, p.reference, c.name as counterparty, l.name as location, p.amount_minor as charges_minor, COALESCE(r.refunded_minor, 0) as refunds_minor, 0 as expenses_minor, p.amount_minor - COALESCE(r.refunded_minor, 0) as net_minor');
        $expenses = DB::table('operating_expenses as e')->join('locations as l', 'l.id', '=', 'e.location_id')
            ->where('e.status', 'approved')->whereBetween('e.incurred_on', [$from, $to])
            ->when($locationIds !== null, fn ($q) => $q->whereIn('e.location_id', $locationIds))
            ->selectRaw("e.incurred_on as entry_date, 'approved_expense' as entry_type, e.reference, COALESCE(e.vendor, e.category) as counterparty, l.name as location, 0 as charges_minor, 0 as refunds_minor, e.amount_minor as expenses_minor, 0 - e.amount_minor as net_minor");

        return $charges->unionAll($expenses)->orderBy('entry_date');
    }

    /** @param list<int>|null $locationIds */
    private function vendorPayablesQuery(?array $locationIds, string $from, string $asOf): Builder
    {
        $payments = DB::table('vendor_bill_payments')->select('vendor_bill_id')
            ->selectRaw('SUM(amount_minor) as paid_minor')->whereDate('paid_on', '<=', $asOf)->groupBy('vendor_bill_id');

        return DB::table('vendor_bills as vb')->join('locations as l', 'l.id', '=', 'vb.location_id')
            ->leftJoinSub($payments, 'paid', 'paid.vendor_bill_id', '=', 'vb.id')
            ->whereDate('vb.invoice_date', '<=', $asOf)
            ->where(fn ($query) => $query->whereDate('vb.invoice_date', '>=', $from)->orWhereRaw('vb.amount_minor > COALESCE(paid.paid_minor, 0)'))
            ->when($locationIds !== null, fn ($query) => $query->whereIn('vb.location_id', $locationIds))
            ->select('vb.vendor_name as vendor', 'vb.invoice_number', 'vb.reference as bill_reference', 'vb.invoice_date', 'vb.due_date', 'l.name as location', 'vb.amount_minor')
            ->selectRaw('COALESCE(paid.paid_minor, 0) as paid_minor')
            ->selectRaw('vb.amount_minor - COALESCE(paid.paid_minor, 0) as balance_minor')
            ->selectRaw("CASE WHEN vb.amount_minor <= COALESCE(paid.paid_minor, 0) THEN 'Paid' WHEN COALESCE(paid.paid_minor, 0) > 0 THEN 'Partially paid' ELSE 'Unpaid' END as payment_state")
            ->orderBy('vb.due_date')->orderBy('vb.vendor_name')->orderBy('vb.id');
    }

    /** @param list<int>|null $locationIds */
    private function journaledIncomeStatementQuery(?array $locationIds, string $from, string $to): Builder
    {
        return DB::table('chart_accounts as ca')->join('financial_journal_lines as jl', 'jl.chart_account_id', '=', 'ca.id')
            ->join('financial_journal_entries as je', 'je.id', '=', 'jl.financial_journal_entry_id')
            ->whereIn('ca.account_type', ['revenue', 'expense'])->whereBetween('je.occurred_on', [$from, $to])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('jl.location_id', $locationIds))
            ->select('ca.account_type', 'ca.code as account_code', 'ca.name as account_name')
            ->selectRaw('SUM(jl.debit_minor) as debit_minor, SUM(jl.credit_minor) as credit_minor')
            ->selectRaw("CASE WHEN ca.account_type = 'revenue' THEN SUM(jl.credit_minor - jl.debit_minor) ELSE SUM(jl.debit_minor - jl.credit_minor) END as net_minor")
            ->groupBy('ca.account_type', 'ca.code', 'ca.name')->orderBy('ca.account_type')->orderBy('ca.code');
    }

    /** @param list<int>|null $locationIds */
    private function taxDetailQuery(?array $locationIds, string $from, string $to): Builder
    {
        $hire = DB::table('hire_invoice_lines as il')->join('hire_invoices as i', 'i.id', '=', 'il.hire_invoice_id')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')->join('locations as l', 'l.id', '=', 'i.location_id')
            ->whereBetween('i.issued_on', [$from, $to])->whereNotNull('i.tax_configuration_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('i.location_id', $locationIds))
            ->selectRaw("i.issued_on as invoice_date, 'Hire' as invoice_type, i.number as invoice_number, c.name as customer, l.name as location, il.tax_code, il.tax_treatment as treatment, il.tax_rate as rate_percent, il.net_minor as taxable_value_minor, il.tax_minor, il.line_total_minor")
            ->where('il.tax_minor', '>', 0);
        $orders = DB::table('custom_order_invoice_lines as il')->join('custom_order_invoices as i', 'i.id', '=', 'il.custom_order_invoice_id')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')->join('locations as l', 'l.id', '=', 'i.location_id')
            ->whereBetween('i.issued_on', [$from, $to])->whereNotNull('i.tax_configuration_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('i.location_id', $locationIds))
            ->selectRaw("i.issued_on as invoice_date, 'Custom order' as invoice_type, i.number as invoice_number, c.name as customer, l.name as location, il.tax_code, il.tax_treatment as treatment, il.tax_rate as rate_percent, il.net_minor as taxable_value_minor, il.tax_minor, il.line_total_minor")
            ->where('il.tax_minor', '>', 0);

        $credits = DB::table('credit_note_lines as cl')->join('credit_notes as cn', 'cn.id', '=', 'cl.credit_note_id')
            ->join('customers as c', 'c.id', '=', 'cn.customer_id')->join('locations as l', 'l.id', '=', 'cn.location_id')
            ->whereBetween('cn.issued_on', [$from, $to])->where('cl.tax_minor', '>', 0)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('cn.location_id', $locationIds))
            ->selectRaw("cn.issued_on as invoice_date, CONCAT('Credit note (', cn.invoice_type, ')') as invoice_type, cn.number as invoice_number, c.name as customer, l.name as location, cl.tax_code, cl.tax_treatment as treatment, cl.tax_rate as rate_percent, -cl.net_minor as taxable_value_minor, -cl.tax_minor as tax_minor, -cl.total_minor as line_total_minor");

        return DB::query()->fromSub($hire->unionAll($orders)->unionAll($credits), 'tax_rows')->orderBy('invoice_date')->orderBy('invoice_number');
    }

    /** @param list<int>|null $locationIds */
    private function lossQuery(?array $locationIds): Builder
    {
        return DB::table('physical_assets as a')->join('products as p', 'p.id', '=', 'a.product_id')->leftJoin('locations as cur', 'cur.id', '=', 'a.current_location_id')
            ->leftJoin('locations as home', 'home.id', '=', 'a.home_location_id')->whereIn('a.status', ['missing', 'damaged', 'under_repair'])
            ->when($locationIds !== null, fn ($q) => $q->where(fn ($inner) => $inner->whereIn('a.current_location_id', $locationIds)->orWhereIn('a.home_location_id', $locationIds)))
            ->select('a.asset_code', 'p.name as product', 'a.status', 'a.condition', 'cur.name as current_location', 'home.name as home_location', 'a.last_verified_at', 'a.updated_at')->orderBy('a.status')->orderBy('a.asset_code');
    }

    /** @param list<int>|null $locationIds */
    private function leadQuery(?array $locationIds, string $from, string $to, ?int $customerId): Builder
    {
        return DB::table('leads as ld')->leftJoin('institutions as i', 'i.id', '=', 'ld.institution_id')->leftJoin('users as u', 'u.id', '=', 'ld.owner_id')
            ->leftJoinSub(DB::table('quotations')->selectRaw('lead_id, COUNT(*) as quote_count, MAX(updated_at) as latest_updated')->groupBy('lead_id'), 'q', 'q.lead_id', '=', 'ld.id')
            ->leftJoin('quotations as latest_q', function ($join): void {
                $join->on('latest_q.lead_id', '=', 'ld.id')->on('latest_q.updated_at', '=', 'q.latest_updated');
            })
            ->where('ld.created_at', '>=', $from.' 00:00:00')->where('ld.created_at', '<=', $to.' 23:59:59')->when($locationIds !== null, fn ($query) => $query->whereIn('ld.location_id', $locationIds))
            ->when($customerId !== null, fn ($query) => $query->where('ld.customer_id', $customerId))
            ->select('ld.reference', 'ld.contact_name', 'i.name as institution', 'u.name as owner', 'ld.stage', 'ld.source', 'ld.estimated_value_minor', 'ld.probability', 'ld.follow_up_at')
            ->selectRaw('COALESCE(q.quote_count, 0) as quote_count')->addSelect('latest_q.status as latest_quote_status', 'latest_q.total_minor as latest_quote_total_minor')->orderBy('ld.created_at');
    }

    /** @param list<int>|null $locationIds */
    private function customOrderQuery(?array $locationIds, string $from, string $to, ?int $customerId): Builder
    {
        return DB::table('custom_orders as co')->join('customers as c', 'c.id', '=', 'co.customer_id')->leftJoin('institutions as i', 'i.id', '=', 'co.institution_id')->join('locations as l', 'l.id', '=', 'co.location_id')
            ->whereBetween('co.order_date', [$from, $to])->when($locationIds !== null, fn ($q) => $q->whereIn('co.location_id', $locationIds))
            ->when($customerId !== null, fn ($q) => $q->where('co.customer_id', $customerId))
            ->select('co.reference', 'c.name as customer', 'i.name as institution', 'co.status', 'co.item_type', 'co.quantity', 'co.agreed_total_minor', 'co.deposit_required_minor', 'co.order_date', 'co.expected_completion_date', 'co.delivery_method', 'co.tracking_number', 'l.name as location')->orderBy('co.order_date');
    }

    /** @param list<int>|null $locationIds */
    private function customOrderMarginQuery(?array $locationIds, string $from, string $to, ?int $customerId): Builder
    {
        $costs = DB::table('custom_order_costs')->select('custom_order_id')->selectRaw('SUM(amount_minor) as direct_cost_minor')
            ->whereDate('incurred_on', '<=', $to)->groupBy('custom_order_id');

        return DB::table('custom_orders as co')->join('customers as c', 'c.id', '=', 'co.customer_id')->join('locations as l', 'l.id', '=', 'co.location_id')
            ->leftJoinSub($costs, 'costs', 'costs.custom_order_id', '=', 'co.id')
            ->whereBetween('co.order_date', [$from, $to])->when($locationIds !== null, fn ($query) => $query->whereIn('co.location_id', $locationIds))
            ->when($customerId !== null, fn ($query) => $query->where('co.customer_id', $customerId))
            ->select('co.reference', 'c.name as customer', 'co.status', 'co.item_type', 'co.order_date', 'co.expected_completion_date as due_date', 'l.name as location', 'co.agreed_total_minor')
            ->selectRaw('COALESCE(costs.direct_cost_minor, 0) as direct_cost_minor')
            ->orderBy('co.order_date')->orderBy('co.id');
    }

    /** @param list<int>|null $locationIds */
    private function auditQuery(?array $locationIds, string $from, string $to): Builder
    {
        return DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('locations as l', 'l.id', '=', 'a.location_id')
            ->where('a.occurred_at', '>=', $from.' 00:00:00')->where('a.occurred_at', '<=', $to.' 23:59:59')->when($locationIds !== null, fn ($q) => $q->whereIn('a.location_id', $locationIds))
            ->select('a.occurred_at', 'u.name as staff_member', 'a.action', 'a.subject_type', 'a.subject_id', 'l.name as location', 'a.reason')->orderBy('a.occurred_at');
    }

    private function safeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }
}
