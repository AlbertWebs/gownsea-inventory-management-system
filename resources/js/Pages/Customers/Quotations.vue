<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileText, Plus, Save } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type LeadOption = { id: number; label: string; stage: string };
type FulfillmentType = 'custom_order' | 'bulk_hire' | 'bulk_sale' | 'serialized_hire';
type QuoteLine = { id: number; description: string; quantity: number; unitPrice: string; fulfillmentType: FulfillmentType; productId: number | null };
type ProductOption = { id: number; name: string; sku: string; trackingType: 'bulk' | 'serialized' };
type Attachment = { id: number; revision: number; name: string; sizeBytes: number };
type Quotation = { id: number; reference: string; leadId: number; leadName: string; institution: string | null; customer: string | null; customerId: number | null; customOrderReference: string | null; hireReference: string | null; saleReference: string | null; location: string | null; revision: number; status: string; approvalStatus: string; approvedBy: string | null; approvalReason: string | null; validUntil: string | null; subtotalMinor: number; totalMinor: number; notes: string | null; lines: QuoteLine[]; attachments: Attachment[] };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
const props = defineProps<{ quotations: Page<Quotation>; leads: LeadOption[]; products: ProductOption[]; canManage: boolean; canApprove: boolean; canConvert: boolean; canConvertHire: boolean; canConvertSale: boolean }>();
const editingId = ref<number | null>(null);
const emptyLine = () => ({ description: '', fulfillment_type: 'custom_order' as FulfillmentType, product_id: '', quantity: 1, unit_price: '0.00' });
const form = useForm({ lead_id: '', valid_until: '', notes: '', status: 'draft', change_reason: '', lines: [emptyLine()], attachments: [] as File[] });
const approvalId = ref<number | null>(null);
const approvalForm = useForm({ decision: 'approved', reason: '' });
const conversionId = ref<number | null>(null);
const conversionForm = useForm({ expected_completion_date: '', deposit_required: '0.00', quotation: '' });
const hireConversionId = ref<number | null>(null);
const hireConversionForm = useForm({ due_at: '', event_date: '', quotation: '' });
const saleConversionId = ref<number | null>(null);
const saleConversionForm = useForm({ method: 'cash', provider_reference: '', quotation: '' });
const serializedHireId = ref<number | null>(null);
const serializedHireTerms = useForm({ due_at: '', event_date: '' });
const serializedAssetCodes = ref<Record<number, string>>({});
const serializedHireErrors = ref<Record<string, string>>({});
function addLine(): void { form.lines.push(emptyLine()); }
function removeLine(index: number): void { if (form.lines.length > 1) form.lines.splice(index, 1); }
function changeFulfillmentType(line: (typeof form.lines)[number]): void { line.product_id = ''; }
function editQuote(quote: Quotation): void {
    editingId.value = quote.id; form.lead_id = String(quote.leadId); form.valid_until = quote.validUntil ?? ''; form.notes = quote.notes ?? '';
    form.status = quote.status; form.change_reason = ''; form.lines = quote.lines.map((line) => ({ description: line.description, fulfillment_type: line.fulfillmentType, product_id: line.productId ? String(line.productId) : '', quantity: line.quantity, unit_price: line.unitPrice }));
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function clearForm(): void { editingId.value = null; form.reset(); form.defaults({ lead_id: '', valid_until: '', notes: '', status: 'draft', change_reason: '', lines: [emptyLine()], attachments: [] }); }
function save(): void {
    if (editingId.value) form.patch(`/quotations/${editingId.value}`, { forceFormData: true, preserveScroll: true, onSuccess: clearForm });
    else form.post('/quotations', { forceFormData: true, preserveScroll: true, onSuccess: clearForm });
}
function selectAttachments(event: Event): void { form.attachments = Array.from((event.target as HTMLInputElement).files ?? []); }
function fileSize(bytes: number): string { return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`; }
const attachmentErrors = computed(() => Object.entries(form.errors).filter(([key]) => key.startsWith('attachments.')).map(([, message]) => message));
function reviewQuote(quote: Quotation, decision: 'approved' | 'rejected'): void {
    approvalId.value = quote.id;
    approvalForm.decision = decision;
    approvalForm.post(`/quotations/${quote.id}/review`, { preserveScroll: true, onSuccess: () => { approvalId.value = null; approvalForm.reset(); } });
}
function openConversion(quote: Quotation): void {
    conversionId.value = quote.id;
    const due = new Date(); due.setDate(due.getDate() + 14);
    conversionForm.expected_completion_date = `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}`;
    conversionForm.deposit_required = '0.00';
}
function convertQuote(quote: Quotation): void {
    conversionForm.post(`/quotations/${quote.id}/convert-to-custom-order`, { preserveScroll: true, onSuccess: () => { conversionId.value = null; conversionForm.reset(); } });
}
function openHireConversion(quote: Quotation): void {
    hireConversionId.value = quote.id;
    const due = new Date(); due.setDate(due.getDate() + 14);
    hireConversionForm.due_at = `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}`;
    hireConversionForm.event_date = '';
}
function convertHire(quote: Quotation): void {
    hireConversionForm.post(`/quotations/${quote.id}/convert-to-bulk-hire`, { preserveScroll: true, onSuccess: () => { hireConversionId.value = null; hireConversionForm.reset(); } });
}
function convertSale(quote: Quotation): void {
    saleConversionForm.post(`/quotations/${quote.id}/convert-to-bulk-sale`, { preserveScroll: true, onSuccess: () => { saleConversionId.value = null; saleConversionForm.reset(); } });
}
function openSerializedHire(quote: Quotation): void {
    serializedHireId.value = quote.id;
    const due = new Date(); due.setDate(due.getDate() + 14);
    serializedHireTerms.due_at = `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}`;
    serializedHireTerms.event_date = '';
    serializedAssetCodes.value = Object.fromEntries(quote.lines.map((line) => [line.id, '']));
}
function convertSerializedHire(quote: Quotation): void {
    const lines = quote.lines.map((line) => ({ quotation_line_id: line.id, asset_codes: (serializedAssetCodes.value[line.id] ?? '').split(/[\n,]/).map((code) => code.trim()).filter(Boolean) }));
    serializedHireErrors.value = {};
    router.post(`/quotations/${quote.id}/convert-to-serialized-hire`, { due_at: serializedHireTerms.due_at, event_date: serializedHireTerms.event_date || null, lines }, { preserveScroll: true, onError: (errors) => { serializedHireErrors.value = errors; }, onSuccess: () => { serializedHireId.value = null; serializedHireTerms.reset(); serializedAssetCodes.value = {}; serializedHireErrors.value = {}; } });
}
function productsForLine(type: FulfillmentType): ProductOption[] {
    const trackingType = type === 'serialized_hire' ? 'serialized' : 'bulk';
    return props.products.filter((product) => product.trackingType === trackingType);
}
const kes = (minor: number): string => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 2 }).format(minor / 100);
</script>

<template>
    <Head title="Quotations" />
    <AppShell active="customers">
        <div class="page-heading"><div><h1>Quotations</h1><p>Prepare itemized offers from CRM leads and track revisions and customer decisions.</p></div><div class="heading-actions"><Link href="/crm" class="secondary-link">CRM pipeline</Link><Link href="/customers" class="secondary-link">Customers</Link></div></div>
        <section v-if="canManage" class="panel quote-form-panel">
            <div class="form-title"><div><h2>{{ editingId ? 'Revise quotation' : 'Create quotation' }}</h2><p>Amounts use KES minor units; taxes are currently zero until tax settings are configured.</p></div><button v-if="editingId" class="secondary-link" type="button" @click="clearForm">Cancel</button></div>
            <form class="quote-form" @submit.prevent="save">
                <div class="form-field"><label for="quote-lead">Lead</label><select id="quote-lead" v-model="form.lead_id" class="select-field" required :disabled="!!editingId"><option value="" disabled>Select lead</option><option v-for="lead in leads" :key="lead.id" :value="lead.id">{{ lead.label }}</option></select><small v-if="form.errors.lead_id" class="form-error">{{ form.errors.lead_id }}</small></div>
                <div class="form-field"><label for="quote-valid">Valid until</label><input id="quote-valid" v-model="form.valid_until" class="field" type="date"><small v-if="form.errors.valid_until" class="form-error">{{ form.errors.valid_until }}</small></div>
                <div v-if="editingId" class="form-field"><label for="quote-status">Status</label><select id="quote-status" v-model="form.status" class="select-field"><option value="draft">Draft</option><option value="sent">Sent</option><option value="accepted">Accepted</option><option value="declined">Declined</option></select></div>
                <div v-if="editingId" class="form-field"><label for="quote-reason">Revision / decision reason</label><input id="quote-reason" v-model="form.change_reason" class="field" required minlength="5" maxlength="1000"><small v-if="form.errors.change_reason" class="form-error">{{ form.errors.change_reason }}</small></div>
                <div class="quote-lines full"><div class="line-heading"><strong>Quotation lines</strong><button class="secondary-link" type="button" @click="addLine"><Plus :size="14" /> Add line</button></div>
                    <div v-for="(line, index) in form.lines" :key="index" class="quote-line">
                        <div class="form-field"><label :for="`line-description-${index}`">Description</label><input :id="`line-description-${index}`" v-model="line.description" class="field" required maxlength="255"><small v-if="form.errors[`lines.${index}.description` as keyof typeof form.errors]" class="form-error">{{ form.errors[`lines.${index}.description` as keyof typeof form.errors] }}</small></div>
                        <div class="form-field"><label :for="`line-type-${index}`">Fulfillment</label><select :id="`line-type-${index}`" v-model="line.fulfillment_type" class="select-field" @change="changeFulfillmentType(line)"><option value="custom_order">Custom work</option><option value="serialized_hire">Serialized hire</option><option value="bulk_hire">Bulk hire</option><option value="bulk_sale">Bulk sale</option></select></div>
                        <div v-if="line.fulfillment_type !== 'custom_order'" class="form-field"><label :for="`line-product-${index}`">{{ line.fulfillment_type === 'serialized_hire' ? 'Serialized product' : 'Bulk product' }}</label><select :id="`line-product-${index}`" v-model="line.product_id" class="select-field" required><option value="">Select product</option><option v-for="product in productsForLine(line.fulfillment_type)" :key="product.id" :value="String(product.id)">{{ product.sku }} · {{ product.name }}</option></select><small v-if="form.errors[`lines.${index}.product_id` as keyof typeof form.errors]" class="form-error">{{ form.errors[`lines.${index}.product_id` as keyof typeof form.errors] }}</small></div>
                        <div class="form-field"><label :for="`line-quantity-${index}`">Quantity</label><input :id="`line-quantity-${index}`" v-model.number="line.quantity" class="field" type="number" min="1" max="100000" required></div>
                        <div class="form-field"><label :for="`line-price-${index}`">Unit price (KES)</label><input :id="`line-price-${index}`" v-model="line.unit_price" class="field" inputmode="decimal" required></div>
                        <button v-if="form.lines.length > 1" class="remove-line" type="button" :aria-label="`Remove line ${index + 1}`" @click="removeLine(index)">Remove</button>
                    </div>
                    <small v-if="form.errors.lines" class="form-error">{{ form.errors.lines }}</small>
                </div>
                <div class="form-field full"><label for="quote-notes">Notes and terms</label><textarea id="quote-notes" v-model="form.notes" class="field" rows="2" maxlength="3000"></textarea></div>
                <div class="form-field full"><label for="quote-attachments">Supporting documents</label><input id="quote-attachments" class="field file-field" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" @change="selectAttachments"><small>Up to 5 files, 10 MB each. Files stay private and attach to this quotation revision.</small><small v-if="form.errors.attachments" class="form-error">{{ form.errors.attachments }}</small><small v-for="(message, index) in attachmentErrors" :key="index" class="form-error">{{ message }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="form.processing"><Save aria-hidden="true" /> {{ form.processing ? 'Saving…' : editingId ? 'Save revision' : 'Create draft' }}</button></div>
            </form>
        </section>
        <section class="panel table-panel">
            <div v-if="quotations.data.length" class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Quotation</th><th>Lead / institution</th><th>Branch</th><th>Revision</th><th>Valid until</th><th>Amount</th><th>Status</th><th v-if="canManage || canApprove || canConvert || canConvertHire || canConvertSale">Manage</th></tr></thead>
                    <tbody>
                        <template v-for="quote in quotations.data" :key="quote.id">
                            <tr>
                                <td><strong>{{ quote.reference }}</strong><small class="table-subline">{{ quote.lines.length }} current line items</small><small v-for="attachment in quote.attachments" :key="attachment.id" class="table-subline"><a :href="`/quotations/${quote.id}/attachments/${attachment.id}`">{{ attachment.name }} · v{{ attachment.revision }} · {{ fileSize(attachment.sizeBytes) }}</a></small></td>
                                <td>{{ quote.leadName }} · {{ quote.institution ?? quote.customer ?? 'Prospect' }}</td><td>{{ quote.location ?? '—' }}</td><td>v{{ quote.revision }}</td><td>{{ quote.validUntil ?? '—' }}</td><td class="amount">{{ kes(quote.totalMinor) }}</td>
                                <td><span class="status-chip" :class="quote.status === 'accepted' ? 'completed' : quote.status === 'declined' ? 'damaged' : 'pending'">{{ quote.status }}</span><small class="table-subline">Approval: {{ quote.approvalStatus }}{{ quote.approvedBy ? ` by ${quote.approvedBy}` : '' }}</small><small v-if="quote.customOrderReference" class="table-subline">Order: {{ quote.customOrderReference }}</small><small v-if="quote.hireReference" class="table-subline">Hire: {{ quote.hireReference }}</small><small v-if="quote.saleReference" class="table-subline">Sale: {{ quote.saleReference }}</small></td>
                                <td v-if="canManage || canApprove || canConvert || canConvertHire || canConvertSale" class="quote-actions">
                                    <a v-if="canManage" class="secondary-link compact-action" :href="`/quotations/${quote.id}/pdf`" target="_blank" rel="noopener">Download PDF</a>
                                    <button v-if="canManage && !['accepted','declined','expired'].includes(quote.status)" class="secondary-link compact-action" type="button" @click="editQuote(quote)">Edit / update status</button>
                                    <button v-if="canConvert && quote.status === 'accepted' && !quote.customOrderReference && !quote.hireReference && !quote.saleReference && quote.customerId && quote.lines.every(line => line.fulfillmentType === 'custom_order')" class="secondary-link compact-action" type="button" @click="openConversion(quote)">Create custom order</button>
                                    <button v-if="canConvertHire && quote.status === 'accepted' && !quote.hireReference && !quote.customOrderReference && !quote.saleReference && quote.customerId && quote.lines.every(line => line.fulfillmentType === 'bulk_hire' && line.productId)" class="secondary-link compact-action" type="button" @click="openHireConversion(quote)">Reserve bulk hire</button>
                                    <button v-if="canConvertHire && quote.status === 'accepted' && !quote.hireReference && !quote.customOrderReference && !quote.saleReference && quote.customerId && quote.lines.every(line => line.fulfillmentType === 'serialized_hire' && line.productId)" class="secondary-link compact-action" type="button" @click="openSerializedHire(quote)">Reserve serialized hire</button>
                                    <button v-if="canConvertSale && quote.status === 'accepted' && !quote.saleReference && !quote.customOrderReference && !quote.hireReference && quote.customerId && quote.lines.every(line => line.fulfillmentType === 'bulk_sale' && line.productId)" class="secondary-link compact-action" type="button" @click="saleConversionId = quote.id">Complete bulk sale</button>
                                    <template v-if="canApprove && quote.status === 'draft' && quote.approvalStatus === 'pending'"><input v-model="approvalForm.reason" class="field approval-reason" maxlength="1000" minlength="5" required placeholder="Approval / rejection reason" :aria-label="`Approval reason for ${quote.reference}`"><button class="secondary-link compact-action" type="button" :disabled="approvalForm.processing || approvalForm.reason.trim().length < 5" @click="reviewQuote(quote, 'approved')">Approve</button><button class="secondary-link compact-action" type="button" :disabled="approvalForm.processing || approvalForm.reason.trim().length < 5" @click="reviewQuote(quote, 'rejected')">Reject</button></template>
                                    <small v-if="approvalId === quote.id && approvalForm.errors.reason" class="form-error">{{ approvalForm.errors.reason }}</small>
                                </td>
                            </tr>
                            <tr v-if="conversionId === quote.id"><td colspan="8"><form class="conversion-form" @submit.prevent="convertQuote(quote)"><label>Expected completion<input v-model="conversionForm.expected_completion_date" class="field" type="date" required></label><label>Required deposit (KES)<input v-model="conversionForm.deposit_required" class="field" inputmode="decimal" pattern="^\d{1,10}(\.\d{1,2})?$" required></label><button class="secondary-link compact-action" type="submit" :disabled="conversionForm.processing">{{ conversionForm.processing ? 'Creating…' : 'Create order' }}</button><button class="secondary-link compact-action" type="button" @click="conversionId = null">Cancel</button><small class="conversion-note">Accepted quote lines and total are copied to the order. Review measurements and delivery details in Custom Orders.</small><small v-if="conversionForm.errors.expected_completion_date" class="form-error">{{ conversionForm.errors.expected_completion_date }}</small><small v-if="conversionForm.errors.deposit_required" class="form-error">{{ conversionForm.errors.deposit_required }}</small></form></td></tr>
                            <tr v-if="hireConversionId === quote.id"><td colspan="8"><form class="conversion-form" @submit.prevent="convertHire(quote)"><label>Return due date<input v-model="hireConversionForm.due_at" class="field" type="date" required></label><label>Graduation/event date<input v-model="hireConversionForm.event_date" class="field" type="date"></label><button class="secondary-link compact-action" type="submit" :disabled="hireConversionForm.processing">{{ hireConversionForm.processing ? 'Reserving…' : 'Reserve mapped stock' }}</button><button class="secondary-link compact-action" type="button" @click="hireConversionId = null">Cancel</button><small class="conversion-note">The mapped bulk quantities are reserved now. Staff must scan and confirm them at customer handover.</small><small v-if="hireConversionForm.errors.due_at" class="form-error">{{ hireConversionForm.errors.due_at }}</small><small v-if="hireConversionForm.errors.quotation" class="form-error">{{ hireConversionForm.errors.quotation }}</small></form></td></tr>
                            <tr v-if="serializedHireId === quote.id"><td colspan="8"><form class="conversion-form serial-conversion-form" @submit.prevent="convertSerializedHire(quote)"><label>Return due date<input v-model="serializedHireTerms.due_at" class="field" type="date" required></label><label>Graduation/event date<input v-model="serializedHireTerms.event_date" class="field" type="date"></label><div v-for="line in quote.lines" :key="line.id" class="serial-code-field"><label :for="`quote-assets-${quote.id}-${line.id}`">{{ line.description }} · scan {{ line.quantity }} asset code(s)</label><textarea :id="`quote-assets-${quote.id}-${line.id}`" v-model="serializedAssetCodes[line.id]" class="field" rows="2" placeholder="One barcode per line or comma separated" required></textarea></div><button class="secondary-link compact-action" type="submit">Reserve scanned assets</button><button class="secondary-link compact-action" type="button" @click="serializedHireId = null">Cancel</button><small class="conversion-note">Each scanned asset must match its quoted product and be available at the quotation branch.</small><small v-if="serializedHireTerms.errors.due_at" class="form-error">{{ serializedHireTerms.errors.due_at }}</small><small v-for="(message, field) in serializedHireErrors" :key="field" class="form-error">{{ message }}</small></form></td></tr>
                            <tr v-if="saleConversionId === quote.id"><td colspan="8"><form class="conversion-form" @submit.prevent="convertSale(quote)"><label>Payment method<select v-model="saleConversionForm.method" class="select-field"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mpesa">M-Pesa</option><option value="other">Other</option></select></label><label v-if="saleConversionForm.method === 'mpesa'">M-Pesa receipt<input v-model="saleConversionForm.provider_reference" class="field" maxlength="120" required><small v-if="saleConversionForm.errors.provider_reference" class="form-error">{{ saleConversionForm.errors.provider_reference }}</small></label><button class="secondary-link compact-action" type="submit" :disabled="saleConversionForm.processing">{{ saleConversionForm.processing ? 'Completing…' : `Record ${kes(quote.totalMinor)} paid sale` }}</button><button class="secondary-link compact-action" type="button" @click="saleConversionId = null">Cancel</button><small class="conversion-note">This records payment and stock issue immediately at the accepted quoted prices. Confirm payment before submitting.</small><small v-if="saleConversionForm.errors.quotation" class="form-error">{{ saleConversionForm.errors.quotation }}</small></form></td></tr>
                            <tr v-if="quote.approvalReason"><td colspan="8"><small class="table-subline">Review note: {{ quote.approvalReason }}</small></td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div v-else class="empty-state"><div><FileText aria-hidden="true" /><strong>No quotations yet</strong><span>Create a draft from an open CRM lead.</span></div></div>
            <Pagination v-if="quotations.total > 0" :links="quotations.links" :from="quotations.from" :to="quotations.to" :total="quotations.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.quote-form-panel { margin-bottom: 13px; padding: 17px; }.form-title { display: flex; justify-content: space-between; margin-bottom: 13px; }.form-title h2 { margin: 0; font-size: 14px; }.form-title p { margin: 4px 0 0; color: #606874; font-size: 10px; }
.conversion-form { display:flex; align-items:end; flex-wrap:wrap; gap:10px; padding:8px 0; }.conversion-form label { display:grid; gap:4px; color:#657080; font-size:10px; }.conversion-form label input { min-width:150px; }.conversion-note { flex-basis:100%; color:#606874; font-size:10px; }
.quote-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; align-items: end; }.quote-form .full { grid-column: 1 / -1; }.quote-form .form-actions { grid-column: 1 / -1; justify-content: flex-start; margin-top: 0; }
.line-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 11px; }.quote-line { display: grid; grid-template-columns: minmax(150px, 1.7fr) minmax(125px, 1fr) minmax(150px, 1.25fr) minmax(80px, .55fr) minmax(120px, 1fr) auto; gap: 10px; align-items: end; padding: 10px 0; border-top: 1px solid #eceef0; }.remove-line { margin-bottom: 5px; border: 0; background: transparent; color: #bd2228; font-size: 10px; cursor: pointer; }.compact-action { min-height: 29px; padding: 5px 9px; }.quote-actions { display:flex; flex-wrap:wrap; gap:6px; }.approval-reason { max-width:190px; min-height:30px; padding:5px 8px; font-size:11px; }
@media(max-width:750px) { .quote-form { grid-template-columns: repeat(2,minmax(0,1fr)); }.quote-line { grid-template-columns: 1fr 1fr; }.quote-line .form-field:first-child { grid-column: 1/-1; } }
@media(max-width:520px) { .quote-form { grid-template-columns: 1fr; }.quote-form .full,.quote-form .form-actions { grid-column: auto; } }
</style>
