<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CircleHelp, ScanLine, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
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
const confirmedCount = ref(false);
const form = useForm({ asset_codes: [] as string[], bulk_handover_confirmed: false, idempotency_key: crypto.randomUUID() });
function addCode(value: string): void {
    const code = value.trim();
    if (!code) return;
    if (codes.value.includes(code)) {
        feedback.value = `${code} has already been scanned.`;
        input.value = '';
        return;
    }
    codes.value.push(code);
    input.value = '';
    feedback.value = `${codes.value.length} of ${props.hire.assets.length} reserved gowns scanned.`;
}
function removeCode(code: string): void { codes.value = codes.value.filter((item) => item !== code); }
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
        <div class="page-heading"><div><h1>Issue reservation</h1><p>{{ hire.reference }} · {{ hire.customer }} · {{ hire.location }}</p></div><Link href="/hiring" class="secondary-link"><ArrowLeft aria-hidden="true" /> Hiring records</Link></div>
        <div class="workflow-note"><CircleHelp :size="16" aria-hidden="true" /> <template v-if="hire.isBulk">Count and confirm every reserved bulk quantity at handover. Expected return: {{ hire.dueAt ?? 'Not specified' }}.</template><template v-else>Scan each reserved gown at handover. All {{ hire.assets.length }} units must be confirmed before custody changes. Expected return: {{ hire.dueAt ?? 'Not specified' }}.</template></div>
        <section v-if="hire.isBulk" class="panel reservation-assets"><h2>Reserved quantities</h2><div class="asset-list"><span v-for="item in hire.bulkItems" :key="item.product" class="asset-chip"><strong>{{ item.quantity }} units</strong><small>{{ item.product }}</small></span></div></section>
        <section v-else class="panel reservation-assets"><h2>Reserved asset list</h2><div class="asset-list"><span v-for="asset in hire.assets" :key="asset.code" class="asset-chip"><strong>{{ asset.code }}</strong><small>{{ asset.product }}</small></span></div></section>
        <form class="panel form-card" @submit.prevent="issue">
            <div class="form-grid"><div v-if="!hire.isBulk" class="form-field full"><label for="reserved-scan">Scan reserved asset barcode</label><div class="scan-input"><ScanLine aria-hidden="true" /><input id="reserved-scan" v-model="input" class="field" autocomplete="off" autofocus placeholder="Scan and press Enter" @keydown.enter.prevent="addCode(input)"><button type="button" class="secondary-link scan-add" @click="addCode(input)">Add</button></div><small>Scanned {{ codes.length }} of {{ hire.assets.length }} reserved units.</small><small v-if="feedback" role="status">{{ feedback }}</small><BarcodeScanner @detected="addCode" /><small v-if="errors.asset_codes || errors['asset_codes.0']" class="form-error">{{ errors.asset_codes || errors['asset_codes.0'] }}</small></div>
                <div v-if="!hire.isBulk" class="form-field full">
                    <label>Confirmed scans</label>
                    <div v-if="codes.length" class="scan-list"><div v-for="code in codes" :key="code" class="scanned-code"><span>{{ code }}</span><button type="button" :aria-label="`Remove ${code}`" @click="removeCode(code)"><Trash2 aria-hidden="true" /></button></div></div>
                    <small v-else>No reserved asset has been scanned yet.</small>
                </div>
            </div>
            <label v-if="hire.isBulk" class="count-confirmation"><input v-model="confirmedCount" type="checkbox"> I verified the counted quantities and am handing them to this customer.</label><small v-if="form.errors.bulk_handover_confirmed" class="form-error">{{ form.errors.bulk_handover_confirmed }}</small>
            <div class="form-actions"><Link href="/hiring" class="secondary-link">Cancel</Link><button class="primary-button" type="submit" :disabled="form.processing || !online || (hire.isBulk ? !confirmedCount : codes.length !== hire.assets.length)">{{ !online ? 'Reconnect to issue' : form.processing ? 'Confirming handover…' : `Issue ${hire.isBulk ? hire.bulkItems.reduce((total, item) => total + item.quantity, 0) : hire.assets.length} reserved gowns` }}</button></div>
        </form>
    </AppShell>
</template>

<style scoped>
.reservation-assets { margin-bottom: 13px; padding: 16px; }
.reservation-assets h2 { margin: 0 0 11px; font-size: 13px; }
.asset-list { display: flex; flex-wrap: wrap; gap: 6px; }
.count-confirmation { display: flex; align-items: center; gap: 8px; margin-top: 16px; color: #414957; font-size: 12px; }
.asset-chip { display: flex; flex-direction: column; gap: 3px; border: 1px solid #e5e7e9; border-radius: 7px; padding: 7px 9px; font-size: 10px; }
.asset-chip small { color: #7b828e; }
.scan-input { position: relative; }
.scan-input > svg { position: absolute; z-index: 1; top: 13px; left: 12px; width: 17px; height: 17px; color: #606874; }
.scan-input .field { padding-left: 38px; }
.scan-add { min-height: 42px; }
.scan-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 5px; }
.scanned-code { display: flex; align-items: center; justify-content: space-between; border: 1px solid #e4e6e8; border-radius: 6px; padding: 5px 8px; color: #303744; font-size: 10px; }
.scanned-code button { border: 0; background: transparent; color: #606874; cursor: pointer; }
.primary-button:disabled { cursor: wait; opacity: .7; }
</style>
