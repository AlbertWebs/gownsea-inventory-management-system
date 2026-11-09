<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CircleHelp, ScanLine, ShieldAlert } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';
import BarcodeScanner from '../../Components/BarcodeScanner.vue';
import { useOnlineStatus } from '../../composables/useOnlineStatus';

type Location = { id: number; name: string; type: string };
type BulkHireItem = { id: number; hireReference: string; customer: string; origin: string; product: string; dueAt: string | null; remaining: number };
const props = defineProps<{ locations: Location[]; defaultLocationId: number | null; bulkHireItems: BulkHireItem[] }>();
const online = useOnlineStatus();
const form = useForm({
    asset_code: '',
    location_id: props.defaultLocationId ? String(props.defaultLocationId) : (props.locations[0]?.id ? String(props.locations[0].id) : ''),
    condition: 'good',
    notes: '',
    idempotency_key: crypto.randomUUID(),
});
const bulkForm = useForm({
    hire_item_id: '', quantity: '1', location_id: props.defaultLocationId ? String(props.defaultLocationId) : (props.locations[0]?.id ? String(props.locations[0].id) : ''),
    condition: 'good', notes: '', idempotency_key: crypto.randomUUID(),
});

function submitBulk(): void {
    bulkForm.post('/returns/bulk', { preserveScroll: true, onSuccess: () => { bulkForm.hire_item_id = ''; bulkForm.quantity = '1'; bulkForm.notes = ''; bulkForm.idempotency_key = crypto.randomUUID(); } });
}

function submit(): void {
    form.post('/returns/scan', { preserveScroll: true, onSuccess: () => { form.asset_code = ''; form.idempotency_key = crypto.randomUUID(); } });
}
</script>

<template>
    <Head title="Scan return" />
    <AppShell active="hiring">
        <div class="page-heading">
            <div><h1>Scan a return</h1><p>Confirm a returned gown, record damage, or report an issued gown missing.</p></div>
            <Link href="/hiring" class="secondary-link"><ArrowLeft aria-hidden="true" /> Hiring & returns</Link>
        </div>
        <div class="workflow-note"><CircleHelp :size="16" aria-hidden="true" /> Returned gowns move into inspection status. A missing report removes the gown from available stock and requires separate manager approval before write-off.</div>
        <form class="panel form-card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="form-field full">
                    <label for="return-code">Gown barcode / asset ID</label>
                    <div class="scan-input"><ScanLine aria-hidden="true" /><input id="return-code" v-model="form.asset_code" class="field" type="text" autocomplete="off" autofocus placeholder="Scan or enter the gown barcode" required></div>
                    <small>Each serialized gown can be returned once. Repeated scans are safely ignored.</small>
                    <BarcodeScanner @detected="form.asset_code = $event" />
                    <small v-if="form.errors.asset_code" class="form-error">{{ form.errors.asset_code }}</small>
                </div>
                <div class="form-field">
                    <label for="location">Receiving location</label>
                    <select id="location" v-model="form.location_id" class="select-field" required><option value="" disabled>Select a location</option><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }} · {{ location.type }}</option></select>
                    <small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small>
                </div>
                <div class="form-field">
                    <label for="condition">Condition on return</label>
                    <select id="condition" v-model="form.condition" class="select-field" required><option value="good">Physically returned; requires inspection</option><option value="damaged">Physically returned damaged</option><option value="missing">Not returned; report as missing</option></select>
                    <small v-if="form.errors.condition" class="form-error">{{ form.errors.condition }}</small>
                </div>
                <div v-if="form.condition === 'damaged'" class="form-field full damage-callout"><ShieldAlert :size="18" aria-hidden="true" /><span>Damaged returns will remain unavailable pending an authorized repair or write-off decision.</span></div>
                <div v-if="form.condition === 'missing'" class="form-field full damage-callout"><ShieldAlert :size="18" aria-hidden="true" /><span>Use this only after confirming the gown was not physically returned. A separate manager must approve write-off.</span></div>
                <div class="form-field full">
                    <label for="notes">{{ form.condition === 'missing' ? 'Reason for missing report' : 'Inspection notes' }} <span class="muted">{{ form.condition === 'missing' ? '(required)' : '(optional)' }}</span></label>
                    <textarea id="notes" v-model="form.notes" class="textarea-field" rows="3" maxlength="2000" :required="form.condition === 'missing'" :placeholder="form.condition === 'missing' ? 'Describe the customer report or discrepancy' : 'Record visible damage or return notes'" />
                </div>
            </div>
            <div class="form-actions"><Link href="/hiring" class="secondary-link">Cancel</Link><button class="primary-button" type="submit" :disabled="form.processing || !online">{{ !online ? 'Reconnect to return a gown' : form.processing ? 'Recording return…' : 'Confirm return' }}</button></div>
        </form>
        <section v-if="bulkHireItems.length" class="panel form-card bulk-return">
            <div class="section-heading"><div><h2>Return bulk quantities</h2><p>Select the active hire line and count received units.</p></div></div>
            <form class="form-grid" @submit.prevent="submitBulk">
                <div class="form-field full"><label for="bulk-hire-item">Active bulk hire</label><select id="bulk-hire-item" v-model="bulkForm.hire_item_id" class="select-field" required><option value="" disabled>Select customer and product</option><option v-for="item in bulkHireItems" :key="item.id" :value="String(item.id)">{{ item.hireReference }} · {{ item.customer }} · {{ item.product }} · {{ item.remaining }} remaining{{ item.dueAt ? ` · due ${item.dueAt}` : '' }}</option></select><small v-if="bulkForm.errors.hire_item_id" class="form-error">{{ bulkForm.errors.hire_item_id }}</small></div>
                <div class="form-field"><label for="bulk-return-qty">Quantity received</label><input id="bulk-return-qty" v-model="bulkForm.quantity" class="field" type="number" min="1" :max="bulkHireItems.find((item) => item.id === Number(bulkForm.hire_item_id))?.remaining ?? 1" required><small v-if="bulkForm.errors.quantity" class="form-error">{{ bulkForm.errors.quantity }}</small></div>
                <div class="form-field"><label for="bulk-return-location">Receiving location</label><select id="bulk-return-location" v-model="bulkForm.location_id" class="select-field" required><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }} · {{ location.type }}</option></select></div>
                <div class="form-field"><label for="bulk-return-condition">Condition</label><select id="bulk-return-condition" v-model="bulkForm.condition" class="select-field"><option value="good">Requires inspection</option><option value="damaged">Damaged</option></select></div>
                <div class="form-field full"><label for="bulk-return-notes">Return notes <span class="muted">(optional)</span></label><textarea id="bulk-return-notes" v-model="bulkForm.notes" class="textarea-field" rows="2" maxlength="2000" /></div>
                <small v-if="bulkForm.errors.hire_item_id || bulkForm.errors.quantity || bulkForm.errors.condition" class="form-error">{{ bulkForm.errors.hire_item_id || bulkForm.errors.quantity || bulkForm.errors.condition }}</small>
                <div class="form-actions full"><button class="primary-button" type="submit" :disabled="bulkForm.processing || !online || !bulkForm.hire_item_id">{{ !online ? 'Reconnect to return stock' : bulkForm.processing ? 'Recording return…' : 'Confirm bulk return' }}</button></div>
            </form>
        </section>
    </AppShell>
</template>

<style scoped>
.scan-input { position: relative; }
.scan-input > svg { position: absolute; z-index: 1; top: 13px; left: 12px; width: 17px; height: 17px; color: #606874; }
.scan-input .field { padding-left: 38px; }
.damage-callout { display: flex; flex-direction: row; align-items: center; border-radius: 8px; background: #fff3e8; padding: 11px; color: #8c551a; font-size: 11px; }
.primary-button:disabled { cursor: wait; opacity: .7; }
.bulk-return { margin-top: 14px; }
.section-heading { margin-bottom: 15px; }
.section-heading h2 { margin: 0 0 4px; color: #202633; font-size: 16px; }
.section-heading p { margin: 0; color: #606874; font-size: 11px; }
</style>
