<script setup lang="ts">
import { reactive } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowRightLeft, CircleHelp, PackageCheck, Truck } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';
import BarcodeScanner from '../../Components/BarcodeScanner.vue';
import { useOnlineStatus } from '../../composables/useOnlineStatus';

type Location = { id: number; name: string; type: string };
type Transfer = { id: number; reference: string; source: string; destination: string; sourceLocationId: number; destinationLocationId: number; status: string; createdAt: string; assetCount: number; assets: { id: number; code: string | null; received: boolean }[] };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
const props = defineProps<{ transfers: Page<Transfer>; locations: Location[]; sourceLocationIds: number[] | null; canApprove: boolean }>();
const online = useOnlineStatus();
const requestForm = useForm({ source_location_id: '', destination_location_id: '', asset_codes: [] as string[], asset_codes_text: '', notes: '', idempotency_key: crypto.randomUUID() });
const actionCodes = reactive<Record<number, string>>({});
const actionErrors = reactive<Record<number, string>>({});

function parsedCodes(text: string): string[] {
    return [...new Set(text.split(/[\n,\s]+/).map((code) => code.trim()).filter(Boolean))];
}

function addRequestScan(code: string): void {
    requestForm.asset_codes_text = `${requestForm.asset_codes_text}${requestForm.asset_codes_text ? '\n' : ''}${code}`;
}

function addTransferScan(transferId: number, code: string): void {
    actionCodes[transferId] = `${actionCodes[transferId] ?? ''}${actionCodes[transferId] ? ' ' : ''}${code}`;
}

function requestTransfer(): void {
    requestForm.transform((data) => ({ ...data, asset_codes: parsedCodes(data.asset_codes_text) }));
    requestForm.post('/transfers', { preserveScroll: true, onSuccess: () => { requestForm.reset('asset_codes_text', 'notes'); requestForm.idempotency_key = crypto.randomUUID(); } });
}

function sendAction(transfer: Transfer, action: 'dispatch' | 'receive'): void {
    const asset_codes = parsedCodes(actionCodes[transfer.id] ?? '');
    if (!asset_codes.length) {
        actionErrors[transfer.id] = 'Scan or enter at least one asset code.';
        return;
    }

    actionErrors[transfer.id] = '';
    const url = `/transfers/${transfer.id}/${action}`;
    router.post(url, { asset_codes }, { preserveScroll: true, onSuccess: () => { actionCodes[transfer.id] = ''; } });
}
</script>

<template>
    <Head title="Locations & transfers" />
    <AppShell active="transfers">
        <div class="page-heading"><div><h1>Locations & transfers</h1><p>Move serialized gowns between warehouse and branches with scan-confirmed custody.</p></div></div>
        <div class="workflow-note"><CircleHelp :size="16" aria-hidden="true" /> Requested gowns remain at the source until an authorized manager dispatches them. In-transit assets become available at the destination only after receipt scans.</div>
        <section class="panel transfer-request">
            <div class="section-intro"><span class="report-icon"><ArrowRightLeft aria-hidden="true" /></span><div><h2 class="panel-title">Request a transfer</h2><p class="panel-subtitle">Scan or paste one asset barcode per line. Up to 200 gowns per request.</p></div></div>
            <form class="form-grid" @submit.prevent="requestTransfer">
                <div class="form-field"><label for="source">From location</label><select id="source" v-model="requestForm.source_location_id" class="select-field" required><option value="" disabled>Select source</option><option v-for="location in locations.filter((item) => sourceLocationIds === null || sourceLocationIds.includes(item.id))" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="requestForm.errors.source_location_id" class="form-error">{{ requestForm.errors.source_location_id }}</small></div>
                <div class="form-field"><label for="destination">To location</label><select id="destination" v-model="requestForm.destination_location_id" class="select-field" required><option value="" disabled>Select destination</option><option v-for="location in locations" :key="location.id" :value="location.id" :disabled="String(location.id) === requestForm.source_location_id">{{ location.name }}</option></select><small v-if="requestForm.errors.destination_location_id" class="form-error">{{ requestForm.errors.destination_location_id }}</small></div>
                <div class="form-field full"><label for="asset-codes">Gown asset barcodes</label><textarea id="asset-codes" v-model="requestForm.asset_codes_text" class="textarea-field" rows="3" placeholder="GS-GOWN-001&#10;GS-GOWN-002" required /><BarcodeScanner @detected="addRequestScan" /><small>Use a camera, hardware scanner or separate each asset code with a line break, comma or space.</small><small v-if="requestForm.errors.asset_codes" class="form-error">{{ requestForm.errors.asset_codes }}</small></div>
                <div class="form-field full"><label for="transfer-notes">Transfer notes <span class="muted">(optional)</span></label><input id="transfer-notes" v-model="requestForm.notes" class="field" maxlength="2000" placeholder="Reason, delivery window or handover details"></div>
                <div class="form-field full form-actions"><button class="primary-button" type="submit" :disabled="requestForm.processing || !online">{{ !online ? 'Reconnect to request a transfer' : requestForm.processing ? 'Creating request…' : 'Create transfer request' }}</button></div>
            </form>
        </section>

        <section class="panel transfer-list-panel">
            <div class="panel-title-row"><div><h2 class="panel-title">Transfer history</h2><p class="panel-subtitle">Drafts, dispatched gowns and confirmed receipts.</p></div><span class="transfer-count">{{ transfers.total.toLocaleString() }} records</span></div>
            <div v-if="transfers.data.length" class="transfer-cards">
                <article v-for="transfer in transfers.data" :key="transfer.id" class="transfer-card">
                    <header><strong>{{ transfer.reference }}</strong><span class="transfer-status" :class="transfer.status">{{ transfer.status.replaceAll('_', ' ') }}</span></header>
                    <div class="transfer-route"><span>{{ transfer.source }}</span><ArrowRightLeft aria-hidden="true" /><span>{{ transfer.destination }}</span></div>
                    <div class="transfer-summary"><span>{{ transfer.assetCount }} scanned assets</span><span>{{ transfer.createdAt }}</span></div>
                    <div v-if="transfer.assets.length" class="transfer-asset-list">{{ transfer.assets.map((asset) => `${asset.code ?? asset.id}${asset.received ? ' · received' : ''}`).join(' / ') }}</div>
                    <div v-if="(transfer.status === 'draft' || transfer.status === 'approved') && canApprove" class="transfer-action">
                        <label :for="`dispatch-${transfer.id}`">Scan items to dispatch</label><input :id="`dispatch-${transfer.id}`" v-model="actionCodes[transfer.id]" class="field" type="text" placeholder="Asset barcodes, separated by spaces"><BarcodeScanner @detected="addTransferScan(transfer.id, $event)" />
                        <button type="button" :disabled="!online" @click="sendAction(transfer, 'dispatch')"><Truck aria-hidden="true" /> Approve & dispatch</button>
                    </div>
                    <div v-else-if="['in_transit', 'partially_received'].includes(transfer.status)" class="transfer-action">
                        <label :for="`receive-${transfer.id}`">Scan items received at destination</label><input :id="`receive-${transfer.id}`" v-model="actionCodes[transfer.id]" class="field" type="text" placeholder="Scan the received gowns"><BarcodeScanner @detected="addTransferScan(transfer.id, $event)" />
                        <button type="button" :disabled="!online" @click="sendAction(transfer, 'receive')"><PackageCheck aria-hidden="true" /> Confirm receipt</button>
                    </div>
                    <small v-if="actionErrors[transfer.id]" class="form-error">{{ actionErrors[transfer.id] }}</small>
                    <small v-if="transfer.status === 'draft' && !canApprove" class="muted">Waiting for an authorized manager to approve dispatch.</small>
                </article>
            </div>
            <div v-else class="empty-state"><div><ArrowRightLeft aria-hidden="true" /><strong>No transfers recorded</strong><span>Request the first warehouse or branch transfer above.</span></div></div>
            <Pagination v-if="transfers.total > 0" :links="transfers.links" :from="transfers.from" :to="transfers.to" :total="transfers.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.transfer-request, .transfer-list-panel { margin-bottom: 12px; padding: 17px; }
.section-intro { display: flex; align-items: center; gap: 11px; margin-bottom: 16px; }
.section-intro p { margin-bottom: 0; }
.section-intro .report-icon { background: #fff2df; color: #ad791f; }
.transfer-request .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.transfer-count { color: #606874; font-size: 10px; }
.transfer-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 13px; }
.transfer-card { border: 1px solid #e9ebed; border-radius: 9px; padding: 13px; }
.transfer-card header, .transfer-route, .transfer-summary { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.transfer-card header > strong { color: #242a36; font-size: 12px; }
.transfer-route { justify-content: flex-start; margin-top: 14px; color: #333a47; font-size: 11px; font-weight: 650; }
.transfer-route svg { width: 15px; color: #a0a5ae; }
.transfer-summary { margin-top: 9px; color: #606874; font-size: 9px; }
.transfer-asset-list { margin-top: 9px; border-radius: 6px; background: #f7f8f9; padding: 8px; color: #676f7c; font-size: 9px; line-height: 1.6; overflow-wrap: anywhere; }
.transfer-action { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 7px; margin-top: 12px; }
.transfer-action label { grid-column: 1 / -1; color: #525a68; font-size: 10px; font-weight: 650; }
.transfer-action .field { min-height: 35px; padding: 7px 8px; font-size: 10px; }
.transfer-action :deep(.camera-scan-button) { width: 100%; min-height: 35px; justify-content: center; }
.transfer-action button { display: inline-flex; min-height: 35px; align-items: center; gap: 6px; border: 0; border-radius: 7px; background: #202633; padding: 0 10px; color: #fff; cursor: pointer; font-size: 10px; }
.transfer-action button:disabled { cursor: not-allowed; opacity: .55; }
.transfer-action button svg { width: 15px; }
@media(max-width:700px) { .transfer-request .form-grid, .transfer-cards { grid-template-columns: 1fr; } .transfer-action { grid-template-columns: 1fr; } .transfer-action label { grid-column: auto; } .transfer-action button { justify-content: center; } }
</style>
