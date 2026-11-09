<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CircleHelp, ScanLine, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import BarcodeScanner from '../../Components/BarcodeScanner.vue';
import { useOnlineStatus } from '../../composables/useOnlineStatus';

type Location = { id: number; name: string; type: string };
type Customer = { id: number; name: string; customer_number: string; phone: string | null; institution_id: number | null };
type GraduationEvent = { id: number; name: string; institution_id: number; location_id: number; event_date: string };
type BulkProduct = { id: number; sku: string; name: string; hire_rate_minor: number };
type BulkAvailability = { productId: number; locationId: number; available: number };

const props = withDefaults(defineProps<{ locations: Location[]; customers: Customer[]; graduationEvents?: GraduationEvent[]; contractReferences?: string[]; defaultLocationId: number | null; mode: 'issue' | 'reserve'; bulkProducts?: BulkProduct[]; bulkAvailability?: BulkAvailability[] }>(), { graduationEvents: () => [], contractReferences: () => [], bulkProducts: () => [], bulkAvailability: () => [] });
const online = useOnlineStatus();
const scannedCodes = ref<string[]>([]);
const scanInput = ref('');
const scanFeedback = ref('');
const trackingType = ref<'serialized' | 'bulk'>('serialized');
const selectedBulkProduct = ref('');
const bulkQuantityInput = ref('1');
const bulkLines = ref<{ product_id: number; quantity: number }[]>([]);
const pastedCodes = computed(() => form.asset_codes_text.split(/[\n,\t ]+/).map((code) => code.trim()).filter(Boolean));
const allCodes = computed(() => [...scannedCodes.value, ...pastedCodes.value]);
const bulkAvailable = (productId: number): number => props.bulkAvailability.find((row) => row.productId === productId && row.locationId === Number(form.location_id))?.available ?? 0;
const selectedBulk = computed(() => props.bulkProducts.find((product) => product.id === Number(selectedBulkProduct.value)));
const selectedCustomer = computed(() => props.customers.find((customer) => customer.id === Number(form.customer_id)));
const availableEvents = computed(() => props.graduationEvents.filter((event) => event.institution_id === selectedCustomer.value?.institution_id && event.location_id === Number(form.location_id)));
const dueDate = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);
dueDate.setMinutes(dueDate.getMinutes() - dueDate.getTimezoneOffset());
const form = useForm({
    customer_id: '',
    location_id: props.defaultLocationId ? String(props.defaultLocationId) : (props.locations[0]?.id ? String(props.locations[0].id) : ''),
    asset_codes_text: '',
    due_at: dueDate.toISOString().slice(0, 16),
    event_date: '',
    graduation_event_id: '',
    contract_reference: '',
    notes: '',
    idempotency_key: crypto.randomUUID(),
});
const errors = form.errors as Record<string, string>;

function addCode(value: string): void {
    const code = value.trim();
    if (!code) return;
    if (scannedCodes.value.includes(code)) {
        scanFeedback.value = `${code} is already on this hire.`;
        scanInput.value = '';
        return;
    }
    scannedCodes.value.push(code);
    scanFeedback.value = `${code} scanned · ${scannedCodes.value.length} gown${scannedCodes.value.length === 1 ? '' : 's'}`;
    scanInput.value = '';
}

function removeCode(value: string): void {
    scannedCodes.value = scannedCodes.value.filter((code) => code !== value);
}

function submit(): void {
    const codes = allCodes.value;
    form.transform((data) => ({ customer_id: data.customer_id, location_id: data.location_id, ...(trackingType.value === 'bulk' ? { bulk_items: bulkLines.value } : { asset_codes: codes }), due_at: data.due_at, event_date: data.event_date || null, graduation_event_id: data.graduation_event_id || null, contract_reference: data.contract_reference || null, notes: data.notes || null, idempotency_key: data.idempotency_key }))
        .post(props.mode === 'reserve' ? '/hiring/reservations' : '/hiring/issue', { preserveScroll: true, onSuccess: () => { scannedCodes.value = []; scanFeedback.value = ''; } });
}

function addBulkLine(): void {
    const productId = Number(selectedBulkProduct.value);
    const quantity = Number(bulkQuantityInput.value);
    if (!productId || !Number.isInteger(quantity) || quantity < 1 || quantity > bulkAvailable(productId) || bulkLines.value.some((line) => line.product_id === productId)) return;
    bulkLines.value.push({ product_id: productId, quantity });
    selectedBulkProduct.value = '';
    bulkQuantityInput.value = '1';
}
</script>

<template>
    <Head :title="mode === 'reserve' ? 'Reserve gowns' : 'Issue gowns'" />
    <AppShell active="hiring">
        <div class="page-heading">
            <div><h1>{{ mode === 'reserve' ? 'Reserve gowns' : 'Issue gowns' }}</h1><p>{{ mode === 'reserve' ? 'Hold confirmed gowns for a customer and graduation event.' : 'Record a customer handover and update gown custody.' }}</p></div>
            <Link href="/hiring" class="secondary-link"><ArrowLeft aria-hidden="true" /> Hiring & returns</Link>
        </div>
        <div class="workflow-note"><CircleHelp :size="16" aria-hidden="true" /> <template v-if="mode === 'reserve'">Reserve serialized gowns by barcode or hold counted bulk quantities. Reserved stock is removed from branch availability until handover or cancellation. A refundable deposit can be recorded from Finance.</template><template v-else>Issue serialized gowns by barcode, or issue available bulk quantities. The server locks stock and records each quantity in the hire and movement ledger. Deposits are recorded separately from earned service charges.</template></div>
        <form class="panel form-card" @submit.prevent="submit">
            <div class="form-grid">
                <fieldset v-if="bulkProducts.length" class="form-field full tracking-choice"><legend>Stock tracking</legend><label><input v-model="trackingType" type="radio" value="serialized"> Serialized gowns</label><label><input v-model="trackingType" type="radio" value="bulk"> Bulk quantity</label></fieldset>
                <div class="form-field">
                    <label for="customer">Customer</label>
                    <select id="customer" v-model="form.customer_id" class="select-field" required>
                        <option value="" disabled>Select a customer</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }} · {{ customer.customer_number }}{{ customer.phone ? ` · ${customer.phone}` : '' }}</option>
                    </select>
                    <small v-if="form.errors.customer_id" class="form-error">{{ form.errors.customer_id }}</small>
                </div>
                <div v-if="trackingType === 'bulk'" class="form-field full">
                    <label for="bulk-product">Bulk product</label>
                    <div class="bulk-select-row"><select id="bulk-product" v-model="selectedBulkProduct" class="select-field"><option value="" disabled>Select a product</option><option v-for="product in bulkProducts" :key="product.id" :value="String(product.id)" :disabled="bulkAvailable(product.id) < 1 || bulkLines.some((line) => line.product_id === product.id)">{{ product.name }} · {{ product.sku }} · {{ bulkAvailable(product.id) }} available</option></select><input v-model="bulkQuantityInput" class="field quantity-field" type="number" min="1" :max="selectedBulk ? bulkAvailable(selectedBulk.id) : 1" aria-label="Quantity to issue"><button type="button" class="secondary-link" :disabled="!selectedBulk || Number(bulkQuantityInput) > bulkAvailable(selectedBulk.id)" @click="addBulkLine">Add quantity</button></div>
                    <small>Available quantities are limited to the selected pickup location.</small>
                    <div v-if="bulkLines.length" class="bulk-line-list"><div v-for="line in bulkLines" :key="line.product_id"><span>{{ bulkProducts.find((product) => product.id === line.product_id)?.name }} · {{ line.quantity }} units</span><button type="button" :aria-label="`Remove ${line.product_id}`" @click="bulkLines = bulkLines.filter((entry) => entry.product_id !== line.product_id)"><Trash2 aria-hidden="true" /></button></div></div>
                    <small v-if="errors.bulk_items || errors['bulk_items.0.quantity']" class="form-error">{{ errors.bulk_items || errors['bulk_items.0.quantity'] }}</small>
                </div>
                <div class="form-field">
                    <label for="location">Pickup location</label>
                    <select id="location" v-model="form.location_id" class="select-field" required><option value="" disabled>Select a location</option><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }} · {{ location.type }}</option></select>
                    <small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small>
                </div>
                <div v-if="trackingType === 'serialized'" class="form-field">
                    <label for="asset-code">Scan asset barcode / ID</label>
                    <div class="scan-input"><ScanLine aria-hidden="true" /><input id="asset-code" v-model="scanInput" class="field" type="text" autocomplete="off" autofocus placeholder="Scan then press Enter" @keydown.enter.prevent="addCode(scanInput)"><button class="secondary-link scan-add" type="button" @click="addCode(scanInput)">Add</button></div>
                    <small>Use a camera scanner or barcode reader. {{ allCodes.length }} gown{{ allCodes.length === 1 ? '' : 's' }} prepared.</small>
                    <BarcodeScanner @detected="addCode" />
                    <small v-if="scanFeedback" role="status" class="scan-feedback">{{ scanFeedback }}</small>
                    <div v-if="scannedCodes.length" class="scanned-list" aria-label="Scanned gowns"><div v-for="code in scannedCodes" :key="code" class="scanned-code"><span>{{ code }}</span><button type="button" :aria-label="`Remove ${code}`" @click="removeCode(code)"><Trash2 aria-hidden="true" /></button></div></div>
                    <small v-if="errors.asset_codes || errors['asset_codes.0']" class="form-error">{{ errors.asset_codes || errors['asset_codes.0'] }}</small>
                    <div class="form-field"><label for="asset-codes-paste">Paste asset codes</label><textarea id="asset-codes-paste" v-model="form.asset_codes_text" class="textarea-field" rows="3" placeholder="One code per line, maximum 200 combined"></textarea></div>
                </div>
                <div class="form-field">
                    <label for="due-at">Expected return</label>
                    <input id="due-at" v-model="form.due_at" class="field" type="datetime-local" required>
                    <small v-if="form.errors.due_at" class="form-error">{{ form.errors.due_at }}</small>
                </div>
                <div class="form-field">
                    <label for="event-date">Graduation event date <span class="muted">(optional)</span></label>
                    <input id="event-date" v-model="form.event_date" class="field" type="date">
                    <small v-if="form.errors.event_date" class="form-error">{{ form.errors.event_date }}</small>
                </div>
                <div v-if="availableEvents.length" class="form-field">
                    <label for="graduation-event">Link graduation event <span class="muted">(optional)</span></label>
                    <select id="graduation-event" v-model="form.graduation_event_id" class="select-field" @change="() => { const event = availableEvents.find((item) => item.id === Number(form.graduation_event_id)); if (event) form.event_date = event.event_date.slice(0, 10); }"><option value="">No linked event</option><option v-for="event in availableEvents" :key="event.id" :value="String(event.id)">{{ event.name }} · {{ new Date(`${event.event_date.slice(0, 10)}T12:00:00`).toLocaleDateString('en-KE') }}</option></select>
                    <small v-if="form.errors.graduation_event_id" class="form-error">{{ form.errors.graduation_event_id }}</small>
                </div>
                <div v-if="contractReferences.length" class="form-field">
                    <label for="hire-contract">Customer contract <span class="muted">(optional)</span></label>
                    <select id="hire-contract" v-model="form.contract_reference" class="select-field"><option value="">No contract override</option><option v-for="reference in contractReferences" :key="reference" :value="reference">{{ reference }}</option></select>
                    <small v-if="form.errors.contract_reference" class="form-error">{{ form.errors.contract_reference }}</small>
                </div>
                <div class="form-field full">
                    <label for="notes">Notes <span class="muted">(optional)</span></label>
                    <textarea id="notes" v-model="form.notes" class="textarea-field" rows="3" maxlength="2000" placeholder="Condition at handover or customer instructions" />
                </div>
            </div>
            <div class="form-actions"><Link href="/hiring" class="secondary-link">Cancel</Link><button class="primary-button" type="submit" :disabled="form.processing || !online">{{ !online ? 'Reconnect to continue' : form.processing ? (mode === 'reserve' ? 'Reserving…' : 'Recording issue…') : (mode === 'reserve' ? 'Confirm gown reservation' : 'Confirm gown issue') }}</button></div>
        </form>
    </AppShell>
</template>

<style scoped>
.scan-input { position: relative; }
.scan-input > svg { position: absolute; z-index: 1; top: 13px; left: 12px; width: 17px; height: 17px; color: #606874; }
.scan-input .field { padding-left: 38px; }
.scan-add { min-height: 42px; }
.scan-feedback { color: #596170; }
.scanned-list { display: grid; max-height: 160px; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 5px; overflow-y: auto; margin-top: 8px; }
.scanned-code { display: flex; align-items: center; justify-content: space-between; border: 1px solid #e4e6e8; border-radius: 6px; padding: 5px 8px; color: #303744; font-size: 10px; }
.scanned-code button { border: 0; background: transparent; color: #606874; cursor: pointer; }
.tracking-choice { display: flex; flex-direction: row; gap: 20px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
.tracking-choice legend { padding: 0 5px; color: #596170; font-size: 11px; }
.tracking-choice label { display: flex; flex-direction: row; align-items: center; gap: 6px; }
.bulk-select-row { display: grid; grid-template-columns: minmax(0,1fr) 100px auto; gap: 8px; }
.quantity-field { min-width: 0; }
.bulk-line-list { display: grid; gap: 5px; margin-top: 8px; }
.bulk-line-list > div { display: flex; justify-content: space-between; border: 1px solid #e4e6e8; border-radius: 6px; padding: 8px; font-size: 11px; }
.bulk-line-list button { border: 0; background: transparent; color: #606874; cursor: pointer; }
.primary-button:disabled { cursor: wait; opacity: .7; }
@media(max-width:640px) { .bulk-select-row { grid-template-columns: 1fr 85px; }.bulk-select-row button { grid-column: 1 / -1; } }
</style>
