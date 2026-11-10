<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, CircleAlert, CircleCheck, CircleHelp, Plus, ScanLine, Search, Sparkles, Trash2, UserRoundPlus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import BarcodeScanner from '../../Components/BarcodeScanner.vue';
import { useOnlineStatus } from '../../composables/useOnlineStatus';

type Location = { id: number; name: string; type: string };
type Customer = { id: number; name: string; customer_number: string; phone: string | null; institution_id: number | null };
type Institution = { id: number; name: string; type: string };
type GraduationEvent = { id: number; name: string; institution_id: number; location_id: number; event_date: string };
type BulkProduct = { id: number; sku: string; name: string; hire_rate_minor: number };
type BulkAvailability = { productId: number; locationId: number; available: number };

const props = withDefaults(defineProps<{ locations: Location[]; customers: Customer[]; institutions?: Institution[]; flash?: { createdCustomer?: Customer | null; success?: string }; graduationEvents?: GraduationEvent[]; contractReferences?: string[]; defaultLocationId: number | null; mode: 'issue' | 'reserve'; bulkProducts?: BulkProduct[]; bulkAvailability?: BulkAvailability[] }>(), { institutions: () => [], graduationEvents: () => [], contractReferences: () => [], bulkProducts: () => [], bulkAvailability: () => [] });
const online = useOnlineStatus();
const customerQuery = ref('');
const customerPickerOpen = ref(false);
const customerDialogOpen = ref(false);
const selectedCustomerRecord = ref<Customer | null>(null);
const remoteCustomers = ref<Customer[]>([]);
const customerSearchPending = ref(false);
const customerSearchError = ref(false);
let customerSearchTimer: ReturnType<typeof setTimeout> | undefined;
let barcodeCheckTimer: ReturnType<typeof setTimeout> | undefined;
let customerSearchSequence = 0;
const scannedCodes = ref<string[]>([]);
const scanInput = ref('');
const scanFeedback = ref('');
const scanFeedbackTone = ref<'info' | 'success' | 'error'>('info');
const availabilityPending = ref(false);
const successRewardDismissed = ref(false);
const successRewardMessage = ref<string | null>(null);
const submitError = ref('');
const successReward = computed(() => successRewardMessage.value ?? (props.mode === 'issue' && props.flash?.success?.includes('gown(s) issued successfully') ? props.flash.success : null));
const showSuccessReward = computed(() => !!successReward.value && !successRewardDismissed.value);
const trackingType = ref<'serialized' | 'bulk'>('serialized');
const selectedBulkProduct = ref('');
const bulkQuantityInput = ref('1');
const bulkLines = ref<{ product_id: number; quantity: number }[]>([]);
const pastedCodes = computed(() => form.asset_codes_text.split(/[\n,\t ]+/).map((code) => code.trim()).filter(Boolean));
const allCodes = computed(() => [...scannedCodes.value, ...pastedCodes.value]);
const bulkAvailable = (productId: number): number => props.bulkAvailability.find((row) => row.productId === productId && row.locationId === Number(form.location_id))?.available ?? 0;
const selectedBulk = computed(() => props.bulkProducts.find((product) => product.id === Number(selectedBulkProduct.value)));
const selectedCustomer = computed(() => props.customers.find((customer) => customer.id === Number(form.customer_id)) ?? selectedCustomerRecord.value);
const filteredCustomers = computed(() => {
    const term = customerQuery.value.trim().toLocaleLowerCase();
    const matches = term.length >= 2
        ? remoteCustomers.value
        : props.customers.filter((customer) => !term || [customer.name, customer.customer_number, customer.phone ?? ''].some((value) => value.toLocaleLowerCase().includes(term)));
    return matches.slice(0, term ? 30 : 10);
});
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
const newCustomerForm = useForm({ name: '', type: 'individual', phone: '', email: '', institution_id: '', location_id: '', source: 'direct', notes: '', return_to: 'hiring.issue' });

watch([customerQuery, customerPickerOpen], ([query, open]) => {
    if (customerSearchTimer) clearTimeout(customerSearchTimer);
    const term = query.trim();
    const sequence = ++customerSearchSequence;
    customerSearchError.value = false;
    if (!open || term.length < 2) {
        customerSearchPending.value = false;
        remoteCustomers.value = [];
        return;
    }

    customerSearchPending.value = true;
    customerSearchTimer = setTimeout(async () => {
        try {
            const response = await fetch(`/hiring/customers/search?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Customer search failed.');
            const data = await response.json() as { customers: Customer[] };
            if (sequence === customerSearchSequence) remoteCustomers.value = data.customers;
        } catch {
            if (sequence === customerSearchSequence) customerSearchError.value = true;
        } finally {
            if (sequence === customerSearchSequence) customerSearchPending.value = false;
        }
    }, 240);
});

watch([scanInput, availabilityPending], ([value, pending]) => {
    if (barcodeCheckTimer) clearTimeout(barcodeCheckTimer);
    const code = value.trim();
    if (!code || pending) return;

    barcodeCheckTimer = setTimeout(() => {
        if (!availabilityPending.value && scanInput.value.trim() === code) void addCode(code);
    }, 500);
});

onBeforeUnmount(() => {
    if (customerSearchTimer) clearTimeout(customerSearchTimer);
    if (barcodeCheckTimer) clearTimeout(barcodeCheckTimer);
});

watch(successReward, (message) => {
    if (message) successRewardDismissed.value = false;
});

function selectCustomer(customer: Customer): void {
    selectedCustomerRecord.value = customer;
    form.customer_id = String(customer.id);
    customerQuery.value = customer.name;
    customerPickerOpen.value = false;
}

function onCustomerQueryInput(): void {
    form.customer_id = '';
    selectedCustomerRecord.value = null;
    customerPickerOpen.value = true;
}

function openCustomerOnboarding(): void {
    newCustomerForm.clearErrors();
    newCustomerForm.name = customerQuery.value.trim();
    newCustomerForm.type = 'individual';
    newCustomerForm.phone = '';
    newCustomerForm.email = '';
    newCustomerForm.institution_id = '';
    newCustomerForm.location_id = form.location_id;
    newCustomerForm.source = 'direct';
    newCustomerForm.notes = '';
    customerPickerOpen.value = false;
    customerDialogOpen.value = true;
}

function createCustomer(): void {
    newCustomerForm.post('/customers', {
        preserveScroll: true,
        onSuccess: () => {
            const created = props.flash?.createdCustomer;
            if (created) {
                selectedCustomerRecord.value = created;
                form.customer_id = String(created.id);
                customerQuery.value = created.name;
            }
            customerDialogOpen.value = false;
            newCustomerForm.reset();
        },
    });
}

async function addCode(value: string): Promise<void> {
    const code = value.trim().toLocaleUpperCase();
    if (!code) return;
    if (scannedCodes.value.includes(code)) {
        scanFeedback.value = `${code} is already on this hire.`;
        scanFeedbackTone.value = 'error';
        scanInput.value = '';
        return;
    }
    if (availabilityPending.value) {
        scanFeedback.value = 'Please wait for the current barcode check to finish.';
        scanFeedbackTone.value = 'info';
        return;
    }
    if (!form.location_id) {
        scanFeedback.value = 'Choose a pickup location before checking a gown.';
        scanFeedbackTone.value = 'error';
        return;
    }

    scanInput.value = '';
    availabilityPending.value = true;
    scanFeedback.value = `Checking ${code}…`;
    scanFeedbackTone.value = 'info';
    try {
        const query = new URLSearchParams({ code, location_id: form.location_id });
        const response = await fetch(`/hiring/assets/availability?${query.toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const result = await response.json() as { available?: boolean; assetCode?: string; productName?: string | null; message?: string };
        if (!response.ok) throw new Error(result.message ?? 'Could not check this barcode. Try again.');
        if (!result.available) {
            scanFeedback.value = result.message ?? `${code} is not available.`;
            scanFeedbackTone.value = 'error';
            return;
        }

        const confirmedCode = result.assetCode ?? code;
        if (!scannedCodes.value.includes(confirmedCode)) scannedCodes.value.push(confirmedCode);
        scanFeedback.value = `${result.message ?? `${confirmedCode} is available.`}${result.productName ? ` · ${result.productName}` : ''}`;
        scanFeedbackTone.value = 'success';
    } catch (error) {
        scanFeedback.value = error instanceof Error ? error.message : 'Could not check this barcode. Check your connection and try again.';
        scanFeedbackTone.value = 'error';
    } finally {
        availabilityPending.value = false;
    }
}

function removeCode(value: string): void {
    scannedCodes.value = scannedCodes.value.filter((code) => code !== value);
}

function submit(): void {
    const codes = allCodes.value;
    submitError.value = '';
    successRewardMessage.value = null;
    successRewardDismissed.value = false;
    form.transform((data) => ({ customer_id: data.customer_id, location_id: data.location_id, ...(trackingType.value === 'bulk' ? { bulk_items: bulkLines.value } : { asset_codes: codes }), due_at: data.due_at, event_date: data.event_date || null, graduation_event_id: data.graduation_event_id || null, contract_reference: data.contract_reference || null, notes: data.notes || null, idempotency_key: data.idempotency_key }))
        .post(props.mode === 'reserve' ? '/hiring/reservations' : '/hiring/issue', {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { success?: string } | undefined;
                if (props.mode === 'issue') successRewardMessage.value = flash?.success ?? 'Gown issue recorded successfully.';
                scannedCodes.value = [];
                scanFeedback.value = '';
                form.reset('customer_id', 'asset_codes_text', 'event_date', 'graduation_event_id', 'contract_reference', 'notes');
                form.idempotency_key = crypto.randomUUID();
                selectedCustomerRecord.value = null;
                customerQuery.value = '';
            },
            onError: (errors) => {
                submitError.value = Object.values(errors).find((message) => typeof message === 'string' && message.trim())
                    ?? 'The gown issue could not be saved. Review the form and try again.';
            },
        });
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
            <div v-if="submitError" class="submit-error-summary" role="alert"><CircleAlert :size="18" aria-hidden="true" /><span>{{ submitError }}</span></div>
            <div class="form-grid">
                <fieldset v-if="bulkProducts.length" class="form-field full tracking-choice"><legend>Stock tracking</legend><label><input v-model="trackingType" type="radio" value="serialized"> Serialized gowns</label><label><input v-model="trackingType" type="radio" value="bulk"> Bulk quantity</label></fieldset>
                <div class="form-field customer-picker-field">
                    <label for="customer">Customer</label>
                    <div class="customer-picker">
                        <Search class="customer-search-icon" aria-hidden="true" />
                        <input id="customer" v-model="customerQuery" class="field customer-search-input" type="search" role="combobox" aria-autocomplete="list" aria-controls="issue-customer-options" :aria-expanded="customerPickerOpen" autocomplete="off" placeholder="Search by customer name, number, or phone" @focus="customerPickerOpen = true" @input="onCustomerQueryInput" @keydown.esc="customerPickerOpen = false" @blur="customerPickerOpen = false">
                        <div v-if="customerPickerOpen" id="issue-customer-options" class="customer-options" role="listbox" aria-label="Customers">
                            <button v-for="customer in filteredCustomers" :key="customer.id" class="customer-option" type="button" role="option" :aria-selected="form.customer_id === String(customer.id)" @mousedown.prevent @click="selectCustomer(customer)">
                                <span class="customer-option-avatar" aria-hidden="true">{{ customer.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}</span>
                                <span class="customer-option-copy"><strong>{{ customer.name }}</strong><small>{{ customer.customer_number }}<template v-if="customer.phone"> · {{ customer.phone }}</template></small></span>
                            </button>
                            <p v-if="customerSearchPending" class="customer-list-hint" role="status">Searching customers…</p>
                            <div v-else-if="customerSearchError" class="customer-no-results" role="alert"><strong>Search unavailable</strong><span>Check your connection and try again.</span></div>
                            <template v-else-if="customerQuery.trim().length >= 2 && !filteredCustomers.length">
                                <div class="customer-no-results"><strong>No customer found</strong><span>Try another spelling or add this customer.</span></div>
                                <button v-if="mode === 'issue'" class="customer-add-option" type="button" @mousedown.prevent @click="openCustomerOnboarding"><UserRoundPlus aria-hidden="true" /><span>Add “{{ customerQuery.trim() }}” as a customer</span><Plus aria-hidden="true" /></button>
                            </template>
                            <p v-else-if="!customerQuery.trim()" class="customer-list-hint">Type a name, customer number, or phone to search.</p>
                        </div>
                    </div>
                    <small v-if="form.customer_id && selectedCustomer">Selected: {{ selectedCustomer.customer_number }}{{ selectedCustomer.phone ? ` · ${selectedCustomer.phone}` : '' }}</small>
                    <small v-if="form.errors.customer_id" class="form-error">{{ form.errors.customer_id }}</small>
                </div>
                <div v-if="trackingType === 'bulk'" class="form-field full">
                    <label for="bulk-product">Bulk product</label>
                    <div class="bulk-select-row"><select id="bulk-product" v-model="selectedBulkProduct" class="select-field"><option value="" disabled>Select a product</option><option v-for="product in bulkProducts" :key="product.id" :value="String(product.id)" :disabled="bulkAvailable(product.id) < 1 || bulkLines.some((line) => line.product_id === product.id)">{{ product.name }} · {{ product.sku }} · {{ bulkAvailable(product.id) }} available</option></select><input v-model="bulkQuantityInput" class="field quantity-field" type="number" min="1" :max="selectedBulk ? bulkAvailable(selectedBulk.id) : 1" aria-label="Quantity to issue"><button type="button" class="secondary-link" :disabled="!selectedBulk || Number(bulkQuantityInput) > bulkAvailable(selectedBulk.id)" @click="addBulkLine"><Plus aria-hidden="true" /> Add quantity</button></div>
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
                    <div class="scan-input"><ScanLine aria-hidden="true" /><input id="asset-code" v-model="scanInput" class="field" type="text" autocomplete="off" autofocus placeholder="Type or scan a barcode" @keydown.enter.prevent="addCode(scanInput)"><button class="secondary-link scan-add" type="button" :disabled="availabilityPending" @click="addCode(scanInput)"><Plus aria-hidden="true" /> Check</button></div>
                    <small>Availability checks automatically as you type, or use a camera scanner. {{ allCodes.length }} gown{{ allCodes.length === 1 ? '' : 's' }} prepared.</small>
                    <BarcodeScanner @detected="addCode" />
                    <small v-if="scanFeedback" role="status" aria-live="polite" class="scan-feedback" :class="`scan-feedback--${scanFeedbackTone}`">{{ scanFeedback }}</small>
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
            <div class="form-actions"><Link href="/hiring" class="secondary-link"><X aria-hidden="true" /> Cancel</Link><button class="primary-button" type="submit" :disabled="form.processing || !online || !form.customer_id || availabilityPending || (trackingType === 'serialized' ? !allCodes.length : !bulkLines.length)"><Check aria-hidden="true" /> {{ !online ? 'Reconnect to continue' : form.processing ? (mode === 'reserve' ? 'Reserving…' : 'Recording issue…') : (mode === 'reserve' ? 'Confirm gown reservation' : 'Confirm gown issue') }}</button></div>
        </form>

        <div v-if="showSuccessReward" class="issue-success-backdrop" role="presentation" @click.self="successRewardDismissed = true">
            <section class="issue-success-card" role="dialog" aria-modal="true" aria-labelledby="issue-success-title">
                <button class="issue-success-close" type="button" aria-label="Close success message" @click="successRewardDismissed = true"><X aria-hidden="true" /></button>
                <div class="issue-success-confetti" aria-hidden="true"><i v-for="piece in 12" :key="piece" :style="{ '--piece': piece }" /></div>
                <div class="issue-success-icon"><CircleCheck :size="38" aria-hidden="true" /></div>
                <Sparkles class="issue-success-sparkle" :size="22" aria-hidden="true" />
                <h2 id="issue-success-title">Gown issue complete!</h2>
                <p>{{ successReward }}</p>
                <button class="primary-button" type="button" @click="successRewardDismissed = true"><Check aria-hidden="true" /> Continue</button>
            </section>
        </div>

        <div v-if="customerDialogOpen" class="customer-dialog-backdrop" role="presentation" @click.self="customerDialogOpen = false" @keydown.esc="customerDialogOpen = false">
            <section class="customer-dialog" role="dialog" aria-modal="true" aria-labelledby="new-customer-title">
                <header class="customer-dialog-heading"><div><span class="customer-dialog-kicker">CUSTOMER ONBOARDING</span><h2 id="new-customer-title">Add a customer</h2><p>Create a profile and assign it to the selected pickup location.</p></div><button class="customer-dialog-close" type="button" aria-label="Close customer form" @click="customerDialogOpen = false"><X aria-hidden="true" /></button></header>
                <form class="customer-dialog-form" @submit.prevent="createCustomer">
                    <label class="form-field"><span>Full name <span class="required-mark">*</span></span><input v-model="newCustomerForm.name" class="field" type="text" required maxlength="255" autocomplete="name" autofocus placeholder="Customer name"><small v-if="newCustomerForm.errors.name" class="form-error">{{ newCustomerForm.errors.name }}</small></label>
                    <label class="form-field"><span>Phone number</span><input v-model="newCustomerForm.phone" class="field" type="tel" autocomplete="tel" placeholder="0712 345 678"><small>Kenyan numbers are saved in +254 format.</small><small v-if="newCustomerForm.errors.phone" class="form-error">{{ newCustomerForm.errors.phone }}</small></label>
                    <label class="form-field"><span>Email <span class="muted">(optional)</span></span><input v-model="newCustomerForm.email" class="field" type="email" autocomplete="email" placeholder="name@example.com"><small v-if="newCustomerForm.errors.email" class="form-error">{{ newCustomerForm.errors.email }}</small></label>
                    <label class="form-field"><span>Customer type</span><select v-model="newCustomerForm.type" class="select-field"><option value="individual">Individual</option><option value="institution">Institution</option></select></label>
                    <label class="form-field"><span>Institution <span class="muted">(optional)</span></span><select v-model="newCustomerForm.institution_id" class="select-field"><option value="">No institution linked</option><option v-for="institution in institutions" :key="institution.id" :value="String(institution.id)">{{ institution.name }}</option></select><small v-if="newCustomerForm.errors.institution_id" class="form-error">{{ newCustomerForm.errors.institution_id }}</small></label>
                    <div v-if="newCustomerForm.errors.location_id" class="form-error">{{ newCustomerForm.errors.location_id }}</div>
                    <footer class="customer-dialog-actions"><button class="secondary-link" type="button" :disabled="newCustomerForm.processing" @click="customerDialogOpen = false"><X aria-hidden="true" /> Cancel</button><button class="primary-button" type="submit" :disabled="newCustomerForm.processing || !newCustomerForm.name.trim() || !newCustomerForm.location_id"><UserRoundPlus aria-hidden="true" /> {{ newCustomerForm.processing ? 'Saving customer…' : 'Save and select customer' }}</button></footer>
                </form>
            </section>
        </div>
    </AppShell>
</template>

<style scoped>
.scan-input { position: relative; }
.scan-input > svg { position: absolute; z-index: 1; top: 13px; left: 12px; width: 17px; height: 17px; color: #606874; }
.scan-input .field { padding-left: 38px; }
.scan-add { min-height: 42px; }
.scan-feedback { display: block; margin-top: 4px; }
.scan-feedback--info { color: #596170; }
.scan-feedback--success { color: #12804a; font-weight: 650; }
.scan-feedback--error { color: #c8202f; font-weight: 650; }
.submit-error-summary { display: flex; align-items: flex-start; gap: 9px; margin-bottom: 16px; border: 1px solid #f2c9c9; border-radius: 8px; padding: 12px 14px; background: #fff5f5; color: #a91c28; font-size: 13px; font-weight: 600; line-height: 1.5; }
.submit-error-summary svg { flex: 0 0 auto; margin-top: 1px; }
.scanned-list { display: grid; max-height: 160px; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 5px; overflow-y: auto; margin-top: 8px; }
.scanned-code { display: flex; align-items: center; justify-content: space-between; border: 1px solid #e4e6e8; border-radius: 6px; padding: 5px 8px; color: #303744; font-size: 13px; }
.scanned-code button { border: 0; background: transparent; color: #606874; cursor: pointer; }
.tracking-choice { display: flex; flex-direction: row; gap: 20px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
.tracking-choice legend { padding: 0 5px; color: #596170; font-size: 13px; font-weight: 650; }
.tracking-choice label { display: flex; flex-direction: row; align-items: center; gap: 6px; }
.bulk-select-row { display: grid; grid-template-columns: minmax(0,1fr) 100px auto; gap: 8px; }
.quantity-field { min-width: 0; }
.bulk-line-list { display: grid; gap: 5px; margin-top: 8px; }
.bulk-line-list > div { display: flex; justify-content: space-between; border: 1px solid #e4e6e8; border-radius: 6px; padding: 8px; font-size: 13px; }
.bulk-line-list button { border: 0; background: transparent; color: #606874; cursor: pointer; }
.customer-picker { position: relative; }
.customer-search-input { padding-left: 39px; }
.customer-search-icon { position: absolute; z-index: 1; top: 12px; left: 12px; width: 17px; height: 17px; color: #747c88; pointer-events: none; }
.customer-options { position: absolute; z-index: 40; top: calc(100% + 6px); left: 0; right: 0; max-height: 310px; overflow-y: auto; border: 1px solid #e2e5e9; border-radius: 11px; background: #fff; padding: 5px; box-shadow: 0 14px 35px rgb(26 33 44 / 16%); }
.customer-option { display: flex; width: 100%; min-height: 54px; align-items: center; gap: 10px; border: 0; border-radius: 7px; background: transparent; padding: 7px 9px; cursor: pointer; text-align: left; }
.customer-option:hover, .customer-option[aria-selected="true"] { background: #f7f8f9; }
.customer-option-avatar { display: grid; width: 34px; height: 34px; flex: 0 0 34px; place-items: center; border-radius: 50%; background: #f9eeee; color: #bd2229; font-size: 12px; font-weight: 750; }
.customer-option-copy { display: grid; min-width: 0; gap: 3px; }
.customer-option-copy strong, .customer-option-copy small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.customer-option-copy strong { color: #252c37; font-size: 13px; }
.customer-option-copy small { color: #596371; font-size: 12px; }
.customer-no-results, .customer-list-hint { display: grid; gap: 4px; margin: 0; padding: 13px 11px; color: #596371; font-size: 12px; }
.customer-no-results strong { color: #343b47; font-size: 13px; }
.customer-add-option { display: flex; width: 100%; min-height: 42px; align-items: center; gap: 9px; border: 0; border-radius: 7px; background: #fff7f7; padding: 0 10px; color: #b71f26; cursor: pointer; font-size: 13px; font-weight: 650; text-align: left; }
.customer-add-option:hover { background: #ffeded; }
.customer-add-option > svg:first-child { width: 17px; height: 17px; }
.customer-add-option > span { flex: 1; }
.customer-add-option > svg:last-child { width: 15px; height: 15px; }
.customer-dialog-backdrop { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; overflow-y: auto; padding: 20px; background: rgb(17 24 39 / 58%); backdrop-filter: blur(3px); }
.customer-dialog { width: min(520px, 100%); max-height: min(92vh, 760px); overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; padding: 23px; box-shadow: 0 24px 80px rgb(0 0 0 / 24%); }
.customer-dialog-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.customer-dialog-kicker { color: #ae2027; font-size: 12px; font-weight: 750; letter-spacing: .1em; }
.customer-dialog-heading h2 { margin: 5px 0 0; color: #171c26; font-size: 19px; font-weight: 750; letter-spacing: -.025em; }
.customer-dialog-heading p { margin: 6px 0 0; color: #596371; font-size: 12px; line-height: 1.5; }
.customer-dialog-close { display: grid; width: 34px; height: 34px; flex: 0 0 34px; place-items: center; border: 1px solid #e5e8ed; border-radius: 9px; background: #fff; color: #505968; cursor: pointer; }
.customer-dialog-close svg { width: 17px; height: 17px; }
.customer-dialog-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.customer-dialog-form > .form-field:first-child, .customer-dialog-actions { grid-column: 1 / -1; }
.customer-dialog-form .field, .customer-dialog-form .select-field { width: 100%; }
.customer-dialog-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 5px; padding-top: 15px; border-top: 1px solid #edf0f2; }
.customer-dialog-actions .secondary-link { display: inline-flex; min-height: 40px; align-items: center; border: 1px solid #e3e6e9; border-radius: 7px; background: #fff; padding: 0 13px; color: #4d5663; cursor: pointer; font-size: 13px; font-weight: 650; }
.customer-dialog-actions .primary-button { min-height: 40px; padding: 0 14px; }
.customer-dialog-actions button:disabled { cursor: wait; opacity: .65; }
.required-mark { color: #c8202f; }
.primary-button:disabled { cursor: wait; opacity: .7; }
.issue-success-backdrop { position: fixed; z-index: 120; inset: 0; display: grid; place-items: center; padding: 20px; background: rgb(14 20 29 / 55%); backdrop-filter: blur(3px); }
.issue-success-card { position: relative; isolation: isolate; width: min(100%, 430px); overflow: hidden; border: 1px solid #e8ecef; border-radius: 18px; padding: 36px 30px 28px; background: #fff; box-shadow: 0 25px 80px rgb(0 0 0 / 24%); text-align: center; animation: issue-reward-pop .38s cubic-bezier(.18,.89,.32,1.28) both; }
.issue-success-close { position: absolute; z-index: 3; top: 12px; right: 12px; display: grid; width: 34px; height: 34px; place-items: center; border: 0; border-radius: 50%; background: #f2f4f5; color: #596170; cursor: pointer; }
.issue-success-icon { position: relative; z-index: 2; display: grid; width: 76px; height: 76px; place-items: center; margin: 0 auto 15px; border: 1px solid #c6ecd8; border-radius: 50%; background: #e9f9f0; color: #12804a; }
.issue-success-sparkle { position: absolute; top: 84px; left: calc(50% + 37px); color: #c69b42; animation: issue-sparkle 1.5s ease-in-out infinite; }
.issue-success-card h2 { position: relative; z-index: 2; margin: 0 0 8px; color: #152238; font-size: 23px; }
.issue-success-card p { position: relative; z-index: 2; margin: 0 0 22px; color: #596170; font-size: 14px; line-height: 1.6; }
.issue-success-card .primary-button { position: relative; z-index: 2; justify-content: center; min-width: 150px; }
.issue-success-confetti { position: absolute; inset: 0 0 auto; height: 135px; overflow: hidden; pointer-events: none; }
.issue-success-confetti i { position: absolute; top: -14px; left: calc((var(--piece) - 1) * 9% + 4%); width: 7px; height: 13px; border-radius: 2px; background: hsl(calc(var(--piece) * 31) 68% 52%); transform: rotate(calc(var(--piece) * 19deg)); animation: issue-confetti-fall 1.6s ease-out calc(var(--piece) * 35ms) both; }
@keyframes issue-reward-pop { from { opacity: 0; transform: translateY(12px) scale(.94); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes issue-confetti-fall { to { transform: translateY(155px) rotate(300deg); opacity: 0; } }
@keyframes issue-sparkle { 50% { transform: scale(1.2) rotate(18deg); opacity: .65; } }
@media(prefers-reduced-motion:reduce) { .issue-success-card, .issue-success-confetti i, .issue-success-sparkle { animation: none; } }
@media(max-width:640px) { .bulk-select-row { grid-template-columns: 1fr 85px; }.bulk-select-row button { grid-column: 1 / -1; }.customer-dialog-backdrop { align-items: end; padding: 8px; }.customer-dialog { max-height: 92vh; border-radius: 16px 16px 10px 10px; padding: 18px; }.customer-dialog-form { grid-template-columns: 1fr; gap: 12px; }.customer-dialog-form > .form-field:first-child, .customer-dialog-actions { grid-column: auto; }.customer-dialog-actions { position: sticky; bottom: -18px; margin: 0 -18px -18px; padding: 12px 18px 18px; background: #fff; }.customer-dialog-actions button { flex: 1; justify-content: center; } }
</style>
