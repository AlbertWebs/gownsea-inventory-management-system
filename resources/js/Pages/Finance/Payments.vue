<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, CircleHelp, Download, Receipt } from '@lucide/vue';
import { computed, nextTick, watch } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchableSelect from '../../Components/SearchableSelect.vue';

type Payment = { id: number; reference: string; amount_minor: number; refunded_minor: number | null; hire_deposit_applied_minor?: number | null; currency: string; method: string; purpose: string; status: string; provider_reference: string | null; received_at: string; customer: { name: string }; hire: { reference: string } | null; custom_order: { reference: string } | null; bulk_sale: { reference: string } | null };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
type HireOption = { id: number; reference: string; customer: string; status: string; chargeTotalMinor: number; chargePaidMinor: number; penaltyTotalMinor: number; penaltyPaidMinor: number; depositMinor: number; hasInvoice: boolean };
type CustomOrderOption = { id: number; reference: string; customer: string; status: string; chargeTotalMinor: number; chargePaidMinor: number; depositRequiredMinor: number; depositPaidMinor: number; hasInvoice: boolean };
type CustomOrderDeposit = { id: number; orderId: number; reference: string; customer: string | null; availableMinor: number };
type HireDeposit = { id: number; hireId: number; reference: string; customer: string | null; availableMinor: number };
type Expense = { id: number; reference: string; amount_minor: number; category: string; vendor: string | null; receipt_reference: string | null; incurred_on: string; status: string; payment_status: string; payment_method: string | null; payment_reference: string | null; paid_on: string | null; has_receipt: boolean; description: string; decision_reason: string | null; created_by: number; location: { name: string }; creator: { name: string }; decision_maker: { name: string } | null; paid_by: { name: string } | null };
type ExpenseLocation = { id: number; name: string };
type StkPush = { id: number; reference: string; amount_minor: number; purpose: string; phone: string; status: string; result_description: string | null; mpesa_receipt: string | null; checkout_request_id: string | null; last_status_check_at: string | null; requested_at: string; customer: { name: string } };
type PenaltyWaiver = { id: number; reference: string; hire: string; customer: string; amountMinor: number; reason: string; requestedBy: string; requesterId: number };
type BusinessApproval = { id: number; section: string; title: string; effectiveFrom: string | null; createdBy: number | null; summary: string };
const props = defineProps<{ section: 'finance' | 'overview' | 'receipts' | 'deposits' | 'penalties' | 'expenses'; payments: Page<Payment>; expenses: Page<Expense>; expenseLocations: ExpenseLocation[]; hires: HireOption[]; customOrders: CustomOrderOption[]; customOrderDeposits: CustomOrderDeposit[]; hireDepositReceipts: HireDeposit[]; stkPushes: StkPush[]; penaltyWaiverRequests: PenaltyWaiver[]; businessApprovals: BusinessApproval[]; independentPenaltyApproval: boolean; canDecidePenaltyWaivers: boolean; darajaEnabled: boolean; canRecord: boolean; canReconcileMpesa: boolean; canRefund: boolean; canWaivePenalty: boolean; canSubmitExpense: boolean; canDecideExpenses: boolean; canMarkExpensesPaid: boolean; actorId: number; totals: { receivedCharges: number; depositsHeld: number; penaltiesDue: number; penaltyAccruingToday: number; approvedExpenses: number; pendingExpenses: number; count: number } }>();
const sectionTitle = computed(() => ({ finance: 'Finance overview', overview: 'Payments overview', receipts: 'Receipts', deposits: 'Deposits', penalties: 'Penalties & refunds', expenses: 'Operating expenses' })[props.section]);
const kes = (amount: number): string => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 0 }).format(amount / 100);
const dateLabel = (value: string): string => new Date(value).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' });
const paymentForm = useForm({ entity_type: 'hire', hire_id: '', custom_order_id: '', amount: '', method: 'cash', purpose: 'service_charge', provider_reference: '', idempotency_key: crypto.randomUUID() });
const selectedHire = computed(() => props.hires.find((hire) => String(hire.id) === paymentForm.hire_id));
const selectedCustomOrder = computed(() => props.customOrders.find((order) => String(order.id) === paymentForm.custom_order_id));
const paymentHireOptions = computed(() => props.hires.map((hire) => ({ value: String(hire.id), label: `${hire.reference} · ${hire.customer} · ${hire.status === 'reserved' ? 'reservation deposit only' : `service balance ${kes(Math.max(0, hire.chargeTotalMinor - hire.chargePaidMinor))} · penalty balance ${kes(Math.max(0, hire.penaltyTotalMinor - hire.penaltyPaidMinor))}`}` })));
const paymentOrderOptions = computed(() => props.customOrders.map((order) => ({ value: String(order.id), label: `${order.reference} · ${order.customer} · charge balance ${kes(Math.max(0, order.chargeTotalMinor - order.chargePaidMinor))} · deposit ${kes(Math.max(0, order.depositRequiredMinor - order.depositPaidMinor))}` })));
const stkHireOptions = computed(() => props.hires.map((hire) => ({ value: String(hire.id), label: `${hire.reference} · ${hire.customer}` })));
const stkOrderOptions = computed(() => props.customOrders.map((order) => ({ value: String(order.id), label: `${order.reference} · ${order.customer}` })));
const depositApplicationForm = useForm({ custom_order_id: '', payment_id: '', amount: '', idempotency_key: crypto.randomUUID() });
const selectedDepositReceipt = computed(() => props.customOrderDeposits.find((payment) => String(payment.id) === depositApplicationForm.payment_id));
const depositOrderOptions = computed(() => props.customOrders.filter((order) => order.hasInvoice && order.chargeTotalMinor > order.chargePaidMinor && props.customOrderDeposits.some((receipt) => receipt.orderId === order.id)).map((order) => ({ value: String(order.id), label: `${order.reference} · ${order.customer} · due ${kes(order.chargeTotalMinor - order.chargePaidMinor)}` })));
const applicableDeposits = computed(() => props.customOrderDeposits.filter((payment) => String(payment.orderId) === depositApplicationForm.custom_order_id).map((payment) => ({ value: String(payment.id), label: `${payment.reference} · ${payment.customer ?? 'Customer'} · available ${kes(payment.availableMinor)}` })));
const selectedDepositOrder = computed(() => props.customOrders.find((order) => String(order.id) === depositApplicationForm.custom_order_id));
const maxOrderDepositApplicationMinor = computed(() => Math.min(selectedDepositReceipt.value?.availableMinor ?? 0, Math.max(0, (selectedDepositOrder.value?.chargeTotalMinor ?? 0) - (selectedDepositOrder.value?.chargePaidMinor ?? 0))));
watch(() => depositApplicationForm.custom_order_id, () => { depositApplicationForm.payment_id = ''; depositApplicationForm.amount = ''; });
function applyDeposit(): void {
    depositApplicationForm.post('/payments/custom-order-deposits/apply', { preserveScroll: true, onSuccess: () => { depositApplicationForm.reset('custom_order_id', 'payment_id', 'amount'); depositApplicationForm.idempotency_key = crypto.randomUUID(); } });
}
const hireDepositForm = useForm({ hire_id: '', payment_id: '', amount: '', idempotency_key: crypto.randomUUID() });
const selectedHireDeposit = computed(() => props.hireDepositReceipts.find((payment) => String(payment.id) === hireDepositForm.payment_id));
const depositHireOptions = computed(() => props.hires.filter((hire) => hire.status !== 'reserved' && hire.status !== 'cancelled' && hire.hasInvoice && hire.chargeTotalMinor > hire.chargePaidMinor && props.hireDepositReceipts.some((receipt) => receipt.hireId === hire.id)).map((hire) => ({ value: String(hire.id), label: `${hire.reference} · ${hire.customer} · due ${kes(hire.chargeTotalMinor - hire.chargePaidMinor)}` })));
const applicableHireDeposits = computed(() => props.hireDepositReceipts.filter((payment) => String(payment.hireId) === hireDepositForm.hire_id).map((payment) => ({ value: String(payment.id), label: `${payment.reference} · ${payment.customer ?? 'Customer'} · available ${kes(payment.availableMinor)}` })));
const selectedDepositHire = computed(() => props.hires.find((hire) => String(hire.id) === hireDepositForm.hire_id));
const maxHireDepositApplicationMinor = computed(() => Math.min(selectedHireDeposit.value?.availableMinor ?? 0, Math.max(0, (selectedDepositHire.value?.chargeTotalMinor ?? 0) - (selectedDepositHire.value?.chargePaidMinor ?? 0))));
const availableDepositTotalMinor = computed(() => [...props.customOrderDeposits, ...props.hireDepositReceipts].reduce((total, payment) => total + payment.availableMinor, 0));
const availableCustomOrderDepositMinor = computed(() => props.customOrderDeposits.reduce((total, payment) => total + payment.availableMinor, 0));
const availableHireDepositMinor = computed(() => props.hireDepositReceipts.reduce((total, payment) => total + payment.availableMinor, 0));
const depositRows = computed(() => [
    ...props.customOrderDeposits.map((receipt) => {
        const order = props.customOrders.find((candidate) => candidate.id === receipt.orderId);
        const canApply = !!order?.hasInvoice && order.chargeTotalMinor > order.chargePaidMinor;
        return { id: receipt.id, kind: 'Custom order' as const, targetId: receipt.orderId, reference: receipt.reference, customer: receipt.customer ?? order?.customer ?? 'Customer', target: order?.reference ?? 'Custom order', availableMinor: receipt.availableMinor, canApply, waitReason: !order?.hasInvoice ? 'Waiting for invoice' : canApply ? '' : 'No charge due' };
    }),
    ...props.hireDepositReceipts.map((receipt) => {
        const hire = props.hires.find((candidate) => candidate.id === receipt.hireId);
        const canApply = !!hire?.hasInvoice && !['reserved', 'cancelled'].includes(hire.status) && hire.chargeTotalMinor > hire.chargePaidMinor;
        return { id: receipt.id, kind: 'Hire' as const, targetId: receipt.hireId, reference: receipt.reference, customer: receipt.customer ?? hire?.customer ?? 'Customer', target: hire?.reference ?? 'Hire', availableMinor: receipt.availableMinor, canApply, waitReason: hire?.status === 'reserved' ? 'Available after gown issue' : !hire?.hasInvoice ? 'Waiting for invoice' : canApply ? '' : 'No charge due' };
    }),
]);
async function startDepositApplication(row: (typeof depositRows.value)[number]): Promise<void> {
    if (!row.canApply) return;
    if (row.kind === 'Custom order') {
        const order = props.customOrders.find((candidate) => candidate.id === row.targetId);
        if (!order) return;
        depositApplicationForm.custom_order_id = String(order.id);
        await nextTick();
        depositApplicationForm.payment_id = String(row.id);
        depositApplicationForm.amount = (Math.min(row.availableMinor, order.chargeTotalMinor - order.chargePaidMinor) / 100).toFixed(2);
        await nextTick();
        document.querySelector('#custom-order-deposit-application')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
        const hire = props.hires.find((candidate) => candidate.id === row.targetId);
        if (!hire) return;
        hireDepositForm.hire_id = String(hire.id);
        await nextTick();
        hireDepositForm.payment_id = String(row.id);
        hireDepositForm.amount = (Math.min(row.availableMinor, hire.chargeTotalMinor - hire.chargePaidMinor) / 100).toFixed(2);
        await nextTick();
        document.querySelector('#hire-deposit-application')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
watch(() => hireDepositForm.hire_id, () => { hireDepositForm.payment_id = ''; hireDepositForm.amount = ''; });
function applyHireDeposit(): void {
    hireDepositForm.post('/payments/hire-deposits/apply', { preserveScroll: true, onSuccess: () => { hireDepositForm.reset('hire_id', 'payment_id', 'amount'); hireDepositForm.idempotency_key = crypto.randomUUID(); } });
}
watch(() => paymentForm.entity_type, (type) => { paymentForm.hire_id = ''; paymentForm.custom_order_id = ''; paymentForm.purpose = type === 'hire' ? 'service_charge' : 'custom_order_charge'; });
watch(selectedHire, (hire) => { if (paymentForm.entity_type !== 'hire') return; if (hire?.status === 'reserved') paymentForm.purpose = 'deposit'; else if (paymentForm.purpose === 'deposit') paymentForm.purpose = 'service_charge'; });
watch(selectedCustomOrder, (order) => { if (paymentForm.entity_type !== 'custom_order') return; paymentForm.purpose = order && order.depositRequiredMinor > order.depositPaidMinor ? 'custom_order_deposit' : 'custom_order_charge'; });
function recordPayment(): void {
    paymentForm.transform((data) => {
        const { entity_type: entityType, ...payload } = data;
        return entityType === 'hire' ? { ...payload, custom_order_id: undefined } : { ...payload, hire_id: undefined };
    });
    paymentForm.post('/payments', { preserveScroll: true, onSuccess: () => { paymentForm.reset('amount', 'provider_reference'); paymentForm.idempotency_key = crypto.randomUUID(); } });
}
const stkForm = useForm({ entity_type: 'hire', hire_id: '', custom_order_id: '', amount: '', purpose: 'service_charge', phone: '', idempotency_key: crypto.randomUUID() });
const stkErrors = computed(() => stkForm.errors as Record<string, string>);
const stkHire = computed(() => props.hires.find((hire) => String(hire.id) === stkForm.hire_id));
const stkOrder = computed(() => props.customOrders.find((order) => String(order.id) === stkForm.custom_order_id));
watch(() => stkForm.entity_type, (type) => { stkForm.hire_id = ''; stkForm.custom_order_id = ''; stkForm.purpose = type === 'hire' ? 'service_charge' : 'custom_order_charge'; });
watch(stkHire, (hire) => { if (stkForm.entity_type === 'hire' && hire?.status === 'reserved') stkForm.purpose = 'deposit'; else if (stkForm.purpose === 'deposit') stkForm.purpose = 'service_charge'; });
watch(stkOrder, (order) => { if (stkForm.entity_type === 'custom_order') stkForm.purpose = order && order.depositRequiredMinor > order.depositPaidMinor ? 'custom_order_deposit' : 'custom_order_charge'; });
function startStkPush(): void {
    stkForm.transform((data) => { const { entity_type: entityType, ...payload } = data; return entityType === 'hire' ? { ...payload, custom_order_id: undefined } : { ...payload, hire_id: undefined }; });
    stkForm.post('/payments/mpesa/stk', { preserveScroll: true, onSuccess: () => { stkForm.reset('amount', 'phone'); stkForm.idempotency_key = crypto.randomUUID(); } });
}
function reconcileStk(push: StkPush): void { router.post(`/payments/mpesa/stk/${push.id}/reconcile`, {}, { preserveScroll: true }); }
const refundForm = useForm({ payment_id: '', amount: '', method: 'cash', provider_reference: '', reason: '', idempotency_key: crypto.randomUUID() });
const refundablePayments = computed(() => props.payments.data.filter((payment) => payment.purpose !== 'product_sale' && payment.amount_minor > (payment.refunded_minor ?? 0) + (payment.purpose === 'deposit' ? (payment.hire_deposit_applied_minor ?? 0) : 0)));
const refundablePaymentOptions = computed(() => refundablePayments.value.map((payment) => ({ value: String(payment.id), label: `${payment.reference} · ${payment.customer.name} · remaining ${kes(payment.amount_minor - (payment.refunded_minor ?? 0) - (payment.purpose === 'deposit' ? (payment.hire_deposit_applied_minor ?? 0) : 0))}` })));
const receiptPageTotals = computed(() => props.payments.data.reduce((totals, payment) => {
    const refunded = payment.refunded_minor ?? 0;
    totals.gross += payment.amount_minor;
    totals.refunded += refunded;
    totals.net += payment.amount_minor - refunded;
    return totals;
}, { gross: 0, refunded: 0, net: 0 }));
function recordRefund(): void {
    refundForm.post('/payments/refunds', { preserveScroll: true, onSuccess: () => { refundForm.reset('amount', 'provider_reference', 'reason'); refundForm.idempotency_key = crypto.randomUUID(); } });
}
const waiverForm = useForm({ hire_id: '', amount: '', reason: '', idempotency_key: crypto.randomUUID() });
const financeApprovalForm = useForm({ approval_reference: '', confirm: true });
const waiverHires = computed(() => props.hires.filter((hire) => hire.penaltyTotalMinor > hire.penaltyPaidMinor));
const waiverHireOptions = computed(() => waiverHires.value.map((hire) => ({ value: String(hire.id), label: `${hire.reference} · ${hire.customer} · outstanding ${kes(Math.max(0, hire.penaltyTotalMinor - hire.penaltyPaidMinor))}` })));
function waivePenalty(): void {
    waiverForm.post('/payments/penalty-waivers', { preserveScroll: true, onSuccess: () => { waiverForm.reset('amount', 'reason'); waiverForm.idempotency_key = crypto.randomUUID(); } });
}
function decidePenaltyWaiver(waiver: PenaltyWaiver, status: 'approved' | 'rejected'): void {
    const decision_reason = status === 'rejected' ? window.prompt('Reason for rejecting this waiver request:') : undefined;
    if (status === 'rejected' && (!decision_reason || decision_reason.trim().length < 5)) return;
    router.patch(`/payments/penalty-waivers/${waiver.id}/decision`, { status, decision_reason }, { preserveScroll: true });
}
function approveBusinessConfiguration(configuration: BusinessApproval): void {
    financeApprovalForm.post(`/settings/finance/${configuration.section}/${configuration.id}/approve`, { preserveScroll: true, onSuccess: () => financeApprovalForm.reset('approval_reference') });
}
const expenseForm = useForm({ location_id: props.expenseLocations[0]?.id ?? '', amount: '', category: 'transport', vendor: '', receipt_reference: '', receipt: null as File | null, incurred_on: new Date().toISOString().slice(0, 10), description: '', idempotency_key: crypto.randomUUID() });
function submitExpense(): void {
    expenseForm.post('/finance/expenses', { forceFormData: true, preserveScroll: true, onSuccess: () => { expenseForm.reset('amount', 'vendor', 'receipt_reference', 'receipt', 'description'); expenseForm.idempotency_key = crypto.randomUUID(); } });
}
function setExpenseReceipt(event: Event): void { expenseForm.receipt = (event.target as HTMLInputElement).files?.[0] ?? null; }
function decideExpense(expense: Expense, decision: 'approve' | 'reject'): void {
    const reason = decision === 'reject' ? window.prompt('Reason for rejecting this expense:') : null;
    if (decision === 'reject' && (!reason || reason.trim().length < 5)) return;
    router.patch(`/finance/expenses/${expense.id}/decision`, { decision, reason }, { preserveScroll: true });
}
function markExpensePaid(expense: Expense): void {
    const method = window.prompt('Payment method: cash, bank_transfer, mpesa, card, or other', 'mpesa');
    if (!method || !['cash', 'bank_transfer', 'mpesa', 'card', 'other'].includes(method)) return;
    const reference = window.prompt('Payment reference (optional):') ?? '';
    router.patch(`/finance/expenses/${expense.id}/paid`, { payment_method: method, payment_reference: reference, paid_on: new Date().toISOString().slice(0, 10) }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="sectionTitle" />
    <AppShell active="payments">
        <div class="page-heading"><div><h1>{{ sectionTitle }}</h1><p>{{ section === 'finance' ? 'Monitor accounting balances and open the tools used to reconcile income, payables, and financial records.' : section === 'overview' ? 'A clear view of customer receipts, refundable deposits, penalties, and operating costs.' : section === 'receipts' ? 'Record and review payments received from hires and custom orders.' : section === 'deposits' ? 'See unallocated deposits, which charges can use them, and apply funds up to the available balance.' : section === 'penalties' ? 'Manage assessed late fees, waiver approvals, and customer refunds.' : 'Submit, review, and record payment of operating expenses.' }}</p></div><div class="heading-actions"><Link v-if="section === 'finance'" href="/payments" class="secondary-link">Sales &amp; payments</Link><template v-else><Link v-if="section === 'deposits' && canRecord" href="/payments/receipts" class="secondary-link">Record a deposit receipt</Link><a v-if="section === 'receipts'" href="/payments/receipts/export" class="secondary-link download-receipts"><Download aria-hidden="true" /> Download receipts</a><Link href="/finance/vendor-bills" class="secondary-link">Vendor bills</Link><Link href="/finance/invoices" class="secondary-link">Customer invoices</Link><Link href="/finance/ledger" class="secondary-link">General ledger</Link><Link href="/finance" class="secondary-link">Finance overview</Link></template></div></div>
        <div v-if="section === 'finance'" class="finance-cards">
            <article class="panel finance-card"><span>Charge receipts, net</span><strong>{{ kes(totals.receivedCharges) }}</strong><small>Earned charges and collected penalties, less refunds</small></article>
            <article class="panel finance-card"><span>Refundable deposits held</span><strong>{{ kes(totals.depositsHeld) }}</strong><small>Customer funds held separately from revenue</small></article>
            <article class="panel finance-card"><span>Late-fee receivable</span><strong>{{ kes(totals.penaltiesDue) }}</strong><small>Unpaid assessed and accruing penalties</small></article>
            <article class="panel finance-card"><span>Approved expenses</span><strong>{{ kes(totals.approvedExpenses) }}</strong><small>{{ totals.pendingExpenses }} pending approval</small></article>
        </div>
        <div v-if="section === 'finance'" class="finance-workspaces">
            <Link href="/finance/invoices" class="finance-workspace-card"><span class="workspace-icon"><Receipt aria-hidden="true" /></span><span class="workspace-copy"><strong>Customer invoices</strong><small>Review billed amounts, payments, balances, credit notes, and eTIMS status.</small></span><span class="workspace-arrow">Open invoices <span aria-hidden="true">→</span></span></Link>
            <Link href="/finance/vendor-bills" class="finance-workspace-card"><span class="workspace-icon"><Banknote aria-hidden="true" /></span><span class="workspace-copy"><strong>Vendor bills</strong><small>Track supplier invoices, due balances, and partial payments.</small></span><span class="workspace-arrow">Open bills <span aria-hidden="true">→</span></span></Link>
            <Link href="/finance/ledger" class="finance-workspace-card"><span class="workspace-icon"><CircleHelp aria-hidden="true" /></span><span class="workspace-copy"><strong>General ledger</strong><small>Inspect balanced journal entries and recorded reversals.</small></span><span class="workspace-arrow">Open ledger <span aria-hidden="true">→</span></span></Link>
            <Link href="/payments/expenses" class="finance-workspace-card"><span class="workspace-icon"><Receipt aria-hidden="true" /></span><span class="workspace-copy"><strong>Operating expenses</strong><small>Review submitted expenses, approval decisions, and payment status.</small></span><span class="workspace-arrow">Manage expenses <span aria-hidden="true">→</span></span></Link>
        </div>
        <div v-if="section === 'overview'" class="finance-cards">
            <article class="panel finance-card"><span>Charge receipts</span><strong>{{ kes(totals.receivedCharges) }}</strong><small>Hire/custom order charges and collected penalties, net of refunds</small></article>
            <article class="panel finance-card"><span>Refundable deposits received</span><strong>{{ kes(totals.depositsHeld) }}</strong><small>Tracked separately from earned charges</small></article>
            <article class="panel finance-card"><span>Late-fee receivable</span><strong>{{ kes(totals.penaltiesDue) }}</strong><small>Assessed to date, less receipts and waivers</small></article>
            <article class="panel finance-card"><span>Receipts recorded</span><strong>{{ totals.count.toLocaleString() }}</strong><small>Visible for your authorized locations</small></article>
            <article class="panel finance-card"><span>Approved operating expenses</span><strong>{{ kes(totals.approvedExpenses) }}</strong><small>{{ totals.pendingExpenses }} expense(s) awaiting management review</small></article>
        </div>
        <div v-if="section === 'overview'" class="payments-overview-links"><Link href="/payments/receipts" class="payment-shortcut"><Banknote aria-hidden="true"/><span><strong>Receipts</strong><small>Record and review customer payments</small></span></Link><Link href="/payments/deposits" class="payment-shortcut"><Receipt aria-hidden="true"/><span><strong>Deposits</strong><small>Track and apply refundable deposits</small></span></Link><Link href="/payments/penalties" class="payment-shortcut"><CircleHelp aria-hidden="true"/><span><strong>Penalties & refunds</strong><small>Manage fee adjustments and refunds</small></span></Link><Link href="/payments/expenses" class="payment-shortcut"><Receipt aria-hidden="true"/><span><strong>Operating expenses</strong><small>Submit and review branch costs</small></span></Link></div>
        <div v-if="section === 'expenses'" class="expense-summary-cards">
            <article class="panel expense-summary-card"><span>Approved spend</span><strong>{{ kes(totals.approvedExpenses) }}</strong><small>Approved expense value</small></article>
            <article class="panel expense-summary-card pending"><span>Awaiting review</span><strong>{{ totals.pendingExpenses.toLocaleString() }}</strong><small>Needs a different manager’s decision</small></article>
            <article class="panel expense-summary-card"><span>Expense records</span><strong>{{ expenses.total.toLocaleString() }}</strong><small>Across your authorized locations</small></article>
        </div>
        <div v-if="section === 'penalties'" class="penalty-summary-cards">
            <article class="panel penalty-summary-card outstanding"><span>Outstanding penalties</span><strong>{{ kes(totals.penaltiesDue) }}</strong><small>Assessed and accruing amounts less payments and waivers</small></article>
            <article class="panel penalty-summary-card"><span>Accruing today</span><strong>{{ kes(totals.penaltyAccruingToday) }}</strong><small>Daily late fees on gowns still in customer custody</small></article>
        </div>
        <div v-if="section === 'deposits'" class="deposit-summary-cards"><article class="panel deposit-summary-card total"><span>Available to apply</span><strong>{{ kes(availableDepositTotalMinor) }}</strong><small>{{ depositRows.length }} unapplied deposit receipts</small></article><article class="panel deposit-summary-card"><span>Custom order deposits</span><strong>{{ kes(availableCustomOrderDepositMinor) }}</strong><small>{{ customOrderDeposits.length }} available receipts</small></article><article class="panel deposit-summary-card"><span>Hire deposits</span><strong>{{ kes(availableHireDepositMinor) }}</strong><small>{{ hireDepositReceipts.length }} available receipts</small></article></div>
        <section v-if="section === 'deposits'" class="panel table-panel deposit-register"><div class="receipt-heading"><div><h2>Unapplied deposits</h2><p>Each balance stays refundable until it is refunded or applied to an invoiced charge.</p></div></div><div v-if="depositRows.length" class="table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Receipt</th><th>Customer</th><th>Hire / order</th><th>Available</th><th>Next step</th></tr></thead><tbody><tr v-for="row in depositRows" :key="`${row.kind}-${row.id}`"><td><span class="deposit-type-chip">{{ row.kind }}</span></td><td><strong>{{ row.reference }}</strong></td><td>{{ row.customer }}</td><td>{{ row.target }}</td><td class="amount deposit-available-amount">{{ kes(row.availableMinor) }}</td><td><button v-if="row.canApply && canRecord" class="deposit-apply-action" type="button" @click="startDepositApplication(row)">Apply deposit</button><span v-else class="deposit-wait-reason">{{ row.waitReason || 'No action available' }}</span></td></tr></tbody></table></div><div v-else class="deposit-empty"><Receipt aria-hidden="true"/><div><strong>No unapplied deposits</strong><span>Record a refundable deposit receipt first. It will appear here with the amount still available.</span></div><Link v-if="canRecord" href="/payments/receipts" class="secondary-link">Go to receipts</Link></div></section>
        <div v-if="section === 'finance' || section === 'overview' || section === 'receipts' || section === 'penalties'" class="workflow-note"><CircleHelp :size="16" aria-hidden="true" /> <template v-if="section === 'penalties'">Late fees accrue daily under the terms agreed when the gown was issued. Review the hire balance before requesting a waiver; the final fee is fixed when the gown is physically returned. A refund records the reversal here, while any bank or M-Pesa transfer is completed separately.</template><template v-else>Late fees on outstanding hire units accrue daily using the terms set when the gown was issued. The accrued amount can be collected or waived before return; at physical return, the final amount is frozen. Held deposits are tracked separately from revenue. M-Pesa transfers are not initiated from manual receipt entry.</template></div>
        <section v-if="section === 'expenses' && canSubmitExpense" class="panel receipt-form-panel">
            <div class="receipt-heading expense-form-heading"><div><h2>Submit an expense</h2><p>Enter the purchase details and attach a receipt if you have one. A different manager must review it before it can be marked paid.</p></div><span class="expense-review-note">1 · Submit <span aria-hidden="true">→</span> 2 · Manager review <span aria-hidden="true">→</span> 3 · Record payment</span></div>
            <form class="receipt-form expense-form" @submit.prevent="submitExpense">
                <div class="form-field"><label for="expense-location">Location</label><select id="expense-location" v-model="expenseForm.location_id" class="select-field" required><option v-for="location in expenseLocations" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="expenseForm.errors.location_id" class="form-error">{{ expenseForm.errors.location_id }}</small></div>
                <div class="form-field"><label for="expense-category">Category</label><select id="expense-category" v-model="expenseForm.category" class="select-field"><option value="transport">Transport</option><option value="labor">Labor</option><option value="repairs">Repairs</option><option value="sms">SMS</option><option value="delivery">Delivery</option><option value="other">Other</option></select></div>
                <div class="form-field"><label for="expense-amount">Amount (KES)</label><input id="expense-amount" v-model="expenseForm.amount" class="field" inputmode="decimal" required><small v-if="expenseForm.errors.amount" class="form-error">{{ expenseForm.errors.amount }}</small></div>
                <div class="form-field"><label for="expense-date">Expense date</label><input id="expense-date" v-model="expenseForm.incurred_on" class="field" type="date" required><small v-if="expenseForm.errors.incurred_on" class="form-error">{{ expenseForm.errors.incurred_on }}</small></div>
                <div class="form-field"><label for="expense-vendor">Vendor (optional)</label><input id="expense-vendor" v-model="expenseForm.vendor" class="field" maxlength="160"></div>
                <div class="form-field"><label for="expense-receipt">Receipt reference (optional)</label><input id="expense-receipt" v-model="expenseForm.receipt_reference" class="field" maxlength="120"></div>
                <div class="form-field"><label for="expense-attachment">Receipt attachment (optional)</label><input id="expense-attachment" class="field expense-file" type="file" accept="image/jpeg,image/png,application/pdf" @change="setExpenseReceipt"><small>{{ expenseForm.receipt?.name ?? 'PDF, JPG or PNG · up to 10 MB' }}</small><small v-if="expenseForm.errors.receipt" class="form-error">{{ expenseForm.errors.receipt }}</small></div>
                <div class="form-field full"><label for="expense-description">What was this expense for?</label><textarea id="expense-description" v-model="expenseForm.description" class="field" rows="2" minlength="5" maxlength="2000" placeholder="Add a short description to help the reviewer" required></textarea><small v-if="expenseForm.errors.description" class="form-error">{{ expenseForm.errors.description }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="expenseForm.processing || !expenseLocations.length"><Receipt aria-hidden="true" /> {{ expenseForm.processing ? 'Submitting…' : 'Submit for approval' }}</button></div>
            </form>
        </section>
        <section v-if="section === 'expenses'" class="panel table-panel">
            <div class="receipt-heading expense-table-heading"><div><h2>Expense register</h2><p>Review submissions, approval status, and payment records. Use Vendor Bills for supplier invoices, due balances, and partial payments.</p></div><Link href="/finance/vendor-bills" class="secondary-link">Open vendor bills</Link></div>
            <div v-if="expenses.data.length" class="table-wrap" role="region" aria-label="Operating expense register" tabindex="0"><table class="data-table expense-table"><thead><tr><th>Expense</th><th>Date &amp; location</th><th>Category</th><th>Vendor / receipt</th><th>Submitted by</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="expense in expenses.data" :key="expense.id"><td><strong>{{ expense.reference }}</strong><small class="table-subline expense-description">{{ expense.description }}</small></td><td>{{ expense.incurred_on }}<small class="table-subline">{{ expense.location.name }}</small></td><td><span class="expense-category-chip">{{ expense.category.replaceAll('_', ' ') }}</span></td><td>{{ expense.vendor ?? '—' }}<small v-if="expense.receipt_reference" class="table-subline">Ref: {{ expense.receipt_reference }}</small></td><td>{{ expense.creator.name }}</td><td class="amount expense-amount">{{ kes(expense.amount_minor) }}</td><td><div class="expense-status-stack"><span class="status-chip" :class="expense.status === 'approved' ? 'completed' : expense.status === 'rejected' ? 'damaged' : 'due'">{{ expense.status }}</span><span v-if="expense.status === 'approved'" class="status-chip" :class="expense.payment_status === 'paid' ? 'completed' : 'due'">{{ expense.payment_status }}</span></div><small v-if="expense.decision_maker" class="table-subline">Reviewed by {{ expense.decision_maker.name }}</small><small v-if="expense.paid_on" class="table-subline">{{ expense.paid_on }} · {{ expense.payment_method }} · {{ expense.paid_by?.name ?? 'staff' }}</small></td><td><div class="expense-row-actions"><template v-if="canDecideExpenses && expense.status === 'pending' && expense.created_by !== actorId"><button class="expense-action approve" type="button" @click="decideExpense(expense, 'approve')">Approve</button><button class="expense-action reject" type="button" @click="decideExpense(expense, 'reject')">Reject</button></template><button v-else-if="canMarkExpensesPaid && expense.status === 'approved' && expense.payment_status === 'unpaid'" class="expense-action approve" type="button" @click="markExpensePaid(expense)">Mark paid</button><a v-if="expense.has_receipt" :href="`/finance/expenses/${expense.id}/receipt`" class="expense-action evidence">View receipt</a></div><small v-if="expense.decision_reason" class="expense-decision-reason">{{ expense.decision_reason }}</small></td></tr></tbody></table></div>
            <div v-else class="empty-state expense-empty-state"><div><Receipt aria-hidden="true" /><strong>No expenses recorded</strong><span>Submitted expenses will appear here.</span></div></div>
            <Pagination v-if="expenses.total > 0" :links="expenses.links" :from="expenses.from" :to="expenses.to" :total="expenses.total" />
        </section>
        <section v-if="section === 'receipts' && canRecord" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Record a customer receipt</h2><p>Manual receipt entry only. This does not charge M-Pesa or create an invoice.</p></div></div>
            <form class="receipt-form payment-receipt-form" @submit.prevent="recordPayment">
                <div class="form-field"><label for="payment-entity-type">Receipt for</label><select id="payment-entity-type" v-model="paymentForm.entity_type" class="select-field"><option value="hire">Gown hire</option><option value="custom_order">Custom order</option></select></div>
                <div v-if="paymentForm.entity_type === 'hire'" class="form-field"><label for="payment-hire">Hire and customer</label><SearchableSelect id="payment-hire" v-model="paymentForm.hire_id" :options="paymentHireOptions" label="Hire and customer" placeholder="Select a hire" search-placeholder="Search reference or customer…"/><small v-if="paymentForm.errors.hire_id" class="form-error">{{ paymentForm.errors.hire_id }}</small></div>
                <div v-else class="form-field"><label for="payment-custom-order">Custom order and customer</label><SearchableSelect id="payment-custom-order" v-model="paymentForm.custom_order_id" :options="paymentOrderOptions" label="Custom order and customer" placeholder="Select a custom order" search-placeholder="Search order or customer…"/><small v-if="paymentForm.errors.custom_order_id" class="form-error">{{ paymentForm.errors.custom_order_id }}</small></div>
                <div class="form-field"><label for="payment-amount">Amount (KES)</label><input id="payment-amount" v-model="paymentForm.amount" class="field" inputmode="decimal" placeholder="e.g. 750.00" required><small v-if="paymentForm.errors.amount" class="form-error">{{ paymentForm.errors.amount }}</small></div>
                <div class="form-field"><label for="payment-purpose">Receipt purpose</label><select id="payment-purpose" v-model="paymentForm.purpose" class="select-field"><template v-if="paymentForm.entity_type === 'hire'"><option value="service_charge" :disabled="selectedHire?.status === 'reserved'">Service charge</option><option value="penalty" :disabled="selectedHire?.status === 'reserved' || !selectedHire || selectedHire.penaltyTotalMinor <= selectedHire.penaltyPaidMinor">Assessed penalty</option><option value="deposit">Refundable deposit</option></template><template v-else><option value="custom_order_charge">Agreed order charge</option><option value="custom_order_deposit" :disabled="!selectedCustomOrder || selectedCustomOrder.depositRequiredMinor <= selectedCustomOrder.depositPaidMinor">Required deposit</option></template></select><small v-if="paymentForm.entity_type === 'hire'">Penalty receipts are limited to assessed unpaid amounts; reservations accept deposits only.</small><small v-else>Receipts cannot exceed the remaining order balance. Charge receipts before completion are held as customer advances.</small></div>
                <div class="form-field"><label for="payment-method">Payment method</label><select id="payment-method" v-model="paymentForm.method" class="select-field"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mpesa">M-Pesa (manual receipt)</option><option value="other">Other</option></select></div>
                <div v-if="paymentForm.method === 'mpesa'" class="form-field"><label for="provider-reference">M-Pesa receipt reference</label><input id="provider-reference" v-model="paymentForm.provider_reference" class="field" maxlength="120" required><small v-if="paymentForm.errors.provider_reference" class="form-error">{{ paymentForm.errors.provider_reference }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="paymentForm.processing">{{ paymentForm.processing ? 'Recording…' : 'Record receipt' }}</button></div>
            </form>
        </section>
        <section v-if="section === 'receipts' && canRecord && darajaEnabled" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Request M-Pesa payment</h2><p>Daraja will send a PIN prompt. The receipt stays pending until Safaricom confirms it by callback.</p></div></div>
            <form class="receipt-form" @submit.prevent="startStkPush">
                <div class="form-field"><label for="stk-entity">Payment for</label><select id="stk-entity" v-model="stkForm.entity_type" class="select-field"><option value="hire">Gown hire</option><option value="custom_order">Custom order</option></select></div>
                <div v-if="stkForm.entity_type === 'hire'" class="form-field"><label for="stk-hire">Hire</label><SearchableSelect id="stk-hire" v-model="stkForm.hire_id" :options="stkHireOptions" label="Hire" placeholder="Select a hire" search-placeholder="Search reference or customer…"/><small v-if="stkForm.errors.hire_id" class="form-error">{{ stkForm.errors.hire_id }}</small></div>
                <div v-else class="form-field"><label for="stk-order">Custom order</label><SearchableSelect id="stk-order" v-model="stkForm.custom_order_id" :options="stkOrderOptions" label="Custom order" placeholder="Select an order" search-placeholder="Search order or customer…"/><small v-if="stkForm.errors.custom_order_id" class="form-error">{{ stkForm.errors.custom_order_id }}</small></div>
                <div class="form-field"><label for="stk-purpose">Purpose</label><select id="stk-purpose" v-model="stkForm.purpose" class="select-field"><template v-if="stkForm.entity_type === 'hire'"><option value="service_charge" :disabled="stkHire?.status === 'reserved'">Service charge</option><option value="penalty">Assessed penalty</option><option value="deposit">Refundable deposit</option></template><template v-else><option value="custom_order_charge">Agreed order charge</option><option value="custom_order_deposit">Required deposit</option></template></select><small v-if="stkForm.errors.purpose" class="form-error">{{ stkForm.errors.purpose }}</small></div>
                <div class="form-field"><label for="stk-amount">Whole KES amount</label><input id="stk-amount" v-model="stkForm.amount" class="field" inputmode="numeric" placeholder="e.g. 750" required><small v-if="stkForm.errors.amount" class="form-error">{{ stkForm.errors.amount }}</small></div>
                <div class="form-field"><label for="stk-phone">Customer M-Pesa number</label><input id="stk-phone" v-model="stkForm.phone" class="field" type="tel" autocomplete="tel" placeholder="07… or +254…" required><small v-if="stkForm.errors.phone" class="form-error">{{ stkForm.errors.phone }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="stkForm.processing">{{ stkForm.processing ? 'Sending prompt…' : 'Send M-Pesa prompt' }}</button><small v-if="stkErrors.mpesa" class="form-error">{{ stkErrors.mpesa }}</small><small v-if="stkErrors.idempotency_key" class="form-error">{{ stkErrors.idempotency_key }}</small></div>
            </form>
            <div v-if="stkPushes.length" class="table-wrap stk-history"><table class="data-table"><thead><tr><th>STK reference</th><th>Customer</th><th>Amount</th><th>Status</th><th>M-Pesa receipt</th><th v-if="canReconcileMpesa">Reconcile</th></tr></thead><tbody><tr v-for="push in stkPushes" :key="push.id"><td>{{ push.reference }}<small class="table-subline">{{ push.purpose.replaceAll('_', ' ') }}</small></td><td>{{ push.customer.name }}</td><td class="amount">{{ kes(push.amount_minor) }}</td><td><span class="status-chip" :class="push.status === 'paid' ? 'completed' : push.status === 'failed' ? 'damaged' : 'due'">{{ push.status }}</span><small v-if="push.result_description" class="table-subline">{{ push.result_description }}</small></td><td>{{ push.mpesa_receipt ?? '—' }}</td><td v-if="canReconcileMpesa"><button v-if="['pending','review'].includes(push.status) && push.checkout_request_id" class="secondary-link compact-action" type="button" @click="reconcileStk(push)">Check status</button><small v-if="push.last_status_check_at" class="table-subline">Checked {{ dateLabel(push.last_status_check_at) }}</small><span v-if="['pending','review'].includes(push.status) && !push.checkout_request_id" class="table-subline">Needs manual provider review</span></td></tr></tbody></table></div>
        </section>
        <section v-if="section === 'deposits' && canRecord && depositOrderOptions.length" id="custom-order-deposit-application" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Apply to a custom order</h2><p>Choose an invoiced order with a remaining balance, then apply available deposit funds. The application reduces the charge due without creating a new receipt.</p></div></div>
            <form class="receipt-form deposit-application-form" @submit.prevent="applyDeposit">
                <div class="form-field"><label for="deposit-application-order">Custom order</label><SearchableSelect id="deposit-application-order" v-model="depositApplicationForm.custom_order_id" :options="depositOrderOptions" label="Custom order" placeholder="Select an invoiced order" search-placeholder="Search order or customer…"/><small v-if="depositApplicationForm.errors.custom_order_id" class="form-error">{{ depositApplicationForm.errors.custom_order_id }}</small></div>
                <div class="form-field"><label for="deposit-application-receipt">Deposit receipt</label><SearchableSelect id="deposit-application-receipt" v-model="depositApplicationForm.payment_id" :options="applicableDeposits" label="Deposit receipt" placeholder="Select available receipt" search-placeholder="Search receipt or customer…" :disabled="!depositApplicationForm.custom_order_id"/><small v-if="depositApplicationForm.errors.payment_id" class="form-error">{{ depositApplicationForm.errors.payment_id }}</small></div>
                <div class="form-field"><label for="deposit-application-amount">Amount to apply (KES)</label><input id="deposit-application-amount" v-model="depositApplicationForm.amount" class="field" type="number" inputmode="decimal" min="0.01" step="0.01" placeholder="0.00" required :max="(maxOrderDepositApplicationMinor / 100).toFixed(2)"><small v-if="selectedDepositReceipt">Up to {{ kes(maxOrderDepositApplicationMinor) }} can be applied.</small><small v-if="depositApplicationForm.errors.amount" class="form-error">{{ depositApplicationForm.errors.amount }}</small><small v-if="depositApplicationForm.errors.idempotency_key" class="form-error">{{ depositApplicationForm.errors.idempotency_key }}</small></div>
                <div class="form-actions"><button v-if="maxOrderDepositApplicationMinor > 0" class="secondary-link deposit-use-maximum" type="button" @click="depositApplicationForm.amount = (maxOrderDepositApplicationMinor / 100).toFixed(2)">Use maximum {{ kes(maxOrderDepositApplicationMinor) }}</button><button class="primary-button" type="submit" :disabled="depositApplicationForm.processing || !maxOrderDepositApplicationMinor">{{ depositApplicationForm.processing ? 'Applying…' : 'Apply deposit to charge' }}</button></div>
            </form>
        </section>
        <section v-if="section === 'deposits' && canRecord && depositHireOptions.length" id="hire-deposit-application" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Apply to a gown hire</h2><p>Choose an issued, invoiced hire with an outstanding service charge. Reservation deposits become eligible after gown issue.</p></div></div>
            <form class="receipt-form deposit-application-form" @submit.prevent="applyHireDeposit">
                <div class="form-field"><label for="hire-deposit-hire">Hire</label><SearchableSelect id="hire-deposit-hire" v-model="hireDepositForm.hire_id" :options="depositHireOptions" label="Hire" placeholder="Select an invoiced hire" search-placeholder="Search hire or customer…"/><small v-if="hireDepositForm.errors.hire_id" class="form-error">{{ hireDepositForm.errors.hire_id }}</small></div>
                <div class="form-field"><label for="hire-deposit-receipt">Deposit receipt</label><SearchableSelect id="hire-deposit-receipt" v-model="hireDepositForm.payment_id" :options="applicableHireDeposits" label="Deposit receipt" placeholder="Select available receipt" search-placeholder="Search receipt or customer…" :disabled="!hireDepositForm.hire_id"/><small v-if="hireDepositForm.errors.payment_id" class="form-error">{{ hireDepositForm.errors.payment_id }}</small></div>
                <div class="form-field"><label for="hire-deposit-amount">Amount to apply (KES)</label><input id="hire-deposit-amount" v-model="hireDepositForm.amount" class="field" type="number" inputmode="decimal" min="0.01" step="0.01" placeholder="0.00" required :max="(maxHireDepositApplicationMinor / 100).toFixed(2)"><small v-if="selectedHireDeposit">Up to {{ kes(maxHireDepositApplicationMinor) }} can be applied.</small><small v-if="hireDepositForm.errors.amount" class="form-error">{{ hireDepositForm.errors.amount }}</small><small v-if="hireDepositForm.errors.idempotency_key" class="form-error">{{ hireDepositForm.errors.idempotency_key }}</small></div>
                <div class="form-actions"><button v-if="maxHireDepositApplicationMinor > 0" class="secondary-link deposit-use-maximum" type="button" @click="hireDepositForm.amount = (maxHireDepositApplicationMinor / 100).toFixed(2)">Use maximum {{ kes(maxHireDepositApplicationMinor) }}</button><button class="primary-button" type="submit" :disabled="hireDepositForm.processing || !maxHireDepositApplicationMinor">{{ hireDepositForm.processing ? 'Applying…' : 'Apply deposit to charge' }}</button></div>
            </form>
        </section>
        <section v-if="section === 'penalties' && canDecidePenaltyWaivers && independentPenaltyApproval && penaltyWaiverRequests.some((waiver) => waiver.requesterId !== actorId)" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Penalty waiver approvals</h2><p>Each request requires a different approver. Approval applies the configured amount threshold and posts the waiver journal.</p></div></div>
            <div class="approval-list"><article v-for="waiver in penaltyWaiverRequests.filter((request) => request.requesterId !== actorId)" :key="waiver.id" class="approval-item"><div><strong>{{ waiver.reference }} · {{ waiver.hire }}</strong><small>{{ waiver.customer }} · requested by {{ waiver.requestedBy }} · {{ kes(waiver.amountMinor) }}</small><small>{{ waiver.reason }}</small></div><div class="approval-actions"><button class="primary-button" type="button" @click="decidePenaltyWaiver(waiver, 'approved')">Approve</button><button class="secondary-link" type="button" @click="decidePenaltyWaiver(waiver, 'rejected')">Reject</button></div></article></div>
        </section>
        <section v-if="section === 'penalties' && businessApprovals.length" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Finance configuration approvals</h2><p>Review the configuration summary and compare it with the signed accountant or cutover evidence before approving.</p></div></div>
            <article v-for="configuration in businessApprovals.filter((record) => record.createdBy !== actorId)" :key="configuration.id" class="approval-item"><div><strong>{{ configuration.title }} · effective {{ configuration.effectiveFrom ?? 'on approval' }}</strong><small>{{ configuration.summary }}</small></div><div class="approval-actions"><input v-model="financeApprovalForm.approval_reference" class="field approval-reference" :aria-label="`${configuration.title} approval reference`" placeholder="Approval evidence reference" maxlength="255"><button class="primary-button" type="button" :disabled="financeApprovalForm.processing || financeApprovalForm.approval_reference.trim().length < 3" @click="approveBusinessConfiguration(configuration)">Approve</button></div></article>
            <small v-for="(message, field) in financeApprovalForm.errors" :key="field" class="form-error">{{ message }}</small>
        </section>
        <div v-if="section === 'penalties'" class="penalty-forms-grid">
        <section v-if="canWaivePenalty && waiverHires.length" class="panel receipt-form-panel penalty-form-card">
            <div class="receipt-heading"><div><h2>{{ independentPenaltyApproval ? 'Request a penalty waiver' : 'Approve a penalty waiver' }}</h2><p>{{ independentPenaltyApproval ? 'A different finance or management user must approve the request before it reduces the receivable.' : 'Waivers reduce the unpaid receivable, enforce the approved role limits and retain an audit record.' }}</p></div></div>
            <form class="receipt-form" @submit.prevent="waivePenalty">
                <div class="form-field full"><label for="waiver-hire">Hire with an outstanding late fee</label><SearchableSelect id="waiver-hire" v-model="waiverForm.hire_id" :options="waiverHireOptions" label="Hire" placeholder="Select a hire" search-placeholder="Search hire reference or customer…"/><small v-if="waiverForm.errors.hire_id" class="form-error">{{ waiverForm.errors.hire_id }}</small></div>
                <div class="form-field"><label for="waiver-amount">Amount (KES)</label><input id="waiver-amount" v-model="waiverForm.amount" class="field" inputmode="decimal" required><small v-if="waiverForm.errors.amount" class="form-error">{{ waiverForm.errors.amount }}</small></div>
                <div class="form-field full"><label for="waiver-reason">Approval reason</label><textarea id="waiver-reason" v-model="waiverForm.reason" class="field" rows="2" minlength="5" maxlength="1000" required></textarea><small v-if="waiverForm.errors.reason" class="form-error">{{ waiverForm.errors.reason }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="waiverForm.processing">{{ waiverForm.processing ? 'Saving…' : independentPenaltyApproval ? 'Submit for approval' : 'Approve waiver' }}</button></div>
            </form>
        </section>
        <section v-if="canRefund && refundablePayments.length" class="panel receipt-form-panel penalty-form-card">
            <div class="receipt-heading"><div><h2>Record a refund</h2><p>Refunds are append-only reversals. M-Pesa transfers are processed separately outside this system.</p></div></div>
            <form class="receipt-form" @submit.prevent="recordRefund">
                <div class="form-field full"><label for="refund-payment">Receipt to refund</label><SearchableSelect id="refund-payment" v-model="refundForm.payment_id" :options="refundablePaymentOptions" label="Receipt" placeholder="Select a refundable receipt" search-placeholder="Search receipt or customer…"/><small v-if="refundForm.errors.payment_id" class="form-error">{{ refundForm.errors.payment_id }}</small></div>
                <div class="form-field"><label for="refund-amount">Amount (KES)</label><input id="refund-amount" v-model="refundForm.amount" class="field" inputmode="decimal" required><small v-if="refundForm.errors.amount" class="form-error">{{ refundForm.errors.amount }}</small></div>
                <div class="form-field"><label for="refund-method">Refund method</label><select id="refund-method" v-model="refundForm.method" class="select-field"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mpesa">M-Pesa (manual)</option><option value="other">Other</option></select></div>
                <div v-if="refundForm.method === 'mpesa'" class="form-field"><label for="refund-provider">M-Pesa reference</label><input id="refund-provider" v-model="refundForm.provider_reference" class="field" required maxlength="120"></div>
                <div class="form-field full"><label for="refund-reason">Reason</label><textarea id="refund-reason" v-model="refundForm.reason" class="field" rows="2" required minlength="5" maxlength="1000"></textarea><small v-if="refundForm.errors.reason" class="form-error">{{ refundForm.errors.reason }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="refundForm.processing">{{ refundForm.processing ? 'Recording…' : 'Record refund' }}</button></div>
            </form>
        </section>
        </div>
        <section v-if="section === 'receipts'" class="panel table-panel">
            <div v-if="payments.data.length" class="table-wrap" role="region" aria-label="Customer receipts" tabindex="0">
                <table class="data-table"><thead><tr><th>Receipt</th><th>Customer</th><th>Hire / order reference</th><th>Received</th><th>Method</th><th>Purpose</th><th>Gross</th><th>Refunded</th><th>Net</th><th>Action</th></tr></thead>
                    <tbody><tr v-for="payment in payments.data" :key="payment.id"><td><strong>{{ payment.reference }}</strong><small class="table-subline">{{ payment.provider_reference ?? 'Internal receipt' }}</small></td><td>{{ payment.customer.name }}</td><td>{{ payment.hire?.reference ?? payment.custom_order?.reference ?? payment.bulk_sale?.reference ?? '—' }}</td><td>{{ dateLabel(payment.received_at) }}</td><td>{{ payment.method.replaceAll('_', ' ') }}</td><td>{{ payment.purpose.replaceAll('_', ' ') }}</td><td class="amount">{{ kes(payment.amount_minor) }}</td><td class="amount">{{ kes(payment.refunded_minor ?? 0) }}</td><td class="amount">{{ kes(payment.amount_minor - (payment.refunded_minor ?? 0)) }}</td><td><div class="receipt-row-actions"><a :href="`/payments/receipts/${payment.id}/download`" class="receipt-row-download" :aria-label="`Download standard receipt ${payment.reference}`"><Download aria-hidden="true" /> PDF</a><a :href="`/payments/receipts/${payment.id}/thermal`" class="receipt-row-download thermal" :aria-label="`Download thermal receipt ${payment.reference}`" title="80 mm thermal receipt"><Download aria-hidden="true" /> Thermal</a></div></td></tr></tbody>
                    <tfoot><tr><th colspan="6" scope="row">Page total <small>Current rows only</small></th><td class="amount">{{ kes(receiptPageTotals.gross) }}</td><td class="amount">{{ kes(receiptPageTotals.refunded) }}</td><td class="amount">{{ kes(receiptPageTotals.net) }}</td><td></td></tr></tfoot>
                </table>
            </div>
            <div v-else class="empty-state"><div><Banknote aria-hidden="true" /><strong>No receipts recorded</strong><span>Recorded receipts will appear here for your authorized locations.</span></div></div>
            <Pagination v-if="payments.total > 0" :links="payments.links" :from="payments.from" :to="payments.to" :total="payments.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.finance-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 13px; }
.expense-summary-cards { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:0 0 14px; }
.expense-summary-card { display:flex; min-height:98px; flex-direction:column; justify-content:center; gap:5px; padding:15px 17px; border-top:3px solid #dfe5ed; }
.expense-summary-card span { color:#657080; font-size:12px; font-weight:600; }
.expense-summary-card strong { color:#172033; font-size:22px; line-height:1.15; }
.expense-summary-card small { color:#657080; font-size:11px; }
.expense-summary-card.pending { border-top-color:#d8a63c; }
.expense-summary-card.pending strong { color:#8b5b08; }
.deposit-summary-cards { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:14px 0; }
.deposit-summary-card { display:flex; min-height:112px; flex-direction:column; justify-content:center; gap:6px; padding:16px; }
.deposit-summary-card span { color:#657080; font-size:12px; }
.deposit-summary-card strong { color:#172033; font-size:22px; }
.deposit-summary-card small { color:#657080; font-size:11px; }
.deposit-summary-card.total { border-color:#e9c7ca; background:linear-gradient(135deg,#fff8f8,#fff 70%); }
.deposit-summary-card.total strong { color:#a51d2a; }
.penalty-summary-cards { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin:0 0 13px; }
.penalty-summary-card { display:flex; min-height:96px; flex-direction:column; justify-content:center; gap:5px; padding:15px 17px; border-top:3px solid #e1e5eb; }
.penalty-summary-card span { color:#657080; font-size:12px; font-weight:600; }
.penalty-summary-card strong { color:#172033; font-size:22px; line-height:1.15; }
.penalty-summary-card small { color:#657080; font-size:11px; }
.penalty-summary-card.outstanding { border-top-color:#d5a643; background:linear-gradient(135deg,#fffaf0,#fff 72%); }
.penalty-summary-card.outstanding strong { color:#87580b; }
.deposit-register { margin-bottom:14px; }
.deposit-type-chip { display:inline-flex; padding:4px 8px; border:1px solid #e4e7ec; border-radius:99px; background:#f7f8fa; color:#4b5563; font-size:11px; font-weight:650; white-space:nowrap; }
.deposit-available-amount { color:#172033; font-weight:700; white-space:nowrap; }
.deposit-apply-action { min-height:32px; padding:6px 10px; border:1px solid #edc4c8; border-radius:6px; background:#fff7f7; color:#a51d2a; font-size:12px; font-weight:650; cursor:pointer; white-space:nowrap; }
.deposit-apply-action:hover { border-color:#c91f2e; background:#fff0f1; }
.deposit-wait-reason { color:#697384; font-size:12px; white-space:nowrap; }
.deposit-empty { display:flex; align-items:center; gap:14px; padding:20px; border:1px dashed #d9dde3; border-radius:8px; background:#fafbfc; }
.deposit-empty > svg { width:22px; height:22px; flex:none; color:#9aa3af; }
.deposit-empty > div { display:grid; gap:4px; flex:1; }
.deposit-empty > div strong { color:#252b36; font-size:13px; }
.deposit-empty > div span { color:#657080; font-size:12px; line-height:1.45; }
.download-receipts { display:inline-flex; align-items:center; gap:7px; }
.download-receipts svg { width:16px; height:16px; }
.receipt-row-actions { display:flex; align-items:center; gap:6px; }
.receipt-row-download { display:inline-flex; align-items:center; justify-content:center; gap:5px; min-height:32px; padding:6px 9px; border:1px solid #e5c5c8; border-radius:6px; background:#fff8f8; color:#a51d2a; font-size:12px; font-weight:650; text-decoration:none; white-space:nowrap; }
.receipt-row-download.thermal { border-color:#d6dbe3; background:#f7f8fa; color:#394252; }
.receipt-row-download:hover { border-color:#c91f2e; background:#fff0f1; }
.receipt-row-download.thermal:hover { border-color:#687386; background:#edf0f4; }
.receipt-row-download:focus-visible { outline:3px solid #d72b3830; outline-offset:2px; }
.receipt-row-download svg { width:14px; height:14px; }
.payments-overview-links { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin:14px 0; }
.finance-workspaces { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:13px; margin:14px 0; }
.finance-workspace-card { display:grid; grid-template-columns:42px minmax(0,1fr); align-items:center; column-gap:13px; row-gap:8px; padding:17px; border:1px solid #e3e6eb; border-radius:10px; background:#fff; color:#202938; text-decoration:none; transition:border-color .16s ease,transform .16s ease,box-shadow .16s ease; }
.finance-workspace-card:hover { border-color:#d72b38; transform:translateY(-1px); box-shadow:0 6px 18px #17203312; }
.workspace-icon { grid-row:span 2; width:42px; height:42px; display:grid; place-items:center; border-radius:10px; background:#fff0f1; color:#c91f2e; }
.workspace-icon svg { width:20px; height:20px; }
.workspace-copy { display:grid; gap:4px; }
.workspace-copy strong { font-size:14px; }
.workspace-copy small { color:#657080; font-size:12px; line-height:1.45; }
.workspace-arrow { grid-column:2; color:#a51d2a; font-size:12px; font-weight:650; }
.workspace-arrow span { margin-left:3px; }
.payment-shortcut { display:flex; align-items:center; gap:13px; min-height:82px; padding:16px; border:1px solid #e3e6eb; border-radius:10px; background:#fff; color:#202938; text-decoration:none; transition:border-color .16s ease,transform .16s ease,box-shadow .16s ease; }
.payment-shortcut:hover { border-color:#d72b38; transform:translateY(-1px); box-shadow:0 6px 18px #17203312; }
.payment-shortcut > svg { width:21px; height:21px; color:#c91f2e; flex:none; }
.payment-shortcut span { display:grid; gap:4px; }
.payment-shortcut small { color:#657080; font-size:12px; }
.table-panel tfoot { border-top:2px solid #dfe3e8; background:#f5f7fa; }
.table-panel tfoot th,.table-panel tfoot td { padding-top:13px; padding-bottom:13px; color:#172033; font-size:13px; font-weight:700; }
.table-panel tfoot th { text-align:left; }
.table-panel tfoot th small { display:block; margin-top:3px; color:#657080; font-size:11px; font-weight:500; }
.receipt-form-panel { margin-bottom: 13px; padding: 17px; }
.expense-form-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; }
.expense-form-heading p { max-width:720px; }
.expense-review-note { flex:none; padding:8px 11px; border:1px solid #e6e9ee; border-radius:8px; background:#f8f9fb; color:#596579; font-size:11px; font-weight:600; }
.expense-review-note span { padding:0 4px; color:#c22532; }
.expense-form { grid-template-columns:repeat(3,minmax(0,1fr)); align-items:start; }
.expense-form .form-field.full,.expense-form .form-actions { grid-column:1/-1; }
.expense-file { min-height:40px; padding:6px; font-size:12px; }
.expense-table-heading { display:flex; align-items:center; justify-content:space-between; gap:15px; padding:16px 18px; border-bottom:1px solid #e5e8ed; }
.expense-table-heading h2 { margin:0; color:#172033; font-size:16px; }
.expense-table-heading p { max-width:720px; margin:5px 0 0; color:#657080; font-size:12px; line-height:1.45; }
.expense-empty-state { width:100%; min-height:190px; padding:22px; }
.expense-empty-state strong { font-size:14px; }
.expense-empty-state span { max-width:360px; font-size:12px; }
.expense-table { min-width:1120px; }
.expense-description { max-width:300px; white-space:normal; line-height:1.4; }
.expense-category-chip { display:inline-flex; padding:4px 9px; border:1px solid #e2e6ec; border-radius:999px; background:#f7f8fa; color:#384253; font-size:11px; font-weight:600; text-transform:capitalize; white-space:nowrap; }
.expense-amount { color:#172033; font-weight:700; white-space:nowrap; }
.expense-status-stack { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.expense-row-actions { display:flex; gap:6px; flex-wrap:wrap; }
.expense-action { display:inline-flex; min-height:31px; align-items:center; justify-content:center; padding:5px 9px; border:1px solid #dfe4ea; border-radius:6px; background:#fff; color:#364152; font-family:inherit; font-size:11px; font-weight:600; text-decoration:none; cursor:pointer; white-space:nowrap; }
.expense-action.approve { border-color:#c5e4d1; background:#f1faf4; color:#187344; }
.expense-action.reject { border-color:#f0d0d2; background:#fff7f7; color:#a51d2a; }
.expense-action.evidence { background:#f6f8fb; }
.expense-action:hover { filter:brightness(.97); }
.expense-action:focus-visible { outline:3px solid #d72b3830; outline-offset:2px; }
.expense-decision-reason { display:block; max-width:220px; margin-top:5px; color:#7b3b36; line-height:1.35; white-space:normal; }
.penalty-forms-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); align-items:start; gap:14px; }
.penalty-form-card { min-width:0; height:100%; }
.penalty-form-card .receipt-heading h2 { font-size:16px; }
.penalty-form-card .receipt-heading p { max-width:620px; font-size:12px; line-height:1.5; }
.penalty-form-card .form-actions { justify-content:flex-end; }
.receipt-heading h2 { margin: 0; color: #171c26; font-size: 14px; }
.receipt-heading p { margin: 4px 0 13px; color: #606874; font-size: 10px; }
.approval-list { display: grid; gap: 8px; }
.approval-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; border: 1px solid #e7e8ea; border-radius: 8px; padding: 10px 12px; }
.approval-item > div:first-child { display: grid; gap: 4px; }
.approval-item strong { color: #202633; font-size: 11px; }
.approval-item small { color: #606874; font-size: 10px; }
.approval-actions { display: flex; align-items: center; gap: 8px; }
.approval-reference { min-width: 220px; }
.receipt-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: end; }
.payment-receipt-form { grid-template-columns:minmax(160px,.85fr) minmax(280px,1.6fr) minmax(160px,1fr); align-items:start; }
.deposit-application-form { grid-template-columns:repeat(3,minmax(0,1fr)); align-items:start; }
.deposit-application-form .form-actions { grid-column:1/-1; display:flex; align-items:center; justify-content:flex-end; gap:9px; flex-wrap:wrap; }
.deposit-use-maximum { min-height:38px; display:inline-flex; align-items:center; justify-content:center; padding:8px 11px; font-size:12px; white-space:nowrap; }
.receipt-form .full { grid-column: 1 / -1; }
.receipt-form .form-actions { grid-column: 1 / -1; justify-content: flex-start; margin-top: 0; }
.finance-card { display: flex; min-height: 116px; flex-direction: column; gap: 8px; padding: 17px; }
.finance-card span { color: #68707e; font-size: 11px; }
.finance-card strong { color: #151a24; font-size: 25px; letter-spacing: -.03em; }
.finance-card small { color: #606874; font-size: 10px; }
@media(max-width:980px) { .finance-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } .deposit-summary-cards { grid-template-columns:repeat(2,minmax(0,1fr)); } .deposit-application-form { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:1000px) { .expense-form { grid-template-columns:repeat(2,minmax(0,1fr)); } .expense-review-note { display:none; } }
@media(max-width:1000px) { .payment-receipt-form { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:700px) { .finance-cards { grid-template-columns: 1fr; gap: 8px; } .payments-overview-links,.finance-workspaces { grid-template-columns:1fr; } .deposit-summary-cards,.deposit-application-form,.expense-summary-cards,.penalty-summary-cards { grid-template-columns:1fr; } .expense-summary-card { min-height:80px; } .expense-form { grid-template-columns:1fr; } .expense-form .form-field.full,.expense-form .form-actions { grid-column:auto; } .expense-form-heading,.expense-table-heading { align-items:flex-start; flex-direction:column; } .deposit-summary-card { min-height:92px; } .deposit-empty { align-items:flex-start; flex-wrap:wrap; } .deposit-empty > div { min-width:calc(100% - 44px); } .finance-card { min-height: 95px; }.receipt-form { grid-template-columns: 1fr; }.receipt-form .full,.receipt-form .form-actions { grid-column: auto; } }
@media(max-width:640px) { .penalty-forms-grid { grid-template-columns:1fr; } }
</style>
