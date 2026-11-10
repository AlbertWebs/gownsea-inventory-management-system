<?php

namespace App\Http\Controllers;

use App\Actions\ApplyCustomOrderDeposit;
use App\Actions\ApplyHireDeposit;
use App\Actions\DecideHirePenaltyWaiver;
use App\Actions\DecideOperatingExpense;
use App\Actions\MarkOperatingExpensePaid;
use App\Actions\ReconcileDarajaStkPush;
use App\Actions\RecordCustomOrderPayment;
use App\Actions\RecordHirePayment;
use App\Actions\RefundHirePayment;
use App\Actions\RequestHirePenaltyWaiver;
use App\Actions\ReverseFinancialJournalEntry;
use App\Actions\StartDarajaStkPush;
use App\Actions\SubmitOperatingExpense;
use App\Actions\WaiveHirePenalty;
use App\Http\Requests\DecideOperatingExpenseRequest;
use App\Http\Requests\DecidePenaltyWaiverRequest;
use App\Http\Requests\MarkOperatingExpensePaidRequest;
use App\Http\Requests\StartDarajaStkPushRequest;
use App\Http\Requests\StoreCustomOrderDepositApplicationRequest;
use App\Http\Requests\StoreHireDepositApplicationRequest;
use App\Http\Requests\StoreOperatingExpenseRequest;
use App\Http\Requests\StorePaymentRefundRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StorePenaltyWaiverRequest;
use App\Models\BusinessConfiguration;
use App\Models\ChartAccount;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\FinancialJournalEntry;
use App\Models\FinancialJournalLine;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\HireItem;
use App\Models\Location;
use App\Models\MpesaStkPush;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PenaltyAdjustment;
use App\Models\PenaltyWaiverRequest;
use App\Services\HirePenaltyBalance;
use App\Services\IntegrationSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function ledger(Request $request): Response
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'management', 'finance'], true), 403);
        $allowedLocationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();
        $dates = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'location' => ['nullable', 'integer', 'exists:locations,id'],
        ]);
        $today = CarbonImmutable::now(config('app.timezone'));
        $dateFrom = $dates['start_date'] ?? $today->startOfMonth()->toDateString();
        $dateTo = $dates['end_date'] ?? $today->toDateString();
        abort_if(CarbonImmutable::parse($dateFrom)->diffInDays(CarbonImmutable::parse($dateTo)) > 3660, 422, 'Ledger date range cannot exceed ten years.');
        $selectedLocation = isset($dates['location']) ? (int) $dates['location'] : null;
        abort_if($selectedLocation !== null && $allowedLocationIds !== null && ! in_array($selectedLocation, $allowedLocationIds, true), 403);
        $locationScope = $selectedLocation !== null ? [$selectedLocation] : $allowedLocationIds;

        $entries = FinancialJournalEntry::query()->with(['lines.account:id,code,name,account_type', 'lines.location:id,name', 'reversal:id,reversal_of_id'])
            ->whereDate('occurred_on', '>=', $dateFrom)->whereDate('occurred_on', '<=', $dateTo)
            ->when($locationScope !== null, fn ($query) => $query->where(fn ($entries) => $entries->whereIn('location_id', $locationScope)
                ->orWhereHas('lines', fn ($lines) => $lines->whereIn('location_id', $locationScope))))
            ->latest('occurred_on')->latest('id')->paginate(25)->withQueryString()
            ->through(function (FinancialJournalEntry $entry) use ($locationScope): array {
                $visibleLines = $locationScope === null ? $entry->lines : $entry->lines->whereIn('location_id', $locationScope)->values();

                return [
                    'id' => $entry->id,
                    'reference' => $entry->reference,
                    'sourceType' => class_basename($entry->source_type),
                    'sourceReference' => $entry->source_reference,
                    'sourceId' => $entry->source_id,
                    'entryType' => str_replace('_', ' ', $entry->entry_type),
                    'date' => $entry->occurred_on->toDateString(),
                    'description' => $entry->description,
                    'debitMinor' => $visibleLines->sum('debit_minor'),
                    'creditMinor' => $visibleLines->sum('credit_minor'),
                    'isReversed' => $entry->reversal !== null,
                    'reversalOfId' => $entry->reversal_of_id,
                    'reversalReason' => $entry->reversal_reason,
                    'lines' => $visibleLines->map(fn ($line): array => [
                        'accountCode' => $line->account->code,
                        'accountName' => $line->account->name,
                        'locationName' => $line->location?->name,
                        'debitMinor' => $line->debit_minor,
                        'creditMinor' => $line->credit_minor,
                        'description' => $line->description,
                    ]),
                ];
            });

        $totals = FinancialJournalLine::query()->join('financial_journal_entries as journal_entries', 'journal_entries.id', '=', 'financial_journal_lines.financial_journal_entry_id')
            ->whereDate('journal_entries.occurred_on', '<=', $dateTo)
            ->when($locationScope !== null, fn ($query) => $query->whereIn('financial_journal_lines.location_id', $locationScope))
            ->selectRaw('chart_account_id, SUM(debit_minor) as debit_minor, SUM(credit_minor) as credit_minor')
            ->groupBy('chart_account_id')->get()->keyBy('chart_account_id');
        $accounts = ChartAccount::query()->where('is_active', true)->orderBy('code')->get()->map(function (ChartAccount $account) use ($totals): array {
            $activity = $totals->get($account->id);
            $debits = (int) ($activity?->debit_minor ?? 0);
            $credits = (int) ($activity?->credit_minor ?? 0);

            return [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->account_type,
                'debitMinor' => $debits,
                'creditMinor' => $credits,
                'balanceMinor' => $account->normal_balance === 'debit' ? $debits - $credits : $credits - $debits,
            ];
        });

        return Inertia::render('Finance/Ledger', [
            'entries' => $entries,
            'accounts' => $accounts,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedLocation' => $selectedLocation,
            'canReverse' => in_array($user->role, ['super_admin', 'management', 'finance'], true),
            'locations' => Location::query()->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('id', $allowedLocationIds))->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function reverseJournalEntry(Request $request, FinancialJournalEntry $entry, ReverseFinancialJournalEntry $reverse): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $reversal = $reverse->handle($entry, $data['reason'], $request->user());

        return back()->with('success', "Journal reversal {$reversal->reference} recorded.");
    }

    public function exportReceipts(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Payment::class), 403);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();
        $payments = Payment::query()->with(['customer:id,name', 'hire:id,reference', 'customOrder:id,reference', 'bulkSale:id,reference'])
            ->withSum('refunds as refunded_minor', 'amount_minor')
            ->where('status', 'received')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->orderBy('id');

        return response()->streamDownload(function () use ($payments): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            fputcsv($output, ['Receipt', 'Customer', 'Hire / order reference', 'Received at', 'Method', 'Purpose', 'Gross (KES)', 'Refunded (KES)', 'Net (KES)', 'Currency', 'Provider reference']);
            $grossTotal = 0;
            $refundTotal = 0;
            $netTotal = 0;
            $safe = static function (?string $value): string {
                $value ??= '';

                return preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value;
            };
            $payments->chunkById(500, function ($batch) use ($output, &$grossTotal, &$refundTotal, &$netTotal, $safe): void {
                foreach ($batch as $payment) {
                    $gross = (int) $payment->amount_minor;
                    $refunded = (int) ($payment->refunded_minor ?? 0);
                    $net = $gross - $refunded;
                    $grossTotal += $gross;
                    $refundTotal += $refunded;
                    $netTotal += $net;
                    $relatedReference = $payment->hire?->reference ?? $payment->customOrder?->reference ?? $payment->bulkSale?->reference ?? '';
                    fputcsv($output, [
                        $safe($payment->reference), $safe($payment->customer?->name), $safe($relatedReference),
                        $payment->received_at?->toDateTimeString() ?? '', $safe($payment->method), $safe($payment->purpose),
                        number_format($gross / 100, 2, '.', ''), number_format($refunded / 100, 2, '.', ''),
                        number_format($net / 100, 2, '.', ''), $safe($payment->currency), $safe($payment->provider_reference),
                    ]);
                }
            }, 'id');
            fputcsv($output, ['TOTAL', '', '', '', '', '', number_format($grossTotal / 100, 2, '.', ''), number_format($refundTotal / 100, 2, '.', ''), number_format($netTotal / 100, 2, '.', ''), '', '']);
            fclose($output);
        }, 'gownsea-receipts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadReceipt(Request $request, Payment $payment): StreamedResponse
    {
        return $this->streamPaymentReceipt($request, $payment, false);
    }

    public function downloadThermalReceipt(Request $request, Payment $payment): StreamedResponse
    {
        return $this->streamPaymentReceipt($request, $payment, true);
    }

    private function streamPaymentReceipt(Request $request, Payment $payment, bool $thermal): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Payment::class), 403);
        abort_unless($payment->status === 'received', 404);
        abort_unless($user->mayViewAllLocations() || $user->locations()->whereKey($payment->location_id)->exists(), 404);

        $payment->load(['customer:id,name,phone,email', 'hire:id,reference', 'customOrder:id,reference', 'bulkSale:id,reference']);
        $payment->loadSum('refunds', 'amount_minor');
        $profile = BusinessConfiguration::query()->where('configuration_key', 'business_profile')->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $payment->received_at->toDateString()))
            ->orderByDesc('effective_from')->orderByDesc('id')->first()?->configuration ?? [];
        $location = Location::query()->find($payment->location_id);
        $relatedReference = $payment->hire?->reference ?? $payment->customOrder?->reference ?? $payment->bulkSale?->reference;
        $view = $thermal ? 'payments.thermal-receipt' : 'payments.receipt';
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->loadView($view, [
                'payment' => $payment,
                'businessProfile' => $profile,
                'locationName' => $location?->name,
                'locationAddress' => $location?->address,
                'locationPhone' => $location?->phone,
                'relatedReference' => $relatedReference,
                'refundedMinor' => (int) ($payment->refunds_sum_amount_minor ?? 0),
            ]);
        $pdf->setPaper($thermal ? [0, 0, 226.77, 510] : 'a5', $thermal ? 'portrait' : 'landscape');

        return response()->streamDownload(
            fn (): int|false => print $pdf->output(),
            $payment->reference.($thermal ? '-thermal-receipt.pdf' : '-receipt.pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function index(Request $request, HirePenaltyBalance $penaltyBalance): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Payment::class), 403);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();
        $payments = Payment::query()->with(['customer:id,name', 'hire:id,reference', 'customOrder:id,reference', 'bulkSale:id,reference'])
            ->withSum('refunds as refunded_minor', 'amount_minor')->withSum('hireDepositApplications as hire_deposit_applied_minor', 'amount_minor')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->where('status', 'received')
            ->latest('received_at')->paginate(25)->withQueryString();

        $canRecord = $user->can('create', Payment::class);
        $penaltiesAssessed = (int) HireItem::query()->whereHas('hire', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('penalty_assessed_minor');
        $penaltiesWaived = (int) PenaltyAdjustment::query()->where('kind', 'waiver')->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor');
        $penaltiesReceived = (int) Payment::query()->where('status', 'received')->where('purpose', 'penalty')->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor');
        $penaltiesRefunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('purpose', 'penalty')->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $activeHireIds = Hire::query()->whereIn('status', ['issued', 'partially_returned'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->pluck('id');
        $accruedPenaltiesByHire = $penaltyBalance->accruedByHireIds($activeHireIds);
        $penaltyAccruingToday = (int) $accruedPenaltiesByHire->sum();
        /*
         * Active late fees are assessed from each hire's snapshotted terms and
         * outstanding quantity. They move into penalty_assessed_minor when
         * each unit is returned, so this live balance never double counts.
         */
        $hires = $canRecord ? Hire::query()
            ->with('customer:id,name')
            ->whereIn('status', ['reserved', 'issued', 'partially_returned', 'returned_pending_inspection', 'completed'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->select(['id', 'reference', 'customer_id', 'location_id', 'status', 'deposit_minor'])
            ->withExists('invoice')
            ->selectSub(HireItem::query()->selectRaw('COALESCE(SUM(quantity * unit_rate_minor), 0)')->whereColumn('hire_id', 'hires.id'), 'charge_total_minor')
            ->selectSub(Payment::query()->selectRaw('COALESCE(SUM(amount_minor - COALESCE((SELECT SUM(payment_refunds.amount_minor) FROM payment_refunds WHERE payment_refunds.payment_id = payments.id), 0)), 0)')->whereColumn('hire_id', 'hires.id')->where('status', 'received')->where('purpose', 'service_charge'), 'charge_paid_minor')
            ->selectSub(HireDepositApplication::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('hire_id', 'hires.id'), 'deposit_applied_minor')
            ->selectSub(HireItem::query()->selectRaw('COALESCE(SUM(penalty_assessed_minor), 0)')->whereColumn('hire_id', 'hires.id'), 'penalty_total_minor')
            ->selectSub(PenaltyAdjustment::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('hire_id', 'hires.id')->where('kind', 'waiver'), 'penalty_waived_minor')
            ->selectSub(Payment::query()->selectRaw("COALESCE(SUM(CASE WHEN purpose = 'penalty' THEN amount_minor - COALESCE((SELECT SUM(payment_refunds.amount_minor) FROM payment_refunds WHERE payment_refunds.payment_id = payments.id), 0) ELSE 0 END), 0)")->whereColumn('hire_id', 'hires.id')->where('status', 'received'), 'penalty_paid_minor')
            ->latest('created_at')->limit(100)->get()
            ->map(fn (Hire $hire): array => [
                'id' => $hire->id,
                'reference' => $hire->reference,
                'customer' => $hire->customer->name,
                'status' => $hire->status,
                'hasInvoice' => (bool) $hire->invoice_exists,
                'chargeTotalMinor' => (int) $hire->charge_total_minor,
                'chargePaidMinor' => (int) $hire->charge_paid_minor + (int) $hire->deposit_applied_minor,
                'penaltyTotalMinor' => max(0, (int) $hire->penalty_total_minor + (int) $accruedPenaltiesByHire->get($hire->id, 0) - (int) $hire->penalty_waived_minor),
                'penaltyPaidMinor' => (int) $hire->penalty_paid_minor,
                'depositMinor' => (int) $hire->deposit_minor,
            ]) : collect();

        $customOrders = $canRecord ? CustomOrder::query()->with('customer:id,name')->where('status', '!=', 'cancelled')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->select(['id', 'reference', 'customer_id', 'location_id', 'status', 'agreed_total_minor', 'deposit_required_minor'])
            ->withExists('invoice')
            ->selectSub(Payment::query()->selectRaw('COALESCE(SUM(amount_minor - COALESCE((SELECT SUM(payment_refunds.amount_minor) FROM payment_refunds WHERE payment_refunds.payment_id = payments.id), 0)), 0)')->whereColumn('custom_order_id', 'custom_orders.id')->where('status', 'received')->where('purpose', 'custom_order_charge'), 'charge_paid_minor')
            ->selectSub(CustomOrderDepositApplication::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('custom_order_id', 'custom_orders.id'), 'deposit_applied_minor')
            ->selectSub(Payment::query()->selectRaw('COALESCE(SUM(amount_minor - COALESCE((SELECT SUM(payment_refunds.amount_minor) FROM payment_refunds WHERE payment_refunds.payment_id = payments.id), 0)), 0)')->whereColumn('custom_order_id', 'custom_orders.id')->where('status', 'received')->where('purpose', 'custom_order_deposit'), 'deposit_paid_minor')
            ->latest('created_at')->limit(100)->get()->map(fn (CustomOrder $order): array => [
                'id' => $order->id, 'reference' => $order->reference, 'customer' => $order->customer->name, 'status' => $order->status,
                'hasInvoice' => (bool) $order->invoice_exists,
                'chargeTotalMinor' => $order->agreed_total_minor, 'chargePaidMinor' => (int) $order->charge_paid_minor + (int) $order->deposit_applied_minor,
                'depositRequiredMinor' => $order->deposit_required_minor, 'depositPaidMinor' => (int) $order->deposit_paid_minor,
            ]) : collect();

        $customOrderDeposits = $canRecord ? Payment::query()->with('customer:id,name')
            ->where('status', 'received')->where('purpose', 'custom_order_deposit')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->select(['id', 'custom_order_id', 'customer_id', 'reference', 'amount_minor', 'location_id'])
            ->selectSub(PaymentRefund::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('payment_id', 'payments.id'), 'refunded_minor')
            ->selectSub(CustomOrderDepositApplication::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('payment_id', 'payments.id'), 'applied_minor')
            ->latest('received_at')->limit(200)->get()
            ->filter(fn (Payment $payment): bool => $payment->amount_minor > (int) $payment->refunded_minor + (int) $payment->applied_minor)
            ->map(fn (Payment $payment): array => ['id' => $payment->id, 'orderId' => $payment->custom_order_id, 'reference' => $payment->reference, 'customer' => $payment->customer?->name, 'availableMinor' => $payment->amount_minor - (int) $payment->refunded_minor - (int) $payment->applied_minor]) : collect();

        $hireDepositReceipts = $canRecord ? Payment::query()->with('customer:id,name')
            ->where('status', 'received')->where('purpose', 'deposit')->whereNotNull('hire_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->select(['id', 'hire_id', 'customer_id', 'reference', 'amount_minor', 'location_id'])
            ->selectSub(PaymentRefund::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('payment_id', 'payments.id'), 'refunded_minor')
            ->selectSub(HireDepositApplication::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('payment_id', 'payments.id'), 'applied_minor')
            ->latest('received_at')->limit(200)->get()
            ->filter(fn (Payment $payment): bool => $payment->amount_minor > (int) $payment->refunded_minor + (int) $payment->applied_minor)
            ->map(fn (Payment $payment): array => ['id' => $payment->id, 'hireId' => $payment->hire_id, 'reference' => $payment->reference, 'customer' => $payment->customer?->name, 'availableMinor' => $payment->amount_minor - (int) $payment->refunded_minor - (int) $payment->applied_minor]) : collect();

        $expenses = OperatingExpense::query()->with(['location:id,name', 'creator:id,name', 'decisionMaker:id,name', 'paidBy:id,name'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->latest('incurred_on')->latest('id')->paginate(20, ['*'], 'expense_page')->withQueryString()
            ->through(fn (OperatingExpense $expense): array => [
                'id' => $expense->id, 'reference' => $expense->reference, 'amount_minor' => $expense->amount_minor,
                'category' => $expense->category, 'vendor' => $expense->vendor, 'receipt_reference' => $expense->receipt_reference,
                'incurred_on' => $expense->incurred_on->toDateString(), 'status' => $expense->status,
                'payment_status' => $expense->payment_status, 'payment_method' => $expense->payment_method,
                'payment_reference' => $expense->payment_reference, 'paid_on' => $expense->paid_on?->toDateString(),
                'has_receipt' => filled($expense->receipt_path), 'description' => $expense->description,
                'decision_reason' => $expense->decision_reason, 'created_by' => $expense->created_by,
                'location' => ['name' => $expense->location->name], 'creator' => ['name' => $expense->creator->name],
                'decision_maker' => $expense->decisionMaker ? ['name' => $expense->decisionMaker->name] : null,
                'paid_by' => $expense->paidBy ? ['name' => $expense->paidBy->name] : null,
            ]);
        $expenseLocations = $locationIds === null
            ? Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : Location::query()->whereIn('id', $locationIds)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $penaltySettings = BusinessConfiguration::query()->where('configuration_key', 'penalty_accounting')->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
            ->orderByDesc('effective_from')->orderByDesc('id')->first()?->configuration ?? [];
        $canManagePenaltyRequests = $user->hasPermission('hiring.penalty.manage') && in_array($user->role, ['super_admin', 'management', 'finance'], true);
        $penaltyWaiverRequests = $canManagePenaltyRequests ? PenaltyWaiverRequest::query()->with(['hire.customer:id,name', 'requester:id,name'])
            ->where('status', 'pending')->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->latest('created_at')->limit(100)->get()->map(fn (PenaltyWaiverRequest $waiver): array => [
                'id' => $waiver->id, 'reference' => $waiver->reference, 'hire' => $waiver->hire->reference,
                'customer' => $waiver->hire->customer->name, 'amountMinor' => $waiver->amount_minor,
                'reason' => $waiver->reason, 'requestedBy' => $waiver->requester->name, 'requesterId' => $waiver->requested_by,
            ]) : collect();
        $businessApprovals = in_array($user->role, ['super_admin', 'management', 'finance'], true)
            ? BusinessConfiguration::query()->whereIn('configuration_key', ['tax_setup', 'penalty_accounting', 'historical_cutover'])
                ->where('review_status', 'draft')->latest('id')->get()->unique('configuration_key')->map(function (BusinessConfiguration $record): array {
                    $configuration = $record->configuration;
                    $section = match ($record->configuration_key) {
                        'tax_setup' => 'tax-setup', 'penalty_accounting' => 'penalty-accounting', 'historical_cutover' => 'historical-cutover',
                    };
                    $summary = match ($record->configuration_key) {
                        'tax_setup' => implode(' · ', array_filter([
                            'Registration: '.($configuration['registration_status'] ?? 'unknown'),
                            'Pricing: '.($configuration['price_mode'] ?? 'unconfigured'),
                            'Rounding: '.($configuration['rounding_mode'] ?? 'unconfigured'),
                            'Categories: '.collect($configuration['categories'] ?? [])->map(fn (array $category): string => implode('/', array_filter([
                                $category['code'] ?? null, $category['treatment'] ?? null, $category['rate'] ?? null,
                                $category['output_account_code'] ?? null, $category['revenue_account_code'] ?? null,
                            ])))->join('; '),
                            'Accountant: '.($configuration['accountant_name'] ?? 'not recorded'),
                        ])),
                        'penalty_accounting' => implode(' · ', array_filter([
                            'Recognition: '.($configuration['recognition_basis'] ?? 'unconfigured'),
                            'Receivable account: '.($configuration['receivable_account_code'] ?? 'not mapped'),
                            'Income account: '.($configuration['penalty_income_account_code'] ?? 'not mapped'),
                            'Waiver/refund accounts: '.($configuration['waiver_account_code'] ?? 'not mapped').'/'.($configuration['refund_account_code'] ?? 'not mapped'),
                            'Waiver limits KES: '.($configuration['waiver_branch_limit'] ?? 0).'/'.($configuration['waiver_finance_limit'] ?? 0),
                            'Accountant: '.($configuration['accountant_name'] ?? 'not recorded'),
                        ])),
                        default => implode(' · ', array_filter([
                            'Cutover date: '.($configuration['proposed_cutover_date'] ?? 'not set'),
                            'Source: '.($configuration['source_snapshot_reference'] ?? 'not recorded'),
                            'Hires: '.($configuration['candidate_hire_count'] ?? 'not reviewed'),
                            'Custom orders: '.($configuration['candidate_order_count'] ?? 'not reviewed'),
                            'Opening receivables/deposits/refunds KES: '.($configuration['opening_receivables'] ?? 'not reviewed').'/'.($configuration['opening_deposits'] ?? 'not reviewed').'/'.($configuration['opening_refunds'] ?? 'not reviewed'),
                            'Exceptions: '.($configuration['unresolved_exceptions'] ?? 'not reviewed'),
                        ])),
                    };

                    return [
                        'id' => $record->id,
                        'section' => $section,
                        'title' => str($record->configuration_key)->replace('_', ' ')->title()->toString(),
                        'effectiveFrom' => $record->effective_from?->toDateString(),
                        'createdBy' => $record->created_by,
                        'summary' => $summary,
                    ];
                })->values()
            : collect();

        return Inertia::render('Finance/Payments', [
            'section' => $request->routeIs('finance.index') ? 'finance' : (in_array($request->route('section'), ['receipts', 'deposits', 'penalties', 'expenses'], true) ? $request->route('section') : 'overview'),
            'payments' => $payments,
            'hires' => $hires,
            'customOrders' => $customOrders,
            'customOrderDeposits' => $customOrderDeposits,
            'hireDepositReceipts' => $hireDepositReceipts,
            'expenses' => $expenses,
            'expenseLocations' => $expenseLocations,
            'canRecord' => $canRecord,
            'canReconcileMpesa' => $user->hasPermission('finance.manage'),
            'darajaEnabled' => app(IntegrationSettings::class)->darajaEnabled(app(IntegrationSettings::class)->daraja()),
            'stkPushes' => $canRecord ? MpesaStkPush::query()->with('customer:id,name')->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->latest()->limit(20)->get(['id', 'reference', 'customer_id', 'amount_minor', 'purpose', 'phone', 'status', 'result_description', 'mpesa_receipt', 'checkout_request_id', 'last_status_check_at', 'requested_at']) : collect(),
            'canRefund' => $user->can('createRefund', Payment::class),
            'canWaivePenalty' => $user->can('waivePenalty', Hire::class),
            'penaltyWaiverRequests' => $penaltyWaiverRequests,
            'businessApprovals' => $businessApprovals,
            'independentPenaltyApproval' => (bool) ($penaltySettings['independent_approval_required'] ?? true),
            'canDecidePenaltyWaivers' => $canManagePenaltyRequests,
            'canSubmitExpense' => in_array($user->role, ['super_admin', 'management', 'finance'], true),
            'canDecideExpenses' => in_array($user->role, ['super_admin', 'management'], true),
            'canMarkExpensesPaid' => in_array($user->role, ['super_admin', 'management', 'finance'], true),
            'actorId' => $user->id,
            'totals' => [
                'receivedCharges' => (int) Payment::query()->where('status', 'received')->whereIn('purpose', ['service_charge', 'penalty', 'custom_order_charge'])
                    ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor') - (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->whereIn('purpose', ['service_charge', 'penalty', 'custom_order_charge'])->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor')
                    + (int) CustomOrderDepositApplication::query()->whereHas('order', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor')
                    + (int) HireDepositApplication::query()->whereHas('hire', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor'),
                'depositsHeld' => (int) Payment::query()->where('status', 'received')->whereIn('purpose', ['deposit', 'custom_order_deposit'])
                    ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor') - (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->whereIn('purpose', ['deposit', 'custom_order_deposit'])->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor')
                    - (int) CustomOrderDepositApplication::query()->whereHas('order', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor')
                    - (int) HireDepositApplication::query()->whereHas('hire', fn ($query) => $query->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor'),
                'penaltiesDue' => max(0, $penaltiesAssessed + $penaltyAccruingToday - $penaltiesWaived - $penaltiesReceived + $penaltiesRefunded),
                'penaltyAccruingToday' => $penaltyAccruingToday,
                'approvedExpenses' => (int) OperatingExpense::query()->where('status', 'approved')->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->sum('amount_minor'),
                'pendingExpenses' => (int) OperatingExpense::query()->where('status', 'pending')->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->count(),
                'count' => Payment::query()->where('status', 'received')
                    ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))->count(),
            ],
        ]);
    }

    public function store(StorePaymentRequest $request, RecordHirePayment $record, RecordCustomOrderPayment $recordCustomOrder): RedirectResponse
    {
        $data = $request->validated();
        $payment = isset($data['custom_order_id'])
            ? $recordCustomOrder->handle($data, $request->user(), $request->ip(), $request->userAgent())
            : $record->handle($data, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Receipt {$payment->reference} recorded.");
    }

    public function startMpesa(StartDarajaStkPushRequest $request, StartDarajaStkPush $start): RedirectResponse
    {
        $push = $start->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());
        $message = $push->status === 'pending' && filled($push->checkout_request_id)
            ? "M-Pesa prompt {$push->reference} sent to the customer's phone. The receipt is pending Safaricom confirmation."
            : "M-Pesa request {$push->reference} is {$push->status}. Check the payment activity before retrying.";

        return back()->with('success', $message);
    }

    public function reconcileMpesa(Request $request, MpesaStkPush $push, ReconcileDarajaStkPush $reconcile): RedirectResponse
    {
        if ($push->location_id !== null && ! $request->user()->mayViewAllLocations() && ! $request->user()->locations()->whereKey($push->location_id)->exists()) {
            abort(403);
        }

        $updated = $reconcile->handle($push, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "M-Pesa request {$updated->reference} status is {$updated->status}. Review the Daraja response before retrying.");
    }

    public function refund(StorePaymentRefundRequest $request, RefundHirePayment $refund): RedirectResponse
    {
        $entry = $refund->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Refund {$entry->reference} recorded.");
    }

    public function applyCustomOrderDeposit(StoreCustomOrderDepositApplicationRequest $request, ApplyCustomOrderDeposit $apply): RedirectResponse
    {
        $application = $apply->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'KES '.number_format($application->amount_minor / 100, 2).' deposit applied to the custom order charge.');
    }

    public function applyHireDeposit(StoreHireDepositApplicationRequest $request, ApplyHireDeposit $apply): RedirectResponse
    {
        $application = $apply->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'KES '.number_format($application->amount_minor / 100, 2).' hire deposit applied to its service charge.');
    }

    public function waivePenalty(StorePenaltyWaiverRequest $request, WaiveHirePenalty $waive): RedirectResponse
    {
        $configuration = BusinessConfiguration::query()->where('configuration_key', 'penalty_accounting')->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if (($configuration?->configuration['independent_approval_required'] ?? true) === true) {
            $waiver = app(RequestHirePenaltyWaiver::class)->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

            return back()->with('success', "Waiver request {$waiver->reference} was submitted for independent approval.");
        }
        $adjustment = $waive->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Penalty waiver of KES '.number_format($adjustment->amount_minor / 100, 2).' recorded.');
    }

    public function decidePenaltyWaiver(DecidePenaltyWaiverRequest $request, PenaltyWaiverRequest $waiver, DecideHirePenaltyWaiver $decide): RedirectResponse
    {
        $waiver = $decide->handle($waiver, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Waiver request {$waiver->reference} {$waiver->status}.");
    }

    public function storeExpense(StoreOperatingExpenseRequest $request, SubmitOperatingExpense $submit): RedirectResponse
    {
        $expense = $submit->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Expense {$expense->reference} submitted for management approval.");
    }

    public function decideExpense(DecideOperatingExpenseRequest $request, OperatingExpense $expense, DecideOperatingExpense $decide): RedirectResponse
    {
        $expense = $decide->handle($expense, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Expense {$expense->reference} {$expense->status}.");
    }

    public function markExpensePaid(MarkOperatingExpensePaidRequest $request, OperatingExpense $expense, MarkOperatingExpensePaid $markPaid): RedirectResponse
    {
        $expense = $markPaid->handle($expense, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Expense {$expense->reference} marked paid.");
    }

    public function expenseReceipt(Request $request, OperatingExpense $expense): StreamedResponse
    {
        abort_unless($request->user()->can('viewAny', Payment::class), 403);
        $locationIds = $request->user()->mayViewAllLocations() ? null : $request->user()->locations()->pluck('locations.id')->all();
        abort_if($locationIds !== null && ! in_array($expense->location_id, $locationIds, true), 403);
        abort_if(blank($expense->receipt_path) || ! Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download($expense->receipt_path, $expense->reference.'-receipt');
    }
}
