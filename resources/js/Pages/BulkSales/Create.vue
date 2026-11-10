<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2 } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';

type Location = { id: number; name: string };
type Customer = { id: number; name: string; number: string; locationIds: number[] };
type Product = { id: number; sku: string; name: string; salePriceMinor: number; balances: Record<number, { locationId: number; available: number }> };
type Sale = { id: number; reference: string; customer: string; location: string; totalMinor: number; paymentReference: string | null; soldAt: string | null; lines: { productId: number; sku: string; product: string; quantity: number; unitPriceMinor: number; returnedQuantity: number }[] };

const props = defineProps<{ locations: Location[]; customers: Customer[]; products: Product[]; defaultLocationId: number | null; recentSales: Sale[]; canSell: boolean; canRefundSales: boolean }>();
const lines = ref([{ product_id: '', quantity: '1' }]);
const form = useForm({ location_id: props.defaultLocationId ? String(props.defaultLocationId) : '', customer_id: '', items: [] as { product_id: number; quantity: number }[], method: 'cash', provider_reference: '', notes: '', idempotency_key: '' });
const returnSelections = reactive<Record<number, Record<number, { quantity: string; condition: string }>>>(Object.fromEntries(props.recentSales.map((sale) => [sale.id, Object.fromEntries(sale.lines.map((line) => [line.productId, { quantity: '0', condition: 'good' }]))])));
watch(() => props.recentSales, (sales) => {
    for (const sale of sales) {
        returnSelections[sale.id] ??= {};
        for (const line of sale.lines) {
            returnSelections[sale.id][line.productId] ??= { quantity: '0', condition: 'good' };
        }
    }
}, { immediate: true });
const returnForm = useForm({ bulk_sale_id: 0, items: [] as { product_id: number; quantity: number; condition: string }[], method: 'cash', provider_reference: '', reason: '', idempotency_key: '' });
const currentCustomers = computed(() => props.customers.filter((customer) => customer.locationIds.includes(Number(form.location_id))));
const eligibleProducts = computed(() => props.products.filter((product) => (product.balances[Number(form.location_id)]?.available ?? 0) > 0));
const totalMinor = computed(() => lines.value.reduce((total, line) => {
    const product = props.products.find((item) => item.id === Number(line.product_id));
    return total + (product?.salePriceMinor ?? 0) * Math.max(0, Number(line.quantity) || 0);
}, 0));
const kes = (minor: number) => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES' }).format(minor / 100);

function addLine(): void {
    lines.value.push({ product_id: '', quantity: '1' });
}

function removeLine(index: number): void {
    if (lines.value.length > 1) lines.value.splice(index, 1);
}

function available(productId: string): number {
    return props.products.find((product) => product.id === Number(productId))?.balances[Number(form.location_id)]?.available ?? 0;
}

function submit(): void {
    if (lines.value.some((line) => !line.product_id || Number(line.quantity) < 1)) return;
    form.transform((data) => ({
        ...data,
        location_id: Number(data.location_id),
        customer_id: Number(data.customer_id),
        items: lines.value.map((line) => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) })),
        provider_reference: data.method === 'mpesa' ? data.provider_reference.trim() : null,
        idempotency_key: crypto.randomUUID(),
    })).post('/bulk-sales', { preserveScroll: true, onSuccess: () => { lines.value = [{ product_id: '', quantity: '1' }]; form.reset('customer_id', 'provider_reference', 'notes'); } });
}

function submitReturn(sale: Sale): void {
    const selected = sale.lines.map((line) => ({
        product_id: line.productId, quantity: Number(returnSelections[sale.id]?.[line.productId]?.quantity ?? 0),
        condition: returnSelections[sale.id]?.[line.productId]?.condition ?? 'good',
    })).filter((line) => line.quantity > 0);
    if (!selected.length || returnForm.reason.trim().length < 5) return;
    returnForm.bulk_sale_id = sale.id;
    returnForm.items = selected;
    returnForm.idempotency_key = crypto.randomUUID();
    returnForm.transform((data) => ({ ...data, provider_reference: data.method === 'mpesa' ? data.provider_reference.trim() : null })).post('/bulk-sales/returns', {
        preserveScroll: true,
        onSuccess: () => {
            returnForm.reset('items', 'provider_reference', 'reason');
            sale.lines.forEach((line) => { returnSelections[sale.id][line.productId].quantity = '0'; });
        },
    });
}
</script>

<template>
    <Head title="Sell Bulk Stock" />
    <AppShell active="inventory">
        <div class="page-heading">
            <div><h1>Sell bulk stock</h1><p>Record a customer sale, collect payment, and reduce branch availability in one audited checkout.</p></div>
            <Link href="/inventory" class="secondary-link"><ArrowLeft aria-hidden="true" /> Inventory</Link>
        </div>
        <div v-if="!locations.length" class="workflow-note">Your account has no active locations. Ask an administrator to assign a branch before recording a sale.</div>
        <div v-else class="sale-layout">
            <form v-if="canSell" class="panel sale-form" @submit.prevent="submit">
                <div class="form-grid">
                    <label class="form-field"><span>Branch</span><select v-model="form.location_id" class="select-field" required><option value="" disabled>Choose branch</option><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select><small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small></label>
                    <label class="form-field"><span>Customer</span><select v-model="form.customer_id" class="select-field" required><option value="" disabled>Choose assigned customer</option><option v-for="customer in currentCustomers" :key="customer.id" :value="String(customer.id)">{{ customer.name }} · {{ customer.number }}</option></select><small v-if="form.errors.customer_id" class="form-error">{{ form.errors.customer_id }}</small><small v-if="!currentCustomers.length">No customers are assigned to this branch yet.</small></label>
                </div>
                <div class="sale-lines-heading"><div><h2>Items</h2><p>Only available quantities can be sold; reserved and damaged stock is excluded.</p></div><button class="secondary-link" type="button" @click="addLine"><Plus aria-hidden="true" /> Add item</button></div>
                <div v-for="(line, index) in lines" :key="index" class="sale-line">
                    <label class="form-field product-choice"><span>Bulk product</span><select v-model="line.product_id" class="select-field" required><option value="" disabled>Select product</option><option v-for="product in eligibleProducts" :key="product.id" :value="String(product.id)">{{ product.sku }} · {{ product.name }} · {{ kes(product.salePriceMinor) }}</option></select></label>
                    <label class="form-field quantity-choice"><span>Quantity</span><input v-model="line.quantity" class="field" type="number" min="1" :max="line.product_id ? available(line.product_id) : 1000000" step="1" required><small v-if="line.product_id">{{ available(line.product_id).toLocaleString() }} available</small></label>
                    <span class="line-total">{{ kes((props.products.find((product) => product.id === Number(line.product_id))?.salePriceMinor ?? 0) * (Number(line.quantity) || 0)) }}</span>
                    <button class="remove-line" type="button" :disabled="lines.length === 1" :aria-label="`Remove item ${index + 1}`" @click="removeLine(index)"><Trash2 aria-hidden="true" /></button>
                </div>
                <div v-if="form.errors.items" class="form-error">{{ form.errors.items }}</div>
                <div class="form-grid payment-grid">
                    <label class="form-field"><span>Payment method</span><select v-model="form.method" class="select-field"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label>
                    <label v-if="form.method === 'mpesa'" class="form-field"><span>M-Pesa receipt reference</span><input v-model="form.provider_reference" class="field" maxlength="120" required><small v-if="form.errors.provider_reference" class="form-error">{{ form.errors.provider_reference }}</small></label>
                    <label class="form-field full"><span>Sale notes</span><textarea v-model="form.notes" class="textarea-field" rows="2" maxlength="1000" placeholder="Optional fulfillment or receipt notes"></textarea></label>
                </div>
                <div class="checkout-footer"><div><span>Total collected</span><strong>{{ kes(totalMinor) }}</strong></div><button class="primary-button" type="submit" :disabled="form.processing || totalMinor <= 0 || !form.customer_id || !eligibleProducts.length">{{ form.processing ? 'Completing sale…' : 'Complete sale and record payment' }}</button></div>
                <small v-for="(message, field) in form.errors" :key="field" class="form-error">{{ message }}</small>
            </form>
            <div v-if="!canSell" class="workflow-note">This account can review sales and process approved sale returns, but cannot complete a stock sale.</div>
            <section class="panel recent-sales">
                <div class="section-title"><h2>Recent bulk sales</h2><span>Latest 25 at your assigned branches</span></div>
                <div v-if="recentSales.length" class="sales-table-wrap"><table><thead><tr><th>Reference</th><th>Customer / branch</th><th>Items</th><th>Receipt</th><th>Total</th></tr></thead><tbody><template v-for="sale in recentSales" :key="sale.id"><tr><td><strong>{{ sale.reference }}</strong><small>{{ sale.soldAt ?? '' }}</small></td><td>{{ sale.customer }}<small>{{ sale.location }}</small></td><td>{{ sale.lines.map((line) => `${line.quantity} × ${line.product}`).join(', ') }}</td><td>{{ sale.paymentReference ?? '—' }}</td><td class="money">{{ kes(sale.totalMinor) }}</td></tr><tr v-if="canRefundSales"><td colspan="5"><details class="sale-return"><summary>Record item return and refund</summary><form class="return-form" @submit.prevent="submitReturn(sale)"><div v-for="line in sale.lines.filter((item) => item.quantity > item.returnedQuantity)" :key="line.productId" class="return-line"><label><span>{{ line.sku }} · {{ line.product }} ({{ line.quantity - line.returnedQuantity }} returnable)</span><input v-model="returnSelections[sale.id][line.productId].quantity" class="field" type="number" min="0" :max="line.quantity - line.returnedQuantity" step="1" :aria-label="`Return quantity for ${line.product}`"></label><label><span>Condition</span><select v-model="returnSelections[sale.id][line.productId].condition" class="select-field"><option value="good">Good; await inspection</option><option value="damaged">Damaged</option></select></label></div><div class="return-options"><label><span>Refund method</span><select v-model="returnForm.method" class="select-field"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label><label v-if="returnForm.method === 'mpesa'"><span>M-Pesa refund receipt</span><input v-model="returnForm.provider_reference" class="field" maxlength="120" required></label><label><span>Reason</span><input v-model="returnForm.reason" class="field" minlength="5" maxlength="1000" required></label></div><button class="primary-button" type="submit" :disabled="returnForm.processing">{{ returnForm.processing ? 'Recording return…' : 'Restock and refund returned items' }}</button><small v-for="(message, field) in returnForm.errors" :key="field" class="form-error">{{ message }}</small></form></details></td></tr></template></tbody></table></div>
                <div v-else class="empty-sales">No bulk sales recorded yet.</div>
            </section>
        </div>
    </AppShell>
</template>

<style scoped>
.sale-layout { display: grid; gap: 14px; }
.sale-form, .recent-sales { padding: 18px; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px; }
.form-field { display: grid; gap: 6px; color: #333b49; font-size: 12px; font-weight: 600; }
.form-field small { color: #858c98; font-size: 10px; font-weight: 400; }
.field, .select-field, .textarea-field { width: 100%; min-height: 40px; border: 1px solid #dfe2e7; border-radius: 7px; background: #fff; padding: 9px 10px; color: #252c37; font: inherit; }
.textarea-field { min-height: 62px; resize: vertical; }
.full { grid-column: 1 / -1; }
.sale-lines-heading, .section-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 20px 0 11px; }
.sale-lines-heading h2, .section-title h2 { margin: 0; color: #171b22; font-size: 16px; }
.sale-lines-heading p, .section-title span { margin: 4px 0 0; color: #626a76; font-size: 11px; }
.sale-line { display: grid; grid-template-columns: minmax(250px, 1fr) 110px 100px 36px; gap: 10px; align-items: center; padding: 10px 0; border-top: 1px solid #eef0f3; }
.line-total { color: #232a35; font-size: 13px; font-weight: 700; text-align: right; }
.remove-line { display: grid; place-items: center; width: 34px; height: 34px; border: 1px solid #eceef1; border-radius: 7px; background: #fff; color: #9f2330; cursor: pointer; }
.remove-line:disabled { opacity: .4; cursor: default; }
.payment-grid { margin-top: 16px; padding-top: 16px; border-top: 1px solid #eceef1; }
.checkout-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #eceef1; }
.checkout-footer > div { display: grid; gap: 3px; color: #606874; font-size: 11px; }
.checkout-footer strong { color: #171b22; font-size: 20px; }
.primary-button, .secondary-link { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 38px; border-radius: 7px; padding: 0 13px; font: inherit; font-size: 12px; text-decoration: none; cursor: pointer; }
.primary-button { border: 0; background: #d42127; color: #fff; font-weight: 700; }
.primary-button:disabled { opacity: .55; cursor: not-allowed; }
.secondary-link { border: 1px solid #e4e6e9; background: #fff; color: #353d4b; }
.sales-table-wrap { overflow-x: auto; }
.sales-table-wrap table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sales-table-wrap th, .sales-table-wrap td { padding: 12px 14px; border-bottom: 1px solid #e9ebee; text-align: left; vertical-align: middle; }
.sales-table-wrap th { background: #f7f8fa; color: #46505d; font-size: 13px; font-weight: 700; }
.sales-table-wrap td strong, .sales-table-wrap td small { display: block; }
.sales-table-wrap td small { margin-top: 4px; color: #596371; font-size: 12px; }
.money { white-space: nowrap; font-weight: 700; }
.empty-sales { padding: 26px 0; color: #626a76; text-align: center; font-size: 12px; }
.sale-return summary { color: #a31e2b; cursor: pointer; font-size: 11px; font-weight: 700; }
.return-form { display: grid; gap: 10px; max-width: 780px; padding: 12px 0 4px; }
.return-line, .return-options { display: grid; grid-template-columns: 1fr 170px; gap: 10px; align-items: end; }
.return-line label, .return-options label { display: grid; gap: 5px; color: #515a69; font-size: 10px; }
.return-options { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.form-error { color: #b51f2b; font-size: 11px; }
@media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } .sale-line { grid-template-columns: 1fr 90px 70px 32px; gap: 6px; } .checkout-footer { align-items: stretch; flex-direction: column; } .checkout-footer .primary-button { width: 100%; } }
</style>
