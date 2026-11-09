<?php

namespace App\Http\Controllers;

use App\Models\BulkStockBalance;
use App\Models\Customer;
use App\Models\HireItem;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\StockTransfer;
use App\Models\User;
use App\Reports\FinancialReconciliation;
use App\Reports\OperationalReportCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    private const REPORTS = [
        ['slug' => 'available-inventory', 'title' => 'Available inventory', 'description' => 'Serialized items by product, size, condition and location.', 'icon' => 'boxes'],
        ['slug' => 'bulk-stock', 'title' => 'Bulk stock balances', 'description' => 'On-hand, reserved, available and reorder-level quantities by branch.', 'icon' => 'boxes'],
        ['slug' => 'inventory-valuation', 'title' => 'Inventory valuation', 'description' => 'Current bulk inventory value and moving average cost by product and branch.', 'icon' => 'coins'],
        ['slug' => 'cost-of-goods-sold', 'title' => 'Cost of goods sold', 'description' => 'Bulk product sale costs and returned sale costs posted in the selected period.', 'icon' => 'receipt'],
        ['slug' => 'bulk-movements', 'title' => 'Bulk stock movements', 'description' => 'Receipts, manager-authorized adjustments and branch transfers in the selected period.', 'icon' => 'package'],
        ['slug' => 'hired-out', 'title' => 'Gowns hired out', 'description' => 'Current customer custody and expected return records.', 'icon' => 'calendar'],
        ['slug' => 'overdue-returns', 'title' => 'Overdue returns', 'description' => 'Open hire items past their due date, with assessed fees.', 'icon' => 'alert'],
        ['slug' => 'customer-hiring-history', 'title' => 'Customer hiring history', 'description' => 'Hire item history and service rates by issue date.', 'icon' => 'users'],
        ['slug' => 'transfers-custody', 'title' => 'Transfers and custody', 'description' => 'Inter-location transfers and their dispatch and receipt status.', 'icon' => 'map'],
        ['slug' => 'sales-revenue', 'title' => 'Sales and receipts', 'description' => 'Received payments less recorded refunds.', 'icon' => 'trending'],
        ['slug' => 'outstanding-balances', 'title' => 'Outstanding balances', 'description' => 'Invoiced hire and custom order receivables after receipts, refunds and applied deposits.', 'icon' => 'coins'],
        ['slug' => 'approved-expenses', 'title' => 'Approved expenses', 'description' => 'Approved operating expenses in the selected period.', 'icon' => 'receipt'],
        ['slug' => 'cash-basis-activity', 'title' => 'Cash-basis period activity', 'description' => 'Collected charges, refunds and approved expenses. Excludes deposits, inventory costs and depreciation.', 'icon' => 'chart'],
        ['slug' => 'financial-reconciliation', 'title' => 'Financial reconciliation summary', 'description' => 'Period activity and depreciation, plus current receivable, vendor payable, deposit and payment exception controls.', 'icon' => 'chart'],
        ['slug' => 'vendor-payables', 'title' => 'Vendor payable aging and statements', 'description' => 'Outstanding invoices carried forward plus invoices in the selected period, paid-to-date, balances, settlement state and aging as of period end.', 'icon' => 'receipt'],
        ['slug' => 'journaled-income-statement', 'title' => 'Journaled income statement (partial)', 'description' => 'Period revenue and expense totals from posted journal lines only. Cash-basis revenue and unposted/statutory items are excluded.', 'icon' => 'chart'],
        ['slug' => 'tax-detail', 'title' => 'Tax detail by invoice', 'description' => 'Taxable invoice lines with the saved tax code, rate, taxable value, tax amount and invoice total.', 'icon' => 'receipt'],
        ['slug' => 'loss-damage-repair', 'title' => 'Loss, damage and repair', 'description' => 'Serialized stock requiring attention.', 'icon' => 'package'],
        ['slug' => 'leads-quotations', 'title' => 'Leads and quotations', 'description' => 'Lead stages, follow-ups and latest quotation values.', 'icon' => 'chart'],
        ['slug' => 'custom-orders', 'title' => 'Custom orders', 'description' => 'Production and delivery commitments by order date.', 'icon' => 'clipboard'],
        ['slug' => 'custom-order-margin', 'title' => 'Custom order contribution', 'description' => 'Order revenue less directly tracked costs, grouped by order date with costs through the selected period end.', 'icon' => 'chart'],
        ['slug' => 'staff-activity', 'title' => 'Staff activity', 'description' => 'Audited actions performed during the selected period.', 'icon' => 'activity'],
    ];

    public function index(Request $request, FinancialReconciliation $reconciliation): Response
    {
        $user = $request->user();
        $accessibleLocationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $dates = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);
        $locationIds = $this->locationScope($user, $dates['location_id'] ?? null);
        $customers = Customer::query()
            ->when($locationIds !== null, fn ($query) => $query->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds)))
            ->orderBy('name')->get(['id', 'name', 'customer_number'])
            ->map(fn (Customer $customer): array => ['id' => $customer->id, 'name' => $customer->name, 'number' => $customer->customer_number]);
        $customerId = isset($dates['customer_id']) ? (int) $dates['customer_id'] : null;
        abort_unless($customerId === null || $customers->contains('id', $customerId), 403);
        $dateFrom = $dates['start_date'] ?? $today->startOfMonth()->toDateString();
        $dateTo = $dates['end_date'] ?? $today->toDateString();
        abort_if(CarbonImmutable::parse($dateFrom)->diffInDays(CarbonImmutable::parse($dateTo)) > 3660, 422, 'Report date range cannot exceed ten years.');
        $assets = PhysicalAsset::query()->when($locationIds !== null, fn ($query) => $query->where(function ($assets) use ($locationIds): void {
            $assets->whereIn('current_location_id', $locationIds)
                ->orWhereHas('hireItems', fn ($items) => $items->where('status', 'issued')->whereHas('hire', fn ($hires) => $hires->whereIn('location_id', $locationIds)))
                ->orWhereHas('transfer', fn ($transfers) => $transfers->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds));
        }));
        $stockByStatus = (clone $assets)->selectRaw('status, COUNT(*) as total')->groupBy('status')->orderBy('status')->get();
        $overdueQuantity = HireItem::query()->join('hires', 'hires.id', '=', 'hire_items.hire_id')
            ->whereIn('hire_items.status', ['issued', 'partially_returned'])->where('hires.due_at', '<', $today)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('hires.location_id', $locationIds))
            ->sum(DB::raw('hire_items.quantity - hire_items.returned_quantity'));
        $bulkOnHand = (int) BulkStockBalance::query()->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('on_hand');
        $bulkAvailable = (int) BulkStockBalance::query()->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->selectRaw('COALESCE(SUM(on_hand - reserved - pending_inspection - damaged), 0) as aggregate')->value('aggregate');
        $bulkHired = (int) HireItem::query()->join('hires', 'hires.id', '=', 'hire_items.hire_id')->whereNull('hire_items.physical_asset_id')
            ->whereIn('hire_items.status', ['issued', 'partially_returned'])->when($locationIds !== null, fn ($query) => $query->whereIn('hires.location_id', $locationIds))
            ->sum(DB::raw('hire_items.quantity - hire_items.returned_quantity'));
        $canSeeFinance = $user->hasPermission('reports.finance');
        $financeReports = ['sales-revenue', 'outstanding-balances', 'approved-expenses', 'cash-basis-activity', 'financial-reconciliation', 'vendor-payables', 'journaled-income-statement', 'tax-detail', 'custom-order-margin', 'inventory-valuation', 'cost-of-goods-sold'];
        $crmReports = ['leads-quotations', 'custom-orders'];
        $reportCatalog = collect(self::REPORTS)->reject(function (array $report) use ($user, $financeReports, $crmReports): bool {
            return (in_array($report['slug'], $financeReports, true) && ! $user->hasPermission('reports.finance'))
                || (in_array($report['slug'], $crmReports, true) && ! $user->hasPermission('reports.crm'))
                || ($report['slug'] === 'staff-activity' && ! in_array($user->role, ['super_admin', 'management'], true));
        })->values();

        return Inertia::render('Reports/Index', [
            'stockByStatus' => $stockByStatus,
            'metrics' => [
                'assets' => (clone $assets)->count(),
                'available' => (clone $assets)->where('status', 'available')->count() + $bulkAvailable,
                'hired' => (clone $assets)->where('status', 'hired')->count() + $bulkHired,
                'overdue' => (int) $overdueQuantity,
                'inTransit' => (clone $assets)->where('status', 'in_transit')->count(),
                'openTransfers' => StockTransfer::query()->whereIn('status', ['draft', 'approved', 'in_transit', 'partially_received'])
                    ->when($locationIds !== null, fn ($query) => $query->where(fn ($transfers) => $transfers->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds)))->count(),
                'bulkOnHand' => $bulkOnHand,
            ],
            'locations' => Location::query()->where('is_active', true)
                ->when($accessibleLocationIds !== null, fn ($query) => $query->whereIn('id', $accessibleLocationIds))->get(['id', 'name', 'type']),
            'customers' => $customers,
            'reportCatalog' => $reportCatalog,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'locationId' => isset($dates['location_id']) ? (int) $dates['location_id'] : null,
            'customerId' => $customerId,
            'financialReconciliation' => $canSeeFinance ? $reconciliation->summarize($dateFrom, $dateTo, $locationIds) : null,
        ]);
    }

    public function export(Request $request, string $report, OperationalReportCsv $csv, FinancialReconciliation $reconciliation): HttpResponse|StreamedResponse
    {
        abort_unless(collect(self::REPORTS)->contains('slug', $report), 404);

        $user = $request->user();
        $financeReports = ['sales-revenue', 'outstanding-balances', 'approved-expenses', 'cash-basis-activity', 'financial-reconciliation', 'vendor-payables', 'journaled-income-statement', 'tax-detail', 'custom-order-margin', 'inventory-valuation', 'cost-of-goods-sold'];
        $crmReports = ['leads-quotations', 'custom-orders'];
        abort_if(in_array($report, $financeReports, true) && ! $user->hasPermission('reports.finance'), 403);
        abort_if(in_array($report, $crmReports, true) && ! $user->hasPermission('reports.crm'), 403);
        abort_if($report === 'staff-activity' && ! in_array($user->role, ['super_admin', 'management'], true), 403);

        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'format' => ['nullable', 'in:csv,pdf'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);
        abort_if(CarbonImmutable::parse($validated['start_date'])->diffInDays(CarbonImmutable::parse($validated['end_date'])) > 3660, 422, 'Report date range cannot exceed ten years.');

        $locationIds = $this->locationScope($user, $validated['location_id'] ?? null);
        $customerId = isset($validated['customer_id']) ? (int) $validated['customer_id'] : null;
        abort_unless($customerId === null || Customer::query()->whereKey($customerId)
            ->when($locationIds !== null, fn ($query) => $query->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds)))
            ->exists(), 403);
        if (($validated['format'] ?? 'csv') === 'pdf') {
            $stream = fopen('php://temp/maxmemory:1048576', 'w+');
            if ($report === 'financial-reconciliation') {
                $summary = $reconciliation->summarize($validated['start_date'], $validated['end_date'], $locationIds);
                fputcsv($stream, ['Financial reconciliation summary', 'KES minor units'], ',', '"', '');
                fputcsv($stream, ['Period start', $validated['start_date']], ',', '"', '');
                fputcsv($stream, ['Period end', $validated['end_date']], ',', '"', '');
                foreach ($summary as $metric => $value) {
                    fputcsv($stream, [ucwords(str_replace('_', ' ', $metric)), $value], ',', '"', '');
                }
                fputcsv($stream, ['Basis', 'Charge receipts are cash-basis; approved expenses use incurred date. Depreciation is listed separately. The management result excludes bulk COGS, inventory purchases and statutory accruals.'], ',', '"', '');
                fputcsv($stream, ['Exclusions', 'Tax, statutory accruals and unbilled charges.'], ',', '"', '');
            } else {
                $csv->write($stream, $report, $validated['start_date'], $validated['end_date'], $locationIds, $customerId);
            }
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rows[] = array_map(static fn (mixed $cell): string => (string) ($cell ?? ''), $row);
                if (count($rows) > 5001) {
                    fclose($stream);
                    abort(422, 'PDF exports are limited to 5,000 data rows. Use CSV for larger reports.');
                }
            }
            fclose($stream);

            return Pdf::loadView('reports.pdf', [
                'title' => collect(self::REPORTS)->firstWhere('slug', $report)['title'],
                'periodStart' => $validated['start_date'],
                'periodEnd' => $validated['end_date'],
                'rows' => $rows,
                'generatedAt' => CarbonImmutable::now(config('app.timezone'))->format('d M Y H:i'),
                'currencyNote' => in_array($report, ['financial-reconciliation', 'vendor-payables', 'journaled-income-statement', 'tax-detail', 'custom-order-margin'], true) ? 'KES amounts are shown in minor units (cents).' : null,
                'scopeNote' => $locationIds === null ? 'All locations accessible to this role.' : 'Limited to the locations assigned to this account.',
            ])->setPaper('a4', 'landscape')->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
            ])->download('gownsea-'.$report.'-'.$validated['start_date'].'-to-'.$validated['end_date'].'.pdf');
        }

        $filename = 'gownsea-'.$report.'-'.$validated['start_date'].'-to-'.$validated['end_date'].'.csv';

        if ($report === 'financial-reconciliation') {
            $summary = $reconciliation->summarize($validated['start_date'], $validated['end_date'], $locationIds);

            return response()->streamDownload(function () use ($summary, $validated): void {
                $stream = fopen('php://output', 'w');
                fputcsv($stream, ['Financial reconciliation summary', 'KES minor units'], ',', '"', '');
                fputcsv($stream, ['Period start', $validated['start_date']], ',', '"', '');
                fputcsv($stream, ['Period end', $validated['end_date']], ',', '"', '');
                foreach ($summary as $metric => $value) {
                    fputcsv($stream, [ucwords(str_replace('_', ' ', $metric)), $value], ',', '"', '');
                }
                fputcsv($stream, ['Basis', 'Charge receipts are cash-basis; approved expenses use incurred date. Depreciation is listed separately. The management result excludes bulk COGS, inventory purchases and statutory accruals.'], ',', '"', '');
                fputcsv($stream, ['Exclusions', 'Tax, statutory accruals and unbilled charges.'], ',', '"', '');
                fclose($stream);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($csv, $report, $validated, $locationIds, $customerId): void {
            $csv->write(fopen('php://output', 'w'), $report, $validated['start_date'], $validated['end_date'], $locationIds, $customerId);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<int>|null */
    private function locationScope(User $user, ?int $selectedLocationId): ?array
    {
        $assignedLocationIds = $user->mayViewAllLocations()
            ? null
            : $user->locations()->pluck('locations.id')->map(fn ($id): int => (int) $id)->all();

        if ($selectedLocationId === null) {
            return $assignedLocationIds;
        }

        abort_unless($assignedLocationIds === null || in_array($selectedLocationId, $assignedLocationIds, true), 403);

        return [$selectedLocationId];
    }
}
