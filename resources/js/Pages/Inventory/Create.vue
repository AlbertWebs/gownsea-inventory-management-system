<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';

type Location = { id: number; name: string };
type Classification = { id: number; label: string; code: string };
type Institution = { id: number; name: string; type: string };
const props = defineProps<{ locations: Location[]; classifications: Classification[]; institutions: Institution[]; defaultLocationId: number | null }>();
const form = useForm({ sku: '', name: '', category: 'gown', tracking_type: 'serialized', initial_quantity: '0', location_id: props.defaultLocationId ? String(props.defaultLocationId) : String(props.locations[0]?.id ?? ''), classification_id: '', qualification_level: '', institution_id: '', faculty: '', description: '', size: '', color: '', purchase_cost: '0', useful_life_months: '36', sale_price: '0', hire_rate: '0', reorder_threshold: '0', asset_codes_text: '', image: null as File | null });
const errors = form.errors as Record<string, string>;
const imagePreview = ref('');
let previewUrl = '';
function submit(): void {
    const codes = form.tracking_type === 'serialized' ? form.asset_codes_text.split(/[\n,\t ]+/).map((code) => code.trim()).filter(Boolean) : [];
    form.transform((data) => ({ ...data, asset_codes: codes })).post('/inventory');
}
function selectImage(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = file ? URL.createObjectURL(file) : '';
    imagePreview.value = previewUrl;
}
onBeforeUnmount(() => { if (previewUrl) URL.revokeObjectURL(previewUrl); });
</script>

<template>
    <Head title="Register inventory" />
    <AppShell active="inventory">
        <div class="page-heading"><div><h1>Register inventory</h1><p>Add a product and register serialized units or fungible bulk stock at a confirmed location.</p></div><Link href="/inventory" class="secondary-link"><ArrowLeft aria-hidden="true" /> Inventory</Link></div>
        <div class="workflow-note">Serialized products track each gown by barcode. Bulk products track an available quantity by branch and use an append-only movement ledger.</div>
        <form v-if="locations.length" class="panel form-card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="form-field"><label for="sku">Product SKU</label><input id="sku" v-model="form.sku" class="field" required maxlength="64"><small v-if="form.errors.sku" class="form-error">{{ form.errors.sku }}</small></div>
                <div class="form-field"><label for="name">Product name</label><input id="name" v-model="form.name" class="field" required maxlength="255"><small v-if="form.errors.name" class="form-error">{{ form.errors.name }}</small></div>
                <div class="form-field"><label for="category">Category</label><select id="category" v-model="form.category" class="select-field"><option v-for="category in ['gown','cap','hood','stole','tassel','accessory','other']" :key="category" :value="category">{{ category }}</option></select></div>
                <div class="form-field"><label for="tracking-type">Stock tracking</label><select id="tracking-type" v-model="form.tracking_type" class="select-field"><option value="serialized">Serialized assets</option><option value="bulk">Bulk quantities</option></select></div>
                <div class="form-field"><label for="location">Opening stock location</label><select id="location" v-model="form.location_id" class="select-field" required><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small></div>
                <div class="form-field"><label for="classification">Academic classification <span class="muted">(optional)</span></label><select id="classification" v-model="form.classification_id" class="select-field"><option value="">Not specified</option><option v-for="item in classifications" :key="item.id" :value="item.id">{{ item.label }}</option></select></div>
                <div class="form-field"><label for="qualification">Award / qualification level <span class="muted">(optional)</span></label><select id="qualification" v-model="form.qualification_level" class="select-field"><option value="">Not specified</option><option value="certificate">Certificate</option><option value="diploma">Diploma</option><option value="bachelors">Bachelor's Degree</option><option value="masters">Master's Degree</option><option value="phd">PhD / Doctoral</option></select><small>Institution type and gown business classification are recorded separately.</small><small v-if="form.errors.qualification_level" class="form-error">{{ form.errors.qualification_level }}</small></div>
                <div class="form-field"><label for="institution">Institution <span class="muted">(optional)</span></label><select id="institution" v-model="form.institution_id" class="select-field"><option value="">Not specified</option><option v-for="institution in institutions" :key="institution.id" :value="String(institution.id)">{{ institution.name }} · {{ institution.type }}</option></select><small v-if="form.errors.institution_id" class="form-error">{{ form.errors.institution_id }}</small></div>
                <div class="form-field"><label for="faculty">Faculty / school <span class="muted">(optional)</span></label><input id="faculty" v-model="form.faculty" class="field" maxlength="120"></div>
                <div class="form-field"><label for="size">Size <span class="muted">(optional)</span></label><input id="size" v-model="form.size" class="field" maxlength="24"></div>
                <div class="form-field"><label for="color">Color <span class="muted">(optional)</span></label><input id="color" v-model="form.color" class="field" maxlength="48"></div>
                <div class="form-field full"><label for="description">Description <span class="muted">(optional)</span></label><textarea id="description" v-model="form.description" class="textarea-field" rows="3" maxlength="2000"></textarea><small v-if="form.errors.description" class="form-error">{{ form.errors.description }}</small></div>
                <div class="form-field full"><label for="image">Product photo <span class="muted">(optional · JPG, PNG or WebP up to 5 MB)</span></label><div class="image-upload-row"><input id="image" class="field image-upload" type="file" accept="image/jpeg,image/png,image/webp" @change="selectImage"><img v-if="imagePreview" :src="imagePreview" alt="Selected product photo preview" class="image-preview"></div><small v-if="form.errors.image" class="form-error">{{ form.errors.image }}</small></div>
                <div class="form-field"><label for="purchase-cost">Unit purchase cost (KES)</label><input id="purchase-cost" v-model="form.purchase_cost" class="field" inputmode="decimal" required><small v-if="form.errors.purchase_cost" class="form-error">{{ form.errors.purchase_cost }}</small></div>
                <div v-if="form.tracking_type === 'serialized'" class="form-field"><label for="useful-life">Useful life (months)</label><input id="useful-life" v-model="form.useful_life_months" class="field" type="number" min="1" max="600" step="1" required><small>Full cost depreciated straight-line with zero residual value.</small><small v-if="form.errors.useful_life_months" class="form-error">{{ form.errors.useful_life_months }}</small></div>
                <div class="form-field"><label for="sale-price">Unit sale price (KES)</label><input id="sale-price" v-model="form.sale_price" class="field" inputmode="decimal" required><small v-if="form.errors.sale_price" class="form-error">{{ form.errors.sale_price }}</small></div>
                <div class="form-field"><label for="hire-rate">Hire rate (KES per item)</label><input id="hire-rate" v-model="form.hire_rate" class="field" type="number" min="0" step="0.01" required><small v-if="form.errors.hire_rate" class="form-error">{{ form.errors.hire_rate }}</small></div>
                <div class="form-field"><label for="reorder-threshold">Low stock alert at</label><input id="reorder-threshold" v-model="form.reorder_threshold" class="field" type="number" min="0" max="1000000" step="1" required><small v-if="form.errors.reorder_threshold" class="form-error">{{ form.errors.reorder_threshold }}</small></div>
                <div v-if="form.tracking_type === 'serialized'" class="form-field full"><label for="codes">Asset codes / barcodes</label><textarea id="codes" v-model="form.asset_codes_text" class="textarea-field" rows="7" required placeholder="GOWN-0001&#10;GOWN-0002&#10;GOWN-0003" /><small>Enter one unique code per line. Commas, spaces and scanner tabs also separate codes. Maximum 200.</small><small v-if="errors.asset_codes || errors['asset_codes.0']" class="form-error">{{ errors.asset_codes || errors['asset_codes.0'] }}</small></div>
                <div v-else class="form-field"><label for="initial-quantity">Opening quantity</label><input id="initial-quantity" v-model="form.initial_quantity" class="field" type="number" min="0" max="1000000" step="1" required><small>Opening balance can be zero; record receipts as stock arrives.</small><small v-if="form.errors.initial_quantity" class="form-error">{{ form.errors.initial_quantity }}</small></div>
            </div>
            <div class="form-actions"><Link href="/inventory" class="secondary-link">Cancel</Link><button class="primary-button" type="submit" :disabled="form.processing">{{ form.processing ? 'Registering…' : 'Register product and stock' }}</button></div>
        </form>
        <div v-else class="workflow-note">Your account has no active locations. Ask an administrator to assign a location before registering stock.</div>
    </AppShell>
</template>

<style scoped>
.image-upload-row { display:flex; align-items:center; gap:13px; }
.image-upload { padding:6px; }
.image-preview { width:78px; height:78px; border:1px solid #e6e8eb; border-radius:8px; object-fit:cover; }
@media(max-width:600px) { .image-upload-row { align-items:flex-start; flex-direction:column; } }
</style>
