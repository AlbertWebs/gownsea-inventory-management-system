<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, CircleHelp, Receipt } from '@lucide/vue';
import { computed, watch } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type Payment = { id: number; reference: string; amount_minor: number; refunded_minor: number | null; hire_deposit_applied_minor?: number | null; currency: string; method: string; purpose: string; status: string; provider_reference: string | null; received_at: string; customer: { name: string }; hire: { reference: string } | null; custom_order: { reference: string } | null; bulk_sale: { reference: string } | null };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
type HireOption = { id: number; reference: string; customer: string; status: string; chargeTotalMinor: number; chargePaidMinor: number; penaltyTotalMinor: number; penaltyPaidMinor: number; depositMinor: number };
type CustomOrderOption = { id: number; reference: string; customer: string; status: string; chargeTotalMinor: number; chargePaidMinor: number; depositRequiredMinor: number; depositPaidMinor: number };
type CustomOrderDeposit = { id: number; orderId: number; reference: string; customer: string | null; availableMinor: number };
type HireDeposit = { id: number; hireId: number; reference: string; customer: string | null; availableMinor: number };
type Expense = { id: number; reference: string; amount_minor: number; category: string; vendor: string | null; receipt_reference: string | null; incurred_on: string; status: string; payment_status: string; payment_method: string | null; payment_reference: string | null; paid_on: string | null; has_receipt: boolean; description: string; decision_reason: string | null; created_by: number; location: { name: string }; creator: { name: string }; decision_maker: { name: string } | null; paid_by: { name: string } | null };
type ExpenseLocation = { id: number; name: string };
type StkPush = { id: number; reference: string; amount_minor: number; purpose: string; phone: string; status: string; result_description: string | null; mpesa_receipt: string | null; checkout_request_id: string | null; last_status_check_at: string | null; requested_at: string; customer: { name: string } };
type PenaltyWaiver = { id: number; reference: string; hire: string; customer: string; amountMinor: number; reason: string; requestedBy: string; requesterId: number };
type BusinessApproval = { id: number; section: string; title: string; effectiveFrom: string | null; createdBy: number | null; summary: string };
const props = defineProps<{ payments: Page<Payment>; expenses: Page<Expense>; expenseLocations: ExpenseLocation[]; hires: HireOption[]; customOrders: CustomOrderOption[]; customOrderDeposits: CustomOrderDeposit[]; hireDepositReceipts: HireDeposit[]; stkPushes: StkPush[]; penaltyWaiverRequests: PenaltyWaiver[]; businessApprovals: BusinessApproval[]; independentPenaltyApproval: boolean; canDecidePenaltyWaivers: boolean; darajaEnabled: boolean; canRecord: boolean; canReconcileMpesa: boolean; canRefund: boolean; canWaivePenalty: boolean; canSubmitExpense: boolean; canDecideExpenses: boolean; canMarkExpensesPaid: boolean; actorId: number; totals: { receivedCharges: number; depositsHeld: number; penaltiesDue: number; penaltyAccruingToday: number; approvedExpenses: number; pendingExpenses: number; count: number } }>();
const kes = (amount: number): string => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 0 }).format(amount / 100);
const dateLabel = (value: string): string => new Date(value).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' });
const paymentForm = useForm({ entity_type: 'hire', hire_id: '', custom_order_id: '', amount: '', method: 'cash', purpose: 'service_charge', provider_reference: '', idempotency_key: crypto.randomUUID() });
const selectedHire = computed(() => props.hires.find((hire) => String(hire.id) === paymentForm.hire_id));
const selectedCustomOrder = computed(() => props.customOrders.find((order) => String(order.id) === paymentForm.custom_order_id));
const depositApplicationForm = useForm({ custom_order_id: '', payment_id: '', amount: '', idempotency_key: crypto.randomUUID() });
const selectedDepositReceipt = computed(() => props.customOrderDeposits.find((payment) => String(payment.id) === depositApplicationForm.payment_id));
const applicableDeposits = computed(() => props.customOrderDeposits.filter((payment) => String(payment.orderId) === depositApplicationForm.custom_order_id));
watch(() => depositApplicationForm.custom_order_id, () => { depositApplicationForm.payment_id = ''; depositApplicationForm.amount = ''; });
function applyDeposit(): void {
    depositApplicationForm.post('/payments/custom-order-deposits/apply', { preserveScroll: true, onSuccess: () => { depositApplicationForm.reset('custom_order_id', 'payment_id', 'amount'); depositApplicationForm.idempotency_key = crypto.randomUUID(); } });
}
const hireDepositForm = useForm({ hire_id: '', payment_id: '', amount: '', idempotency_key: crypto.randomUUID() });
const selectedHireDeposit = computed(() => props.hireDepositReceipts.find((payment) => String(payment.id) === hireDepositForm.payment_id));
const applicableHireDeposits = computed(() => props.hireDepositReceipts.filter((payment) => String(payment.hireId) === hireDepositForm.hire_id));
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
function recordRefund(): void {
    refundForm.post('/payments/refunds', { preserveScroll: true, onSuccess: () => { refundForm.reset('amount', 'provider_reference', 'reason'); refundForm.idempotency_key = crypto.randomUUID(); } });
}
const waiverForm = useForm({ hire_id: '', amount: '', reason: '', idempotency_key: crypto.randomUUID() });
const financeApprovalForm = useForm({ approval_reference: '', confirm: true });
const waiverHires = computed(() => props.hires.filter((hire) => hire.penaltyTotalMinor > hire.penaltyPaidMinor));
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
    <Head title="Sales & payments" />
    <AppShell active="payments">
        <div class="page-heading"><div><h1>Sales & payments</h1><p>Recorded customer receipts with separate charge and refundable deposit balances.</p></div><div class="heading-actions"><Link href="/finance/vendor-bills" class="secondary-link">Vendor bills</Link><Link href="/finance/invoices" class="secondary-link">Customer invoices</Link><Link href="/finance/ledger" class="secondary-link">General ledger</Link><Link href="/finance" class="secondary-link">Finance overview</Link></div></div>
        <div class="finance-cards">
            <article class="panel finance-card"><span>Charge receipts</span><strong>{{ kes(totals.receivedCharges) }}</strong><small>Hire/custom order charges and collected penalties, net of refunds</small></article>
            <article class="panel finance-card"><span>Refundable deposits received</span><strong>{{ kes(totals.depositsHeld) }}</strong><small>Tracked separately from earned charges</small></article>
            <article class="panel finance-card"><span>Late-fee receivable</span><strong>{{ kes(totals.penaltiesDue) }}</strong><small>Assessed to date, less receipts and waivers</small></article>
            <article class="panel finance-card"><span>Receipts recorded</span><strong>{{ totals.count.toLocaleString() }}</strong><small>Visible for your authorized locations</small></article>
            <article class="panel finance-card"><span>Approved operating expenses</span><strong>{{ kes(totals.approvedExpenses) }}</strong><small>{{ totals.pendingExpenses }} expense(s) awaiting management review</small></article>
        </div>
        <div class="workflow-note"><CircleHelp :size="16" aria-hidden="true" /> Manual receipts and append-only refunds are tracked here. Late fees on outstanding hire units accrue into the receivable daily using the terms snapshotted at issue; the amount already accrued can be collected or waived before return. At physical return, the final amount is frozen for those units and further accrual stops. Held deposits remain separate from revenue. M-Pesa transfers are not initiated by this screen.</div>
        <section v-if="canSubmitExpense" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Submit operating expense</h2><p>Expenses are pending until a different manager reviews them. Approved totals are shown separately from customer receipts.</p></div></div>
            <form class="receipt-form" @submit.prevent="submitExpense">
                <div class="form-field"><label for="expense-location">Location</label><select id="expense-location" v-model="expenseForm.location_id" class="select-field" required><option v-for="location in expenseLocations" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="expenseForm.errors.location_id" class="form-error">{{ expenseForm.errors.location_id }}</small></div>
                <div class="form-field"><label for="expense-category">Category</label><select id="expense-category" v-model="expenseForm.category" class="select-field"><option value="transport">Transport</option><option value="labor">Labor</option><option value="repairs">Repairs</option><option value="sms">SMS</option><option value="delivery">Delivery</option><option value="other">Other</option></select></div>
                <div class="form-field"><label for="expense-amount">Amount (KES)</label><input id="expense-amount" v-model="expenseForm.amount" class="field" inputmode="decimal" required><small v-if="expenseForm.errors.amount" class="form-error">{{ expenseForm.errors.amount }}</small></div>
                <div class="form-field"><label for="expense-date">Expense date</label><input id="expense-date" v-model="expenseForm.incurred_on" class="field" type="date" required><small v-if="expenseForm.errors.incurred_on" class="form-error">{{ expenseForm.errors.incurred_on }}</small></div>
                <div class="form-field"><label for="expense-vendor">Vendor (optional)</label><input id="expense-vendor" v-model="expenseForm.vendor" class="field" maxlength="160"></div>
                <div class="form-field"><label for="expense-receipt">Receipt reference (optional)</label><input id="expense-receipt" v-model="expenseForm.receipt_reference" class="field" maxlength="120"></div>
                <div class="form-field"><label for="expense-attachment">Receipt attachment (optional)</label><input id="expense-attachment" class="field" type="file" accept="image/jpeg,image/png,application/pdf" @change="setExpenseReceipt"><small>PDF, JPG or PNG; maximum 10 MB.</small><small v-if="expenseForm.errors.receipt" class="form-error">{{ expenseForm.errors.receipt }}</small></div>
                <div class="form-field full"><label for="expense-description">Description</label><textarea id="expense-description" v-model="expenseForm.description" class="field" rows="2" minlength="5" maxlength="2000" required></textarea><small v-if="expenseForm.errors.description" class="form-error">{{ expenseForm.errors.description }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="expenseForm.processing || !expenseLocations.length"><Receipt aria-hidden="true" /> {{ expenseForm.processing ? 'Submitting…' : 'Submit for approval' }}</button></div>
            </form>
        </section>
        <section class="panel table-panel">
            <div class="receipt-heading"><div><h2>Operating expenses</h2><p>Use this approval workflow for small expenses settled through cash or a single payment. Use Vendor Bills for supplier invoices, due balances, and partial payments.</p></div><Link href="/finance/vendor-bills" class="secondary-link">Open vendor bills</Link></div>
            <div v-if="expenses.data.length" class="table-wrap"><table class="data-table"><thead><tr><th>Expense</th><th>Date / location</th><th>Category</th><th>Vendor / receipt</th><th>Submitted by</th><th>Amount</th><th>Approval / payment</th><th>Review / evidence</th></tr></thead><tbody><tr v-for="expense in expenses.data" :key="expense.id"><td><strong>{{ expense.reference }}</strong><small class="table-subline">{{ expense.description }}</small></td><td>{{ expense.incurred_on }}<small class="table-subline">{{ expense.location.name }}</small></td><td>{{ expense.category.replaceAll('_', ' ') }}</td><td>{{ expense.vendor ?? '—' }}<small v-if="expense.receipt_reference" class="table-subline">{{ expense.receipt_reference }}</small></td><td>{{ expense.creator.name }}</td><td class="amount">{{ kes(expense.amount_minor) }}</td><td><span class="status-chip" :class="expense.status === 'approved' ? 'completed' : expense.status === 'rejected' ? 'damaged' : 'due'">{{ expense.status }}</span><small v-if="expense.decision_maker" class="table-subline">Reviewed by {{ expense.decision_maker.name }}</small><span v-if="expense.status === 'approved'" class="status-chip" :class="expense.payment_status === 'paid' ? 'completed' : 'due'">{{ expense.payment_status }}</span><small v-if="expense.paid_on" class="table-subline">{{ expense.paid_on }} · {{ expense.payment_method }} · {{ expense.paid_by?.name ?? 'staff' }}</small></td><td><template v-if="canDecideExpenses && expense.status === 'pending' && expense.created_by !== actorId"><button class="secondary-link compact-action" type="button" @click="decideExpense(expense, 'approve')">Approve</button><button class="secondary-link compact-action" type="button" @click="decideExpense(expense, 'reject')">Reject</button></template><button v-else-if="canMarkExpensesPaid && expense.status === 'approved' && expense.payment_status === 'unpaid'" class="secondary-link compact-action" type="button" @click="markExpensePaid(expense)">Mark paid</button><a v-if="expense.has_receipt" :href="`/finance/expenses/${expense.id}/receipt`" class="secondary-link compact-action">View receipt</a><small v-if="expense.decision_reason">{{ expense.decision_reason }}</small></td></tr></tbody></table></div>
            <div v-else class="empty-state"><div><Receipt aria-hidden="true" /><strong>No expenses recorded</strong><span>Submitted expenses will appear here.</span></div></div>
            <Pagination v-if="expenses.total > 0" :links="expenses.links" :from="expenses.from" :to="expenses.to" :total="expenses.total" />
        </section>
        <section v-if="canRecord" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Record a customer receipt</h2><p>Manual receipt entry only. This does not charge M-Pesa or create an invoice.</p></div></div>
            <form class="receipt-form" @submit.prevent="recordPayment">
                <div class="form-field"><label for="payment-entity-type">Receipt for</label><select id="payment-entity-type" v-model="paymentForm.entity_type" class="select-field"><option value="hire">Gown hire</option><option value="custom_order">Custom order</option></select></div>
                <div v-if="paymentForm.entity_type === 'hire'" class="form-field"><label for="payment-hire">Hire and customer</label><select id="payment-hire" v-model="paymentForm.hire_id" class="select-field" required><option value="" disabled>Select a hire</option><option v-for="hire in hires" :key="hire.id" :value="hire.id">{{ hire.reference }} · {{ hire.customer }} · {{ hire.status === 'reserved' ? 'reservation deposit only' : `service balance ${kes(Math.max(0, hire.chargeTotalMinor - hire.chargePaidMinor))} · penalty balance ${kes(Math.max(0, hire.penaltyTotalMinor - hire.penaltyPaidMinor))}` }}</option></select><small v-if="paymentForm.errors.hire_id" class="form-error">{{ paymentForm.errors.hire_id }}</small></div>
                <div v-else class="form-field"><label for="payment-custom-order">Custom order and customer</label><select id="payment-custom-order" v-model="paymentForm.custom_order_id" class="select-field" required><option value="" disabled>Select a custom order</option><option v-for="order in customOrders" :key="order.id" :value="String(order.id)">{{ order.reference }} · {{ order.customer }} · charge balance {{ kes(Math.max(0, order.chargeTotalMinor - order.chargePaidMinor)) }} · deposit {{ kes(Math.max(0, order.depositRequiredMinor - order.depositPaidMinor)) }}</option></select><small v-if="paymentForm.errors.custom_order_id" class="form-error">{{ paymentForm.errors.custom_order_id }}</small></div>
                <div class="form-field"><label for="payment-amount">Amount (KES)</label><input id="payment-amount" v-model="paymentForm.amount" class="field" inputmode="decimal" placeholder="e.g. 750.00" required><small v-if="paymentForm.errors.amount" class="form-error">{{ paymentForm.errors.amount }}</small></div>
                <div class="form-field"><label for="payment-purpose">Receipt purpose</label><select id="payment-purpose" v-model="paymentForm.purpose" class="select-field"><template v-if="paymentForm.entity_type === 'hire'"><option value="service_charge" :disabled="selectedHire?.status === 'reserved'">Service charge</option><option value="penalty" :disabled="selectedHire?.status === 'reserved' || !selectedHire || selectedHire.penaltyTotalMinor <= selectedHire.penaltyPaidMinor">Assessed penalty</option><option value="deposit">Refundable deposit</option></template><template v-else><option value="custom_order_charge">Agreed order charge</option><option value="custom_order_deposit" :disabled="!selectedCustomOrder || selectedCustomOrder.depositRequiredMinor <= selectedCustomOrder.depositPaidMinor">Required deposit</option></template></select><small v-if="paymentForm.entity_type === 'hire'">Penalty receipts are limited to assessed unpaid amounts; reservations accept deposits only.</small><small v-else>Receipts cannot exceed the remaining order balance. Charge receipts before completion are held as customer advances.</small></div>
                <div class="form-field"><label for="payment-method">Payment method</label><select id="payment-method" v-model="paymentForm.method" class="select-field"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mpesa">M-Pesa (manual receipt)</option><option value="other">Other</option></select></div>
                <div v-if="paymentForm.method === 'mpesa'" class="form-field"><label for="provider-reference">M-Pesa receipt reference</label><input id="provider-reference" v-model="paymentForm.provider_reference" class="field" maxlength="120" required><small v-if="paymentForm.errors.provider_reference" class="form-error">{{ paymentForm.errors.provider_reference }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="paymentForm.processing">{{ paymentForm.processing ? 'Recording…' : 'Record receipt' }}</button></div>
            </form>
        </section>
        <section v-if="canRecord && darajaEnabled" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Request M-Pesa payment</h2><p>Daraja will send a PIN prompt. The receipt stays pending until Safaricom confirms it by callback.</p></div></div>
            <form class="receipt-form" @submit.prevent="startStkPush">
                <div class="form-field"><label for="stk-entity">Payment for</label><select id="stk-entity" v-model="stkForm.entity_type" class="select-field"><option value="hire">Gown hire</option><option value="custom_order">Custom order</option></select></div>
                <div v-if="stkForm.entity_type === 'hire'" class="form-field"><label for="stk-hire">Hire</label><select id="stk-hire" v-model="stkForm.hire_id" class="select-field" required><option value="" disabled>Select a hire</option><option v-for="hire in hires" :key="hire.id" :value="String(hire.id)">{{ hire.reference }} · {{ hire.customer }}</option></select><small v-if="stkForm.errors.hire_id" class="form-error">{{ stkForm.errors.hire_id }}</small></div>
                <div v-else class="form-field"><label for="stk-order">Custom order</label><select id="stk-order" v-model="stkForm.custom_order_id" class="select-field" required><option value="" disabled>Select an order</option><option v-for="order in customOrders" :key="order.id" :value="String(order.id)">{{ order.reference }} · {{ order.customer }}</option></select><small v-if="stkForm.errors.custom_order_id" class="form-error">{{ stkForm.errors.custom_order_id }}</small></div>
                <div class="form-field"><label for="stk-purpose">Purpose</label><select id="stk-purpose" v-model="stkForm.purpose" class="select-field"><template v-if="stkForm.entity_type === 'hire'"><option value="service_charge" :disabled="stkHire?.status === 'reserved'">Service charge</option><option value="penalty">Assessed penalty</option><option value="deposit">Refundable deposit</option></template><template v-else><option value="custom_order_charge">Agreed order charge</option><option value="custom_order_deposit">Required deposit</option></template></select><small v-if="stkForm.errors.purpose" class="form-error">{{ stkForm.errors.purpose }}</small></div>
                <div class="form-field"><label for="stk-amount">Whole KES amount</label><input id="stk-amount" v-model="stkForm.amount" class="field" inputmode="numeric" placeholder="e.g. 750" required><small v-if="stkForm.errors.amount" class="form-error">{{ stkForm.errors.amount }}</small></div>
                <div class="form-field"><label for="stk-phone">Customer M-Pesa number</label><input id="stk-phone" v-model="stkForm.phone" class="field" type="tel" autocomplete="tel" placeholder="07… or +254…" required><small v-if="stkForm.errors.phone" class="form-error">{{ stkForm.errors.phone }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="stkForm.processing">{{ stkForm.processing ? 'Sending prompt…' : 'Send M-Pesa prompt' }}</button><small v-if="stkErrors.mpesa" class="form-error">{{ stkErrors.mpesa }}</small><small v-if="stkErrors.idempotency_key" class="form-error">{{ stkErrors.idempotency_key }}</small></div>
            </form>
            <div v-if="stkPushes.length" class="table-wrap stk-history"><table class="data-table"><thead><tr><th>STK reference</th><th>Customer</th><th>Amount</th><th>Status</th><th>M-Pesa receipt</th><th v-if="canReconcileMpesa">Reconcile</th></tr></thead><tbody><tr v-for="push in stkPushes" :key="push.id"><td>{{ push.reference }}<small class="table-subline">{{ push.purpose.replaceAll('_', ' ') }}</small></td><td>{{ push.customer.name }}</td><td class="amount">{{ kes(push.amount_minor) }}</td><td><span class="status-chip" :class="push.status === 'paid' ? 'completed' : push.status === 'failed' ? 'damaged' : 'due'">{{ push.status }}</span><small v-if="push.result_description" class="table-subline">{{ push.result_description }}</small></td><td>{{ push.mpesa_receipt ?? '—' }}</td><td v-if="canReconcileMpesa"><button v-if="['pending','review'].includes(push.status) && push.checkout_request_id" class="secondary-link compact-action" type="button" @click="reconcileStk(push)">Check status</button><small v-if="push.last_status_check_at" class="table-subline">Checked {{ dateLabel(push.last_status_check_at) }}</small><span v-if="['pending','review'].includes(push.status) && !push.checkout_request_id" class="table-subline">Needs manual provider review</span></td></tr></tbody></table></div>
        </section>
        <section v-if="canRecord && customOrderDeposits.length && customOrders.length" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Apply a custom order deposit</h2><p>For completed orders with an invoice, move held deposit funds to the receivable. This reduces the deposit liability and never creates a cash receipt.</p></div></div>
            <form class="receipt-form" @submit.prevent="applyDeposit">
                <div class="form-field"><label for="deposit-application-order">Custom order</label><select id="deposit-application-order" v-model="depositApplicationForm.custom_order_id" class="select-field" required><option value="" disabled>Select an order</option><option v-for="order in customOrders.filter((candidate) => candidate.chargeTotalMinor > candidate.chargePaidMinor && customOrderDeposits.some((receipt) => receipt.orderId === candidate.id))" :key="order.id" :value="String(order.id)">{{ order.reference }} · {{ order.customer }} · charge due {{ kes(order.chargeTotalMinor - order.chargePaidMinor) }}</option></select><small v-if="depositApplicationForm.errors.custom_order_id" class="form-error">{{ depositApplicationForm.errors.custom_order_id }}</small></div>
                <div class="form-field"><label for="deposit-application-receipt">Deposit receipt</label><select id="deposit-application-receipt" v-model="depositApplicationForm.payment_id" class="select-field" required :disabled="!depositApplicationForm.custom_order_id"><option value="" disabled>Select an available receipt</option><option v-for="receipt in applicableDeposits" :key="receipt.id" :value="String(receipt.id)">{{ receipt.reference }} · {{ receipt.customer }} · available {{ kes(receipt.availableMinor) }}</option></select><small v-if="depositApplicationForm.errors.payment_id" class="form-error">{{ depositApplicationForm.errors.payment_id }}</small></div>
                <div class="form-field"><label for="deposit-application-amount">Amount (KES)</label><input id="deposit-application-amount" v-model="depositApplicationForm.amount" class="field" inputmode="decimal" placeholder="e.g. 750.00" required :max="selectedDepositReceipt ? (selectedDepositReceipt.availableMinor / 100).toFixed(2) : undefined"><small v-if="selectedDepositReceipt">Up to {{ kes(selectedDepositReceipt.availableMinor) }} from this receipt</small><small v-if="depositApplicationForm.errors.amount" class="form-error">{{ depositApplicationForm.errors.amount }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="depositApplicationForm.processing">{{ depositApplicationForm.processing ? 'Applying…' : 'Apply deposit to charge' }}</button></div>
            </form>
        </section>
        <section v-if="canRecord && hireDepositReceipts.length && hires.some((hire) => hire.status !== 'reserved' && hire.status !== 'cancelled')" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Apply a hire deposit</h2><p>Move refundable funds from this hire to its service charge. The remaining deposit liability and charge balance are reduced together.</p></div></div>
            <form class="receipt-form" @submit.prevent="applyHireDeposit">
                <div class="form-field"><label for="hire-deposit-hire">Hire</label><select id="hire-deposit-hire" v-model="hireDepositForm.hire_id" class="select-field" required><option value="" disabled>Select a hire</option><option v-for="hire in hires.filter((candidate) => candidate.status !== 'reserved' && candidate.status !== 'cancelled' && candidate.chargeTotalMinor > candidate.chargePaidMinor && hireDepositReceipts.some((receipt) => receipt.hireId === candidate.id))" :key="hire.id" :value="String(hire.id)">{{ hire.reference }} · {{ hire.customer }} · service charge due {{ kes(hire.chargeTotalMinor - hire.chargePaidMinor) }} · deposit held {{ kes(hire.depositMinor) }}</option></select><small v-if="hireDepositForm.errors.hire_id" class="form-error">{{ hireDepositForm.errors.hire_id }}</small></div>
                <div class="form-field"><label for="hire-deposit-receipt">Deposit receipt</label><select id="hire-deposit-receipt" v-model="hireDepositForm.payment_id" class="select-field" required :disabled="!hireDepositForm.hire_id"><option value="" disabled>Select an available receipt</option><option v-for="receipt in applicableHireDeposits" :key="receipt.id" :value="String(receipt.id)">{{ receipt.reference }} · {{ receipt.customer }} · available {{ kes(receipt.availableMinor) }}</option></select><small v-if="hireDepositForm.errors.payment_id" class="form-error">{{ hireDepositForm.errors.payment_id }}</small></div>
                <div class="form-field"><label for="hire-deposit-amount">Amount (KES)</label><input id="hire-deposit-amount" v-model="hireDepositForm.amount" class="field" inputmode="decimal" placeholder="e.g. 750.00" required><small v-if="selectedHireDeposit">Up to {{ kes(selectedHireDeposit.availableMinor) }} from this receipt</small><small v-if="hireDepositForm.errors.amount" class="form-error">{{ hireDepositForm.errors.amount }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="hireDepositForm.processing">{{ hireDepositForm.processing ? 'Applying…' : 'Apply deposit to charge' }}</button><small v-if="hireDepositForm.errors.idempotency_key" class="form-error">{{ hireDepositForm.errors.idempotency_key }}</small></div>
            </form>
        </section>
        <section v-if="canDecidePenaltyWaivers && independentPenaltyApproval && penaltyWaiverRequests.some((waiver) => waiver.requesterId !== actorId)" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Penalty waiver approvals</h2><p>Each request requires a different approver. Approval applies the configured amount threshold and posts the waiver journal.</p></div></div>
            <div class="approval-list"><article v-for="waiver in penaltyWaiverRequests.filter((request) => request.requesterId !== actorId)" :key="waiver.id" class="approval-item"><div><strong>{{ waiver.reference }} · {{ waiver.hire }}</strong><small>{{ waiver.customer }} · requested by {{ waiver.requestedBy }} · {{ kes(waiver.amountMinor) }}</small><small>{{ waiver.reason }}</small></div><div class="approval-actions"><button class="primary-button" type="button" @click="decidePenaltyWaiver(waiver, 'approved')">Approve</button><button class="secondary-link" type="button" @click="decidePenaltyWaiver(waiver, 'rejected')">Reject</button></div></article></div>
        </section>
        <section v-if="businessApprovals.length" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Finance configuration approvals</h2><p>Review the configuration summary and compare it with the signed accountant or cutover evidence before approving.</p></div></div>
            <article v-for="configuration in businessApprovals.filter((record) => record.createdBy !== actorId)" :key="configuration.id" class="approval-item"><div><strong>{{ configuration.title }} · effective {{ configuration.effectiveFrom ?? 'on approval' }}</strong><small>{{ configuration.summary }}</small></div><div class="approval-actions"><input v-model="financeApprovalForm.approval_reference" class="field approval-reference" :aria-label="`${configuration.title} approval reference`" placeholder="Approval evidence reference" maxlength="255"><button class="primary-button" type="button" :disabled="financeApprovalForm.processing || financeApprovalForm.approval_reference.trim().length < 3" @click="approveBusinessConfiguration(configuration)">Approve</button></div></article>
            <small v-for="(message, field) in financeApprovalForm.errors" :key="field" class="form-error">{{ message }}</small>
        </section>
        <section v-if="canWaivePenalty && waiverHires.length" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>{{ independentPenaltyApproval ? 'Request a penalty waiver' : 'Approve a penalty waiver' }}</h2><p>{{ independentPenaltyApproval ? 'A different finance or management user must approve the request before it reduces the receivable.' : 'Waivers reduce the unpaid receivable, enforce the approved role limits and retain an audit record.' }}</p></div></div>
            <form class="receipt-form" @submit.prevent="waivePenalty">
                <div class="form-field full"><label for="waiver-hire">Hire</label><select id="waiver-hire" v-model="waiverForm.hire_id" class="select-field" required><option value="" disabled>Select hire</option><option v-for="hire in waiverHires" :key="hire.id" :value="hire.id">{{ hire.reference }} · {{ hire.customer }} · outstanding {{ kes(Math.max(0, hire.penaltyTotalMinor - hire.penaltyPaidMinor)) }}</option></select><small v-if="waiverForm.errors.hire_id" class="form-error">{{ waiverForm.errors.hire_id }}</small></div>
                <div class="form-field"><label for="waiver-amount">Amount (KES)</label><input id="waiver-amount" v-model="waiverForm.amount" class="field" inputmode="decimal" required><small v-if="waiverForm.errors.amount" class="form-error">{{ waiverForm.errors.amount }}</small></div>
                <div class="form-field full"><label for="waiver-reason">Approval reason</label><textarea id="waiver-reason" v-model="waiverForm.reason" class="field" rows="2" minlength="5" maxlength="1000" required></textarea><small v-if="waiverForm.errors.reason" class="form-error">{{ waiverForm.errors.reason }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="waiverForm.processing">{{ waiverForm.processing ? 'Saving…' : independentPenaltyApproval ? 'Submit for approval' : 'Approve waiver' }}</button></div>
            </form>
        </section>
        <section v-if="canRefund && refundablePayments.length" class="panel receipt-form-panel">
            <div class="receipt-heading"><div><h2>Record a refund</h2><p>Refunds are append-only reversals. M-Pesa transfers are processed separately outside this system.</p></div></div>
            <form class="receipt-form" @submit.prevent="recordRefund">
                <div class="form-field full"><label for="refund-payment">Receipt</label><select id="refund-payment" v-model="refundForm.payment_id" class="select-field" required><option value="" disabled>Select a receipt</option><option v-for="payment in refundablePayments" :key="payment.id" :value="payment.id">{{ payment.reference }} · {{ payment.customer.name }} · remaining {{ kes(payment.amount_minor - (payment.refunded_minor ?? 0) - (payment.purpose === 'deposit' ? (payment.hire_deposit_applied_minor ?? 0) : 0)) }}</option></select><small v-if="refundForm.errors.payment_id" class="form-error">{{ refundForm.errors.payment_id }}</small></div>
                <div class="form-field"><label for="refund-amount">Amount (KES)</label><input id="refund-amount" v-model="refundForm.amount" class="field" inputmode="decimal" required><small v-if="refundForm.errors.amount" class="form-error">{{ refundForm.errors.amount }}</small></div>
                <div class="form-field"><label for="refund-method">Refund method</label><select id="refund-method" v-model="refundForm.method" class="select-field"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mpesa">M-Pesa (manual)</option><option value="other">Other</option></select></div>
                <div v-if="refundForm.method === 'mpesa'" class="form-field"><label for="refund-provider">M-Pesa reference</label><input id="refund-provider" v-model="refundForm.provider_reference" class="field" required maxlength="120"></div>
                <div class="form-field full"><label for="refund-reason">Reason</label><textarea id="refund-reason" v-model="refundForm.reason" class="field" rows="2" required minlength="5" maxlength="1000"></textarea><small v-if="refundForm.errors.reason" class="form-error">{{ refundForm.errors.reason }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="refundForm.processing">{{ refundForm.processing ? 'Recording…' : 'Record refund' }}</button></div>
            </form>
        </section>
        <section class="panel table-panel">
            <div v-if="payments.data.length" class="table-wrap" role="region" aria-label="Customer receipts" tabindex="0">
                <table class="data-table"><thead><tr><th>Receipt</th><th>Customer</th><th>Hire / order reference</th><th>Received</th><th>Method</th><th>Purpose</th><th>Gross</th><th>Refunded</th><th>Net</th></tr></thead>
                    <tbody><tr v-for="payment in payments.data" :key="payment.id"><td><strong>{{ payment.reference }}</strong><small class="table-subline">{{ payment.provider_reference ?? 'Internal receipt' }}</small></td><td>{{ payment.customer.name }}</td><td>{{ payment.hire?.reference ?? payment.custom_order?.reference ?? payment.bulk_sale?.reference ?? '—' }}</td><td>{{ dateLabel(payment.received_at) }}</td><td>{{ payment.method.replaceAll('_', ' ') }}</td><td>{{ payment.purpose.replaceAll('_', ' ') }}</td><td class="amount">{{ kes(payment.amount_minor) }}</td><td class="amount">{{ kes(payment.refunded_minor ?? 0) }}</td><td class="amount">{{ kes(payment.amount_minor - (payment.refunded_minor ?? 0)) }}</td></tr></tbody>
                </table>
            </div>
            <div v-else class="empty-state"><div><Banknote aria-hidden="true" /><strong>No receipts recorded</strong><span>Recorded receipts will appear here for your authorized locations.</span></div></div>
            <Pagination v-if="payments.total > 0" :links="payments.links" :from="payments.from" :to="payments.to" :total="payments.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.finance-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 13px; }
.receipt-form-panel { margin-bottom: 13px; padding: 17px; }
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
.receipt-form .full { grid-column: 1 / -1; }
.receipt-form .form-actions { grid-column: 1 / -1; justify-content: flex-start; margin-top: 0; }
.finance-card { display: flex; min-height: 116px; flex-direction: column; gap: 8px; padding: 17px; }
.finance-card span { color: #68707e; font-size: 11px; }
.finance-card strong { color: #151a24; font-size: 25px; letter-spacing: -.03em; }
.finance-card small { color: #606874; font-size: 10px; }
@media(max-width:980px) { .finance-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media(max-width:700px) { .finance-cards { grid-template-columns: 1fr; gap: 8px; } .finance-card { min-height: 95px; }.receipt-form { grid-template-columns: 1fr; }.receipt-form .full,.receipt-form .form-actions { grid-column: auto; } }
</style>
