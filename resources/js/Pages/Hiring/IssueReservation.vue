<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CircleHelp, ScanLine, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import BarcodeScanner from '../../Components/BarcodeScanner.vue';
import { useOnlineStatus } from '../../composables/useOnlineStatus';

type ReservedAsset = { code: string; product: string };
type ReservedBulkItem = { product: string; quantity: number };
type Reservation = { id: number; reference: string; customer: string; location: string; dueAt: string | null; isBulk: boolean; bulkItems: ReservedBulkItem[]; assets: ReservedAsset[] };
const props = defineProps<{ hire: Reservation }>();
const online = useOnlineStatus();
const codes = ref<string[]>([]);
const input = ref('');
const feedback = ref('');
const feedbackType = ref<'success' | 'error'>('success');
const confirmedCount = ref(false);
const totalReserved = computed(() => props.hire.isBulk
    ? props.hire.bulkItems.reduce((total, item) => total + item.quantity, 0)
    : props.hire.assets.length);
const scanProgress = computed(() => totalReserved.value ? Math.round(codes.value.length / totalReserved.value * 100) : 0);
const form = useForm({ asset_codes: [] as string[], bulk_handover_confirmed: false, idempotency_key: crypto.randomUUID() });
function addCode(value: string): void {
    const code = value.trim();
    if (!code) return;
    const reservedAsset = props.hire.assets.find((asset) => asset.code.toLocaleLowerCase() === code.toLocaleLowerCase());
    if (!reservedAsset) {
        feedbackType.value = 'error';
        feedback.value = `${code} is not on this reservation. Check the barcode and try again.`;
        input.value = '';
        return;
    }
    if (codes.value.includes(reservedAsset.code)) {
        feedbackType.value = 'error';
        feedback.value = `${code} has already been scanned.`;
        input.value = '';
        return;
    }
    codes.value.push(reservedAsset.code);
    input.value = '';
    feedbackType.value = 'success';
    feedback.value = `${codes.value.length} of ${props.hire.assets.length} reserved gowns scanned.`;
}
function removeCode(code: string): void {
    codes.value = codes.value.filter((item) => item !== code);
    feedbackType.value = 'success';
    feedback.value = `${codes.value.length} of ${props.hire.assets.length} reserved gowns scanned.`;
}
function issue(): void {
    form.asset_codes = [...codes.value];
    form.bulk_handover_confirmed = confirmedCount.value;
    form.post(`/hiring/${props.hire.id}/issue-reservation`, { preserveScroll: true, onSuccess: () => { codes.value = []; form.idempotency_key = crypto.randomUUID(); } });
}
const errors = form.errors as Record<string, string>;
</script>

<template>
    <Head :title="`Issue ${hire.reference}`" />
    <AppShell active="hiring">
        <div class="page-heading"><div><h1>Complete gown handover</h1><p>Confirm the reserved items as they are handed to the customer.</p></div><Link href="/hiring" class="secondary-link"><ArrowLeft aria-hidden="true" /> Hiring records</Link></div>
        <section class="handover-summary" aria-label="Reservation summary"><div class="summary-reference"><span>Reservation</span><strong>{{ hire.reference }}</strong></div><div class="summary-divider"/><div class="summary-detail"><span>Customer</span><strong>{{ hire.customer }}</strong></div><div class="summary-detail"><span>Handover location</span><strong>{{ hire.location }}</strong></div><div class="summary-detail"><span>Expected return</span><strong>{{ hire.dueAt ?? 'Not specified' }}</strong></div><div class="summary-quantity"><strong>{{ totalReserved }}</strong><span>{{ hire.isBulk ? 'units to hand over' : 'gowns to scan' }}</span></div></section>
        <div class="workflow-note"><CircleHelp :size="16" aria-hidden="true" /> {{ hire.isBulk ? 'Count the reserved quantities with the customer, then confirm the handover below.' : 'Each barcode is checked against this reservation. All reserved gowns must be scanned before handover can be completed.' }}</div>
        <section v-if="hire.isBulk" class="panel reservation-assets"><div class="assets-heading"><div><h2>Reserved quantities</h2><p>Check each product and quantity before confirming.</p></div><span class="asset-count">{{ totalReserved }} <small>units</small></span></div><div class="bulk-lines"><div v-for="item in hire.bulkItems" :key="item.product" class="bulk-line"><span class="bulk-quantity">{{ item.quantity }}</span><strong>{{ item.product }}</strong><span>reserved</span></div></div></section>
        <section v-else class="panel reservation-assets"><div class="assets-heading"><div><h2>Reserved gown list</h2><p>Use these barcode IDs to verify each physical gown.</p></div><span class="asset-count">{{ codes.length }} <small>/ {{ totalReserved }} scanned</small></span></div><div class="scan-progress" role="progressbar" :aria-valuenow="codes.length" :aria-valuemin="0" :aria-valuemax="totalReserved" :aria-label="`${codes.length} of ${totalReserved} reserved gowns scanned`"><span :style="{ width: `${scanProgress}%` }"/></div><div class="asset-list"><span v-for="asset in hire.assets" :key="asset.code" class="asset-chip" :class="{ 'asset-chip-scanned': codes.includes(asset.code) }"><Check v-if="codes.includes(asset.code)" aria-hidden="true"/><span class="asset-chip-copy"><strong>{{ asset.code }}</strong><small>{{ asset.product }}</small></span><small class="asset-scan-state">{{ codes.includes(asset.code) ? 'Scanned' : 'Waiting' }}</small></span></div></section>
        <form class="panel form-card" @submit.prevent="issue">
            <div v-if="!hire.isBulk" class="handover-form-heading"><div><h2>Scan gowns at handover</h2><p>Scan or enter each reserved barcode below.</p></div><span class="scan-counter" :class="{ 'scan-counter-complete': codes.length === totalReserved }">{{ codes.length === totalReserved ? 'All gowns verified' : `${totalReserved - codes.length} remaining` }}</span></div>
            <div v-if="hire.isBulk" class="handover-form-heading"><div><h2>Confirm counted handover</h2><p>Only confirm after verifying the quantities above with the customer.</p></div></div>
            <div class="form-grid"><div v-if="!hire.isBulk" class="form-field full"><label for="reserved-scan">Gown barcode / asset ID</label><div class="scan-input"><ScanLine aria-hidden="true" /><input id="reserved-scan" v-model="input" class="field" autocomplete="off" autofocus placeholder="Scan or enter a reserved barcode" @keydown.enter.prevent="addCode(input)"><button type="button" class="secondary-link scan-add" @click="addCode(input)">Add gown</button></div><small class="scan-feedback" :class="`feedback-${feedbackType}`" v-if="feedback" role="status">{{ feedback }}</small><BarcodeScanner @detected="addCode" /><small v-if="errors.asset_codes || errors['asset_codes.0']" class="form-error">{{ errors.asset_codes || errors['asset_codes.0'] }}</small></div>
                <div v-if="!hire.isBulk" class="form-field full">
                    <div class="scanned-heading"><label>Scanned gowns</label><small>{{ codes.length }} of {{ totalReserved }} confirmed</small></div>
                    <div v-if="codes.length" class="scan-list"><div v-for="code in codes" :key="code" class="scanned-code"><Check aria-hidden="true"/><span>{{ code }}</span><button type="button" :aria-label="`Remove ${code}`" title="Remove scan" @click="removeCode(code)"><Trash2 aria-hidden="true" /></button></div></div>
                    <div v-else class="scan-empty"><ScanLine aria-hidden="true"/><span>No gowns scanned yet. Scan the first barcode to begin.</span></div>
                </div>
            </div>
            <label v-if="hire.isBulk" class="count-confirmation"><input v-model="confirmedCount" type="checkbox"><span><strong>I confirm the handover count is correct.</strong><small>I have checked every listed quantity with the customer.</small></span></label><small v-if="form.errors.bulk_handover_confirmed" class="form-error">{{ form.errors.bulk_handover_confirmed }}</small>
            <div v-if="!online" class="offline-note">You need an internet connection to complete this handover.</div>
            <div class="form-actions"><Link href="/hiring" class="secondary-link">Cancel</Link><button class="primary-button handover-submit" type="submit" :disabled="form.processing || !online || (hire.isBulk ? !confirmedCount : codes.length !== totalReserved)">{{ !online ? 'Reconnect to continue' : form.processing ? 'Completing handover…' : hire.isBulk ? `Confirm handover · ${totalReserved} units` : codes.length === totalReserved ? `Confirm handover · ${totalReserved} gowns` : `Scan ${totalReserved - codes.length} more gown${totalReserved - codes.length === 1 ? '' : 's'}` }}</button></div>
        </form>
    </AppShell>
</template>

<style scoped>
.handover-summary { display: grid; grid-template-columns: minmax(150px, 1.1fr) 1px minmax(150px, 1.2fr) minmax(140px, 1fr) minmax(150px, 1fr) auto; align-items: center; gap: 18px; margin-bottom: 13px; border: 1px solid #e5e8ec; border-radius: 11px; background: linear-gradient(110deg, #fff 0%, #fffafa 100%); padding: 14px 18px; box-shadow: 0 3px 12px rgb(25 33 45 / 4%); }
.summary-reference, .summary-detail { display: grid; min-width: 0; gap: 4px; }
.summary-reference > span, .summary-detail > span { color: #727b87; font-size: 11px; font-weight: 650; text-transform: uppercase; letter-spacing: .04em; }
.summary-reference strong { color: #b51f29; font-size: 15px; }
.summary-detail strong { overflow: hidden; color: #27303d; font-size: 13px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
.summary-divider { height: 34px; background: #e7e9ed; }
.summary-quantity { display: grid; justify-items: center; gap: 1px; border-radius: 9px; background: #fff0f0; padding: 7px 12px; color: #b51f29; text-align: center; }
.summary-quantity strong { font-size: 20px; line-height: 1.1; }
.summary-quantity span { font-size: 10px; font-weight: 650; }
.reservation-assets { margin-bottom: 13px; padding: 17px; }
.assets-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.assets-heading h2, .handover-form-heading h2 { margin: 0 0 4px; color: #252d39; font-size: 15px; font-weight: 750; }
.assets-heading p, .handover-form-heading p { margin: 0; color: #67717d; font-size: 12px; }
.asset-count { flex: 0 0 auto; border-radius: 20px; background: #f2f4f6; padding: 6px 10px; color: #293341; font-size: 14px; font-weight: 750; }
.asset-count small { color: #68727f; font-size: 11px; font-weight: 600; }
.scan-progress { height: 7px; overflow: hidden; margin-bottom: 12px; border-radius: 10px; background: #eef0f2; }
.scan-progress span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #d9232d, #ef6b4d); transition: width .25s ease; }
.asset-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(225px, 1fr)); gap: 7px; }
.asset-chip { display: flex; min-width: 0; align-items: center; gap: 9px; border: 1px solid #e5e7e9; border-radius: 8px; background: #fff; padding: 8px 10px; font-size: 11px; transition: border-color .18s ease, background .18s ease; }
.asset-chip > svg { width: 15px; height: 15px; flex: 0 0 auto; color: #18845d; }
.asset-chip-copy { display: grid; min-width: 0; flex: 1; gap: 3px; }
.asset-chip strong { color: #303744; font-size: 12px; }
.asset-chip-copy small { overflow: hidden; color: #727b87; text-overflow: ellipsis; white-space: nowrap; }
.asset-chip .asset-scan-state { color: #9198a2; font-size: 10px; }
.asset-chip-scanned { border-color: #b9e2d0; background: #f1fbf6; }
.asset-chip-scanned .asset-scan-state { color: #237451; font-weight: 700; }
.bulk-lines { display: grid; gap: 7px; }
.bulk-line { display: grid; grid-template-columns: 38px minmax(0, 1fr) auto; align-items: center; gap: 10px; border: 1px solid #eaecf0; border-radius: 8px; background: #fff; padding: 8px 10px; }
.bulk-line strong { color: #303744; font-size: 12px; }
.bulk-line > span:last-child { color: #78818d; font-size: 11px; }
.bulk-quantity { display: grid; width: 30px; height: 30px; place-items: center; border-radius: 8px; background: #fff0f0; color: #b51f29; font-size: 13px; font-weight: 750; }
.handover-form-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; border-bottom: 1px solid #eceef1; padding-bottom: 12px; }
.scan-counter { flex: 0 0 auto; border: 1px solid #f1ddb1; border-radius: 20px; background: #fff8e8; padding: 6px 10px; color: #8e651a; font-size: 11px; font-weight: 700; }
.scan-counter-complete { border-color: #cce8dc; background: #edfaf4; color: #237451; }
.count-confirmation { display: flex; align-items: flex-start; gap: 10px; margin-top: 16px; border: 1px solid #e5e8ec; border-radius: 9px; background: #fafbfc; padding: 12px; color: #414957; font-size: 12px; cursor: pointer; }
.count-confirmation input { width: 17px; height: 17px; flex: 0 0 auto; margin: 1px 0 0; accent-color: #c91f2a; }
.count-confirmation span { display: grid; gap: 3px; }
.count-confirmation strong { color: #303744; font-size: 12px; }
.count-confirmation small { color: #68727f; font-size: 11px; }
.handover-form-heading + .form-grid { margin-top: 0; }
.scan-feedback { display: block; margin-top: 7px; font-size: 12px; font-weight: 650; }
.feedback-success { color: #237451; }
.feedback-error { color: #b4232a; }
.scanned-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 7px; }
.scanned-heading label { color: #303744; font-size: 12px; font-weight: 700; }
.scanned-heading small { color: #68727f; font-size: 11px; }
.scan-empty { display: flex; min-height: 54px; align-items: center; gap: 9px; border: 1px dashed #d9dde2; border-radius: 8px; background: #fafbfc; padding: 10px; color: #747e8b; font-size: 12px; }
.scan-empty svg { width: 17px; height: 17px; color: #969eaa; }
.scan-input { position: relative; }
.scan-input > svg { position: absolute; z-index: 1; top: 13px; left: 12px; width: 17px; height: 17px; color: #606874; }
.scan-input .field { padding-left: 38px; }
.scan-add { min-height: 42px; }
.scan-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 6px; }
.scanned-code { display: flex; min-width: 0; align-items: center; gap: 8px; border: 1px solid #cce8dc; border-radius: 7px; background: #f3fbf7; padding: 7px 9px; color: #303744; font-size: 12px; }
.scanned-code > svg { width: 14px; height: 14px; flex: 0 0 auto; color: #237451; }
.scanned-code span { overflow: hidden; flex: 1; font-weight: 650; text-overflow: ellipsis; }
.scanned-code button { display: grid; width: 27px; height: 27px; flex: 0 0 auto; place-items: center; border: 0; border-radius: 6px; background: transparent; color: #727b87; cursor: pointer; }
.scanned-code button:hover { background: #ffe9e9; color: #b4232a; }
.scanned-code button svg { width: 14px; height: 14px; }
.offline-note { margin-top: 12px; border-radius: 7px; background: #fff7e7; padding: 9px 11px; color: #8e651a; font-size: 12px; }
.handover-submit:disabled { cursor: not-allowed; opacity: .58; }
@media (max-width: 1000px) { .handover-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 13px; }.summary-divider { display: none; }.summary-quantity { justify-self: start; } }
@media (max-width: 620px) { .handover-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 12px; }.summary-quantity { justify-self: stretch; }.assets-heading { align-items: flex-start; }.asset-list { grid-template-columns: 1fr; }.handover-form-heading { align-items: flex-start; }.scan-counter { font-size: 10px; }.form-actions { flex-direction: column-reverse; }.form-actions > * { width: 100%; justify-content: center; } }
</style>
