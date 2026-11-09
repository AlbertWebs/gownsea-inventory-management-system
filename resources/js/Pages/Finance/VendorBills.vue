<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

type LocationOption = { id: number; name: string };
type MovementOption = { id: number; locationId: number; product: string; sku: string; quantity: number; amountMinor: number };
type AssetOption = { id: number; locationId: number; product: string; sku: string; assetCode: string; amountMinor: number };
type Bill = { id: number; reference: string; vendor: string; invoiceNumber: string; location: string; amountMinor: number; paidMinor: number; balanceMinor: number; invoiceDate: string; dueDate: string; isInventoryReceipt: boolean; isHireAsset: boolean };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
const props = defineProps<{ bills: Page<Bill>; locations: LocationOption[]; movements: MovementOption[]; assets: AssetOption[]; expenseAccounts: { code: string; name: string }[]; canManage: boolean; paymentMethods: string[] }>();
const kes = (minor: number) => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 2 }).format(minor / 100);
const selectedMovement = computed(() => props.movements.find((movement) => movement.id === Number(billForm.stock_movement_id)));
const selectedAssets = computed(() => props.assets.filter((asset) => billForm.physical_asset_ids.includes(String(asset.id))));
const billForm = useForm({ vendor_name: '', invoice_number: '', location_id: props.locations[0]?.id ?? '', amount: '', invoice_date: new Date().toISOString().slice(0, 10), due_date: new Date().toISOString().slice(0, 10), stock_movement_id: '', physical_asset_ids: [] as string[], expense_account_code: props.expenseAccounts[0]?.code ?? '', description: '' });
const paymentForm = useForm({ amount: '', method: 'mpesa', payment_reference: '', paid_on: new Date().toISOString().slice(0, 10), idempotency_key: crypto.randomUUID() });
const payingBill = ref<Bill | null>(null);
watch(selectedMovement, (movement) => { if (movement) { billForm.physical_asset_ids = []; billForm.location_id = movement.locationId; billForm.amount = (movement.amountMinor / 100).toFixed(2); } });
watch(selectedAssets, (assets) => { if (assets.length) { billForm.stock_movement_id = ''; billForm.location_id = assets[0].locationId; billForm.amount = (assets.reduce((sum, asset) => sum + asset.amountMinor, 0) / 100).toFixed(2); } });
function captureBill() { billForm.post('/finance/vendor-bills', { preserveScroll: true, onSuccess: () => billForm.reset('vendor_name', 'invoice_number', 'amount', 'stock_movement_id', 'physical_asset_ids', 'description') }); }
function startPayment(bill: Bill) { payingBill.value = bill; paymentForm.amount = (bill.balanceMinor / 100).toFixed(2); paymentForm.idempotency_key = crypto.randomUUID(); }
function payBill() { if (!payingBill.value) return; paymentForm.post(`/finance/vendor-bills/${payingBill.value.id}/payments`, { preserveScroll: true, onSuccess: () => { paymentForm.reset('amount', 'payment_reference'); paymentForm.idempotency_key = crypto.randomUUID(); payingBill.value = null; } }); }
</script>

<template>
    <Head title="Vendor bills" />
    <main class="page-shell finance-page">
        <div class="page-heading"><div><h1>Vendor bills</h1><p>Capture supplier invoices, track due balances, and reconcile payments against payables.</p></div><Link href="/finance" class="secondary-link">Sales &amp; expenses</Link></div>
        <section v-if="canManage" class="panel finance-panel">
            <div class="receipt-heading"><div><h2>Capture a vendor bill</h2><p>Expense bills post to accounts payable. Bulk receipt bills attach to the inventory receipt already posted.</p></div></div>
            <form class="vendor-bill-form" @submit.prevent="captureBill">
                <label>Vendor name<input v-model="billForm.vendor_name" required maxlength="160" autocomplete="organization"><small v-if="billForm.errors.vendor_name">{{ billForm.errors.vendor_name }}</small></label>
                <label>Supplier invoice number<input v-model="billForm.invoice_number" required maxlength="120"><small v-if="billForm.errors.invoice_number">{{ billForm.errors.invoice_number }}</small></label>
                <label>Branch<select v-model="billForm.location_id" required><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select></label>
                <label>Invoice date<input v-model="billForm.invoice_date" type="date" required></label>
                <label>Due date<input v-model="billForm.due_date" type="date" required></label>
                <label>Amount (KES)<input v-model="billForm.amount" type="number" min="0.01" step="0.01" required :readonly="!!selectedMovement || selectedAssets.length > 0"><small v-if="billForm.errors.amount">{{ billForm.errors.amount }}</small></label>
                <label class="wide-field">Link bulk inventory receipt (optional)<select v-model="billForm.stock_movement_id"><option value="">No bulk receipt</option><option v-for="movement in movements" :key="`bulk-${movement.id}`" :value="movement.id">{{ movement.product }} · {{ movement.sku }} · {{ movement.quantity }} units · {{ kes(movement.amountMinor) }}</option></select><small v-if="billForm.errors.stock_movement_id">{{ billForm.errors.stock_movement_id }}</small></label>
                <label v-if="assets.length" class="wide-field">Link serialized hire assets (optional)<select v-model="billForm.physical_asset_ids" multiple size="5" :disabled="!!selectedMovement"><option v-for="asset in assets" :key="asset.id" :value="String(asset.id)">{{ asset.assetCode }} · {{ asset.product }} · {{ kes(asset.amountMinor) }}</option></select><small>Choose all assets listed on this supplier invoice. Their combined recorded value sets the bill amount.</small><small v-if="billForm.errors.physical_asset_ids">{{ billForm.errors.physical_asset_ids }}</small></label>
                <label v-if="!selectedMovement && !selectedAssets.length">Expense account<select v-model="billForm.expense_account_code" required><option v-for="account in expenseAccounts" :key="account.code" :value="account.code">{{ account.code }} · {{ account.name }}</option></select></label>
                <label class="wide-field">Description<input v-model="billForm.description" maxlength="1000" placeholder="Optional details"></label>
                <div class="wide-field form-actions"><button class="primary-link" type="submit" :disabled="billForm.processing">{{ billForm.processing ? 'Saving…' : 'Capture bill' }}</button></div>
            </form>
        </section>
        <section class="panel finance-panel">
            <div class="receipt-heading"><div><h2>Captured bills</h2><p>{{ bills.total }} supplier bill(s) in the register.</p></div></div>
            <div v-if="bills.data.length" class="table-wrap"><table class="data-table"><thead><tr><th>Vendor / invoice</th><th>Branch</th><th>Invoice / due</th><th>Type</th><th>Bill amount</th><th>Paid</th><th>Balance</th><th></th></tr></thead><tbody>
                <tr v-for="bill in bills.data" :key="bill.id"><td><strong>{{ bill.vendor }}</strong><small class="table-subline">{{ bill.invoiceNumber }} · {{ bill.reference }}</small></td><td>{{ bill.location }}</td><td>{{ bill.invoiceDate }}<small class="table-subline">Due {{ bill.dueDate }}</small></td><td>{{ bill.isInventoryReceipt ? 'Bulk inventory' : bill.isHireAsset ? 'Hire asset' : 'Operating expense' }}</td><td class="amount">{{ kes(bill.amountMinor) }}</td><td class="amount">{{ kes(bill.paidMinor) }}</td><td class="amount">{{ kes(bill.balanceMinor) }}</td><td><button v-if="canManage && bill.balanceMinor > 0" type="button" class="secondary-link compact-action" @click="startPayment(bill)">Record payment</button><span v-else-if="bill.balanceMinor === 0" class="status-chip completed">Paid</span></td></tr>
            </tbody></table></div><div v-else class="empty-state"><strong>No vendor bills captured yet</strong><span>Supplier invoices will appear here after capture.</span></div>
            <nav v-if="bills.total > 0" class="pagination"><button v-for="link in bills.links" :key="link.label" type="button" :disabled="!link.url" :class="{ active: link.active }" @click="link.url && router.visit(link.url)">{{ link.label.replace('&laquo;', '‹').replace('&raquo;', '›') }}</button></nav>
        </section>
        <section v-if="payingBill" class="panel finance-panel payment-panel"><div class="receipt-heading"><div><h2>Pay {{ payingBill.vendor }}</h2><p>{{ payingBill.invoiceNumber }} · Outstanding {{ kes(payingBill.balanceMinor) }}</p></div><button type="button" class="secondary-link" @click="payingBill = null">Close</button></div>
            <form class="vendor-bill-form" @submit.prevent="payBill"><label>Payment amount (KES)<input v-model="paymentForm.amount" type="number" min="0.01" step="0.01" :max="(payingBill.balanceMinor / 100).toFixed(2)" required><small v-if="paymentForm.errors.amount">{{ paymentForm.errors.amount }}</small></label><label>Method<select v-model="paymentForm.method"><option v-for="method in paymentMethods" :key="method" :value="method">{{ method.replace('_', ' ') }}</option></select></label><label>Payment reference<input v-model="paymentForm.payment_reference" maxlength="120"></label><label>Paid on<input v-model="paymentForm.paid_on" type="date" required></label><div class="wide-field form-actions"><button class="primary-link" type="submit" :disabled="paymentForm.processing">{{ paymentForm.processing ? 'Saving…' : 'Record payment' }}</button></div></form>
        </section>
    </main>
</template>

<style scoped>
.vendor-bill-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin-top:1rem}.vendor-bill-form label{display:flex;flex-direction:column;gap:.4rem;color:#303846;font-size:.86rem;font-weight:600}.vendor-bill-form input,.vendor-bill-form select{min-height:42px;border:1px solid #d6dbe2;border-radius:8px;background:white;padding:.55rem .7rem;color:#171b23}.vendor-bill-form small{color:#c6202c;font-weight:400}.wide-field{grid-column:1/-1}.form-actions{display:flex;justify-content:flex-end}.pagination{display:flex;justify-content:flex-end;gap:.3rem;padding-top:1rem}.pagination button{border:1px solid #e0e3e8;background:white;border-radius:5px;padding:.35rem .6rem}.pagination button.active{background:#c6202c;color:white}.pagination button:disabled{opacity:.45}.payment-panel{border-color:#e9c9cb}@media(max-width:850px){.vendor-bill-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.vendor-bill-form{grid-template-columns:1fr}.wide-field{grid-column:auto}}
</style>
