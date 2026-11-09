<?php

namespace App\Reports;

use App\Models\BulkStockBalance;
use App\Models\CreditNote;
use App\Models\CustomOrderCost;
use App\Models\CustomOrderDepositApplication;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\MpesaStkPush;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PenaltyAdjustment;
use App\Models\VendorBill;
use App\Services\HirePenaltyBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialReconciliation
{
    public function __construct(private readonly HirePenaltyBalance $penaltyBalance) {}

    /** @param list<int>|null $locationIds @return array<string, int> */
    public function summarize(string $from, string $to, ?array $locationIds): array
    {
        $chargePurposes = ['service_charge', 'penalty', 'custom_order_charge', 'product_sale'];
        $received = (int) Payment::query()->where('status', 'received')->whereIn('purpose', $chargePurposes)
            ->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor');
        $refunded = (int) PaymentRefund::query()->whereBetween('refunded_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereHas('payment', fn ($query) => $query->whereIn('purpose', $chargePurposes)->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $hireApplications = (int) HireDepositApplication::query()->whereBetween('applied_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereHas('hire', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $orderApplications = (int) CustomOrderDepositApplication::query()->whereBetween('applied_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereHas('order', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $directCosts = (int) CustomOrderCost::query()->whereDate('incurred_on', '>=', $from)->whereDate('incurred_on', '<=', $to)
            ->whereHas('order', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $expenses = OperatingExpense::query()->where('status', 'approved')->whereDate('incurred_on', '>=', $from)->whereDate('incurred_on', '<=', $to)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds));
        $approvedExpenses = (int) (clone $expenses)->sum('amount_minor');
        $paidExpenses = (int) OperatingExpense::query()->where('status', 'approved')->where('payment_status', 'paid')->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor');
        $unpaidApprovedExpenses = (int) OperatingExpense::query()->where('status', 'approved')->where('payment_status', 'unpaid')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor');
        $assetDepreciation = (int) DB::table('financial_journal_lines as jl')->join('financial_journal_entries as je', 'je.id', '=', 'jl.financial_journal_entry_id')
            ->join('chart_accounts as ca', 'ca.id', '=', 'jl.chart_account_id')->where('ca.code', '6400')
            ->whereDate('je.occurred_on', '>=', $from)->whereDate('je.occurred_on', '<=', $to)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('jl.location_id', $locationIds))
            ->selectRaw('COALESCE(SUM(jl.debit_minor - jl.credit_minor), 0) as amount_minor')->value('amount_minor');
        $hireTax = (int) DB::table('hire_invoices')->whereBetween('issued_on', [$from, $to])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('tax_minor');
        $orderTax = (int) DB::table('custom_order_invoices')->whereBetween('issued_on', [$from, $to])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('tax_minor');
        $creditTax = (int) CreditNote::query()->whereBetween('issued_on', [$from, $to])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('tax_minor');
        $bills = VendorBill::query()->withSum('payments', 'amount_minor')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds));
        $vendorPayables = (int) (clone $bills)->get()->sum(fn (VendorBill $bill): int => max(0, $bill->amount_minor - (int) $bill->payments_sum_amount_minor));
        $overdueVendorPayables = (int) (clone $bills)->whereDate('due_date', '<', now()->toDateString())->get()
            ->sum(fn (VendorBill $bill): int => max(0, $bill->amount_minor - (int) $bill->payments_sum_amount_minor));
        $applications = $hireApplications + $orderApplications;
        $netRecognizedRevenue = $received - $refunded + $applications;
        $bulkSalesRevenue = (int) DB::table('financial_journal_lines as jl')
            ->join('financial_journal_entries as je', 'je.id', '=', 'jl.financial_journal_entry_id')
            ->join('chart_accounts as ca', 'ca.id', '=', 'jl.chart_account_id')->where('ca.code', '4030')
            ->whereDate('je.occurred_on', '>=', $from)->whereDate('je.occurred_on', '<=', $to)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('jl.location_id', $locationIds))
            ->selectRaw('COALESCE(SUM(jl.credit_minor - jl.debit_minor), 0) as amount_minor')->value('amount_minor');
        $bulkCostOfGoodsSold = (int) DB::table('financial_journal_lines as jl')
            ->join('financial_journal_entries as je', 'je.id', '=', 'jl.financial_journal_entry_id')
            ->join('chart_accounts as ca', 'ca.id', '=', 'jl.chart_account_id')->where('ca.code', '5000')
            ->whereDate('je.occurred_on', '>=', $from)->whereDate('je.occurred_on', '<=', $to)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('jl.location_id', $locationIds))
            ->selectRaw('COALESCE(SUM(jl.debit_minor - jl.credit_minor), 0) as amount_minor')->value('amount_minor');
        $bulkInventoryValue = (int) BulkStockBalance::query()
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('inventory_value_minor');

        $hireStatus = ['issued', 'partially_returned', 'returned_pending_inspection', 'completed'];
        $hireBilled = (int) DB::table('hire_items as hi')->join('hires as h', 'h.id', '=', 'hi.hire_id')
            ->whereIn('h.status', $hireStatus)->when($locationIds !== null, fn ($query) => $query->whereIn('h.location_id', $locationIds))
            ->sum(DB::raw('hi.quantity * hi.unit_rate_minor'));
        $assessedPenalties = (int) DB::table('hire_items as hi')->join('hires as h', 'h.id', '=', 'hi.hire_id')
            ->whereIn('h.status', $hireStatus)->when($locationIds !== null, fn ($query) => $query->whereIn('h.location_id', $locationIds))->sum('hi.penalty_assessed_minor');
        $waivedPenalties = (int) PenaltyAdjustment::query()->where('kind', 'waiver')
            ->whereHas('hire', fn ($query) => $query->whereIn('status', $hireStatus)->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $hirePayments = (int) DB::table('payments as p')->leftJoinSub(
            DB::table('payment_refunds')->selectRaw('payment_id, SUM(amount_minor) as refunded_minor')->groupBy('payment_id'),
            'r', 'r.payment_id', '=', 'p.id',
        )->join('hires as h', 'h.id', '=', 'p.hire_id')->where('p.status', 'received')->whereIn('p.purpose', ['service_charge', 'penalty'])
            ->whereIn('h.status', $hireStatus)->when($locationIds !== null, fn ($query) => $query->whereIn('h.location_id', $locationIds))
            ->sum(DB::raw('p.amount_minor - COALESCE(r.refunded_minor, 0)'));
        $hireApplicationsAll = (int) HireDepositApplication::query()->whereHas('hire', fn ($query) => $query->whereIn('status', $hireStatus)->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $activeHireIds = Hire::query()->whereIn('status', ['issued', 'partially_returned'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->pluck('id');
        $activePenaltyAccrual = (int) $this->penaltyBalance->accruedByHireIds($activeHireIds, Carbon::parse($to)->endOfDay())->sum();
        $hireReceivable = max(0, $hireBilled + $assessedPenalties + $activePenaltyAccrual - $waivedPenalties - $hirePayments - $hireApplicationsAll);

        $customInvoiceScope = DB::table('custom_order_invoices as coi')->when($locationIds !== null, fn ($query) => $query->whereIn('coi.location_id', $locationIds));
        $orderBilled = (int) (clone $customInvoiceScope)->sum('coi.total_minor');
        $orderAllocated = (int) DB::table('custom_order_invoice_allocations as a')
            ->join('custom_order_invoices as coi', 'coi.id', '=', 'a.custom_order_invoice_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('coi.location_id', $locationIds))
            ->sum(DB::raw("CASE WHEN a.kind = 'refund' THEN -a.amount_minor ELSE a.amount_minor END"));
        $orderReceivable = max(0, $orderBilled - $orderAllocated);
        $depositReceipts = (int) Payment::query()->where('status', 'received')->whereIn('purpose', ['deposit', 'custom_order_deposit'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor');
        $depositRefunds = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->whereIn('purpose', ['deposit', 'custom_order_deposit'])->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $orderApplicationsAll = (int) CustomOrderDepositApplication::query()->whereHas('order', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $allDepositApplications = $hireApplicationsAll + $orderApplicationsAll;

        // Keep company balances separate from period activity; they are point-in-time control totals.
        $unresolvedPushes = MpesaStkPush::query()->whereIn('status', ['pending', 'manual_review'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds));

        return [
            'receivedCharges' => $received,
            'refunds' => $refunded,
            'depositApplications' => $applications,
            'netRecognizedRevenue' => $netRecognizedRevenue,
            'bulkSalesRevenue' => $bulkSalesRevenue,
            'bulkCostOfGoodsSold' => $bulkCostOfGoodsSold,
            'bulkSalesGrossMargin' => $bulkSalesRevenue - $bulkCostOfGoodsSold,
            'bulkInventoryValue' => $bulkInventoryValue,
            'directOrderCosts' => $directCosts,
            'grossContribution' => $netRecognizedRevenue - $directCosts,
            'approvedExpenses' => $approvedExpenses,
            'paidExpenses' => $paidExpenses,
            'assetDepreciation' => $assetDepreciation,
            'outputTaxInvoiced' => $hireTax + $orderTax - $creditTax,
            'vendorPayables' => $vendorPayables,
            'overdueVendorPayables' => $overdueVendorPayables,
            'netManagementResult' => $netRecognizedRevenue - $directCosts - $approvedExpenses,
            'unpaidApprovedExpenses' => $unpaidApprovedExpenses,
            'outstandingReceivables' => $hireReceivable + $orderReceivable,
            'hireReceivable' => $hireReceivable,
            'customOrderReceivable' => $orderReceivable,
            'depositLiability' => max(0, $depositReceipts - $depositRefunds - $allDepositApplications),
            'paymentExceptions' => (int) (clone $unresolvedPushes)->count(),
            'paymentExceptionAmount' => (int) (clone $unresolvedPushes)->sum('amount_minor'),
        ];
    }
}
