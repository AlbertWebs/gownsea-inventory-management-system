<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Boxes, ClipboardCheck, Plus, Search, ShoppingCart, ScanLine } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type Asset = { id: number; code: string; product: string; sku: string; category: string; classification: string | null; qualification: string | null; size: string | null; color: string | null; imageUrl: string | null; status: string; condition: string; location: string; verifiedAt: string | null; missingReporterId: number | null };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
type PendingCount = { id: number; reference: string; location: string; startedBy: string; startedById: number; expected: number; counted: number; missing: number; unexpectedCodes: string[]; createdAt: string | null };
type PendingBulkCount = { id: number; reference: string; location: string; startedBy: string; startedById: number; expected: number; counted: number; lines: { product: string; sku: string; expected: number; counted: number }[]; createdAt: string | null };
type BulkBalance = { id: number; productId: number; sku: string; product: string; category: string; imageUrl: string | null; locationId: number; location: string; onHand: number; inventoryValueMinor: number; valuationComplete: boolean; averageCostMinor: number; reserved: number; pendingInspection: number; damaged: number; available: number; reorderThreshold: number };
type BulkProduct = { id: number; sku: string; name: string };
type BulkMovement = { id: number; product: string; sku: string; type: string; quantity: number; source: string | null; destination: string | null; staff: string | null; reason: string | null; occurredAt: string | null };

const props = defineProps<{ assets: Page<Asset>; bulkStock: BulkBalance[]; bulkProducts: BulkProduct[]; bulkMovements: BulkMovement[]; filters: { q: string; status: string; location: number | null }; locations: { id: number; name: string }[]; canApproveWriteOff: boolean; canApproveCycleCount: boolean; canAdjustBulk: boolean; canInspectBulk: boolean; canSellBulk: boolean; actorId: number; pendingCounts: PendingCount[]; pendingBulkCounts: PendingBulkCount[] }>();
const query = ref(props.filters.q);
const status = ref(props.filters.status);
const location = ref(props.filters.location ? String(props.filters.location) : '');
const inspectionResult = reactive<Record<number, string>>({});
const scannedCodes = ref('');
const countedBulkQuantities = reactive<Record<number, string>>({});
const selectedAssets = ref<number[]>([]);
const labelUrl = computed(() => `/inventory/labels?${selectedAssets.value.map((id) => `assets[]=${encodeURIComponent(id)}`).join('&')}`);
const countForm = useForm({ location_id: props.filters.location ? String(props.filters.location) : '', asset_codes: [] as string[], notes: '', idempotency_key: '' });
const bulkCountForm = useForm({ location_id: props.filters.location ? String(props.filters.location) : String(props.locations[0]?.id ?? ''), counts: [] as { product_id: number; counted_on_hand: number }[], notes: '', idempotency_key: '' });
const bulkForm = useForm({ product_id: '', movement: 'receive', direction: 'increase', quantity: '1', unit_cost: '0.00', valuation_total: '', location_id: props.filters.location ? String(props.filters.location) : String(props.locations[0]?.id ?? ''), source_location_id: props.filters.location ? String(props.filters.location) : String(props.locations[0]?.id ?? ''), destination_location_id: '', reason: '', idempotency_key: '' });
const bulkInspectionForm = useForm({ product_id: '', location_id: props.filters.location ? String(props.filters.location) : String(props.locations[0]?.id ?? ''), quantity: '1', source: 'pending_inspection', result: 'available', reason: '', idempotency_key: '' });
let timer: ReturnType<typeof setTimeout> | undefined;

watch([query, status, location], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => router.get('/inventory', { q: query.value || undefined, status: status.value || undefined, location: location.value || undefined }, { preserveState: true, preserveScroll: true, replace: true }), 240);
});

function inspect(asset: Asset): void {
    const result = inspectionResult[asset.id] ?? 'approved';
    if (result === 'approved' && !window.confirm(`Release ${asset.code} to available stock after inspection?`)) return;
    router.post(`/inventory/${asset.id}/inspect`, { result, idempotency_key: crypto.randomUUID(), reason: result === 'approved' ? 'Inspection passed.' : `Inspection outcome: ${result}.` }, { preserveScroll: true });
}

function printLabels(): void {
    window.print();
}

function writeOff(asset: Asset): void {
    const reason = window.prompt(`Manager write-off reason for ${asset.code}:`);
    if (!reason || reason.trim().length < 5) return;
    router.post(`/inventory/${asset.id}/write-off`, { reason: reason.trim(), idempotency_key: crypto.randomUUID() }, { preserveScroll: true });
}

function startCycleCount(): void {
    const codes = scannedCodes.value.split(/[\n,;\t]+/).map((code) => code.trim()).filter(Boolean);
    if (!codes.length || !countForm.location_id) return;
    countForm.location_id = String(countForm.location_id);
    countForm.asset_codes = codes;
    countForm.idempotency_key = crypto.randomUUID();
    countForm.post('/inventory/cycle-counts', { preserveScroll: true, onSuccess: () => { scannedCodes.value = ''; countForm.reset('asset_codes', 'notes'); } });
}

function approveCount(count: PendingCount): void {
    if (count.startedById === props.actorId) return;
    const detail = `${count.missing} unscanned asset(s) will be marked missing${count.unexpectedCodes.length ? `; unexpected codes require review: ${count.unexpectedCodes.join(', ')}` : ''}`;
    const reason = window.prompt(`Approve ${count.reference}? ${detail}. Enter an approval reason:`);
    if (!reason || reason.trim().length < 5) return;
    router.post(`/inventory/cycle-counts/${count.id}/approve`, { reason: reason.trim(), idempotency_key: crypto.randomUUID() }, { preserveScroll: true });
}

function cancelCount(count: PendingCount): void {
    const reason = window.prompt(`Cancel ${count.reference}? Enter a reason:`);
    if (!reason || reason.trim().length < 5) return;
    router.post(`/inventory/cycle-counts/${count.id}/cancel`, { reason: reason.trim(), idempotency_key: crypto.randomUUID() }, { preserveScroll: true });
}

function startBulkCount(): void {
    if (!bulkCountForm.location_id || !props.bulkProducts.length) return;
    bulkCountForm.counts = props.bulkProducts.map((product) => ({ product_id: product.id, counted_on_hand: Number(countedBulkQuantities[product.id] ?? 0) }));
    bulkCountForm.idempotency_key = crypto.randomUUID();
    bulkCountForm.post('/inventory/bulk-cycle-counts', { preserveScroll: true, onSuccess: () => { bulkCountForm.reset('counts', 'notes'); bulkCountForm.idempotency_key = ''; } });
}

function approveBulkCount(count: PendingBulkCount): void {
    if (count.startedById === props.actorId) return;
    const differences = count.lines.filter((line) => line.expected !== line.counted).map((line) => `${line.sku}: ${line.expected} → ${line.counted}`).join(', ') || 'no quantity differences';
    const reason = window.prompt(`Approve ${count.reference}? Count differences: ${differences}. Enter an approval reason:`);
    if (!reason || reason.trim().length < 5) return;
    router.post(`/inventory/bulk-cycle-counts/${count.id}/approve`, { reason: reason.trim(), idempotency_key: crypto.randomUUID() }, { preserveScroll: true });
}

function cancelBulkCount(count: PendingBulkCount): void {
    const reason = window.prompt(`Cancel ${count.reference}? Enter a reason so the count can be restarted:`);
    if (!reason || reason.trim().length < 5) return;
    router.post(`/inventory/bulk-cycle-counts/${count.id}/cancel`, { reason: reason.trim(), idempotency_key: crypto.randomUUID() }, { preserveScroll: true });
}

function recordBulkMovement(): void {
    bulkForm.idempotency_key = crypto.randomUUID();
    bulkForm.post('/inventory/bulk-stock', { preserveScroll: true, onSuccess: () => { bulkForm.reset('quantity', 'reason', 'valuation_total'); bulkForm.quantity = '1'; bulkForm.idempotency_key = ''; } });
}

function inspectBulk(): void {
    bulkInspectionForm.idempotency_key = crypto.randomUUID();
    bulkInspectionForm.post('/inventory/bulk-stock/inspect', { preserveScroll: true, onSuccess: () => { bulkInspectionForm.reset('quantity', 'reason'); bulkInspectionForm.quantity = '1'; } });
}
</script>

<template>
    <Head title="Inventory" />
    <AppShell active="inventory">
        <div class="page-heading">
            <div><h1>Inventory</h1><p>Serialized gown and accessory records with their last confirmed location.</p></div>
            <div class="heading-actions"><Link v-if="canSellBulk && bulkProducts.length" href="/bulk-sales/create" class="secondary-link"><ShoppingCart aria-hidden="true" /> Sell bulk stock</Link><Link href="/inventory/create" class="primary-link"><Plus aria-hidden="true" /> Register stock</Link><Link href="/returns/scan" class="secondary-link"><ScanLine aria-hidden="true" /> Scan a return</Link></div>
        </div>
        <div class="toolbar">
            <label class="global-search inventory-search"><Search aria-hidden="true" /><input v-model="query" type="search" placeholder="Search barcode, SKU, product or gown…" aria-label="Search inventory"></label>
            <select v-model="status" class="filter-select" aria-label="Filter by asset status">
                <option value="">All statuses</option>
                <option v-for="value in ['available', 'reserved', 'hired', 'in_transit', 'returned_pending_inspection', 'cleaning', 'under_repair', 'damaged', 'missing', 'written_off']" :key="value" :value="value">{{ value.replaceAll('_', ' ') }}</option>
            </select>
            <select v-model="location" class="filter-select" aria-label="Filter by location">
                <option value="">All assigned locations</option><option v-for="item in locations" :key="item.id" :value="item.id">{{ item.name }}</option>
            </select>
        </div>
        <section class="panel bulk-panel">
            <div class="count-heading"><div><h2><Boxes aria-hidden="true" /> Bulk stock</h2><p>Inventory value uses moving weighted average by product and location. Rental issue and return keep the stock capitalized; a sale recognizes cost of goods sold.</p></div></div>
            <div v-if="bulkStock.length" class="table-wrap bulk-table"><table class="data-table"><thead><tr><th>Product</th><th>Location</th><th>On hand</th><th>Inventory value</th><th>Average unit cost</th><th>Valuation</th><th>Reserved</th><th>Inspection</th><th>Damaged</th><th>Available</th><th>Reorder level</th></tr></thead><tbody><tr v-for="balance in bulkStock" :key="balance.id"><td><span class="product-cell"><img v-if="balance.imageUrl" :src="balance.imageUrl" :alt="`${balance.product} product photo`" class="product-thumbnail"><span><strong>{{ balance.product }}</strong><small class="table-subline">{{ balance.sku }} · {{ balance.category }}</small></span></span></td><td>{{ balance.location }}</td><td>{{ balance.onHand }}</td><td>KES {{ (balance.inventoryValueMinor / 100).toLocaleString('en-KE', { minimumFractionDigits: 2 }) }}</td><td>KES {{ (balance.averageCostMinor / 100).toLocaleString('en-KE', { minimumFractionDigits: 2 }) }}</td><td><strong :class="balance.valuationComplete ? '' : 'count-alert'">{{ balance.valuationComplete ? 'Confirmed' : 'Needs valuation' }}</strong></td><td>{{ balance.reserved }}</td><td>{{ balance.pendingInspection }}</td><td>{{ balance.damaged }}</td><td><strong>{{ balance.available }}</strong></td><td><span :class="balance.available <= balance.reorderThreshold ? 'count-alert' : ''">{{ balance.reorderThreshold }}<small v-if="balance.available <= balance.reorderThreshold" class="table-subline">Reorder needed</small></span></td></tr></tbody></table></div>
            <div v-if="bulkMovements.length" class="bulk-history"><h3>Recent bulk stock ledger</h3><div v-for="movement in bulkMovements" :key="movement.id" class="bulk-history-row"><strong>{{ movement.product }} <small>{{ movement.sku }}</small></strong><span>{{ movement.type.replaceAll('_', ' ') }} · {{ movement.quantity }}</span><span>{{ movement.source ?? 'Opening' }} → {{ movement.destination ?? 'removed' }}</span><small>{{ movement.reason }} · {{ movement.staff ?? 'system' }} · {{ movement.occurredAt ?? '' }}</small></div></div>
            <form v-if="bulkProducts.length" class="bulk-movement-form" @submit.prevent="recordBulkMovement">
                <label>Product<select v-model="bulkForm.product_id" class="filter-select" required><option value="" disabled>Select bulk product</option><option v-for="product in bulkProducts" :key="product.id" :value="String(product.id)">{{ product.sku }} · {{ product.name }}</option></select></label>
                <label>Movement<select v-model="bulkForm.movement" class="filter-select"><option value="receive">Receive stock</option><option v-if="canAdjustBulk" value="adjust">Adjust count</option><option value="transfer">Transfer between locations</option><option v-if="canAdjustBulk" value="revalue">Set opening valuation</option></select></label>
                <label v-if="['receive', 'adjust', 'revalue'].includes(bulkForm.movement)">Location<select v-model="bulkForm.location_id" class="filter-select" required><option v-for="item in locations" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
                <template v-else><label>From<select v-model="bulkForm.source_location_id" class="filter-select" required><option v-for="item in locations" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label><label>To<select v-model="bulkForm.destination_location_id" class="filter-select" required><option value="" disabled>Select destination</option><option v-for="item in locations" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label></template>
                <label v-if="bulkForm.movement === 'adjust'">Adjustment<select v-model="bulkForm.direction" class="filter-select"><option value="increase">Increase</option><option value="decrease">Decrease</option></select></label>
                <label v-if="bulkForm.movement === 'receive' || (bulkForm.movement === 'adjust' && bulkForm.direction === 'increase')">Unit cost (KES)<input v-model="bulkForm.unit_cost" class="filter-select" type="number" min="0" step="0.01" required><small v-if="bulkForm.errors.unit_cost" class="form-error">{{ bulkForm.errors.unit_cost }}</small></label>
                <label v-if="bulkForm.movement === 'revalue'">Total stock value (KES)<input v-model="bulkForm.valuation_total" class="filter-select" type="number" min="0" step="0.01" required><small v-if="bulkForm.errors.valuation_total" class="form-error">{{ bulkForm.errors.valuation_total }}</small></label>
                <label v-if="bulkForm.movement !== 'revalue'">Quantity<input v-model="bulkForm.quantity" class="filter-select" type="number" min="1" max="1000000" step="1" required></label>
                <label class="bulk-reason">Reason<input v-model="bulkForm.reason" class="filter-select" minlength="5" maxlength="1000" placeholder="Supplier receipt, recount correction, transfer note…" required></label>
                <button class="primary-link" type="submit" :disabled="bulkForm.processing">{{ bulkForm.processing ? 'Saving…' : 'Record movement' }}</button>
                <small v-if="bulkForm.errors.quantity || bulkForm.errors.reason || bulkForm.errors.product_id" class="form-error">{{ bulkForm.errors.quantity || bulkForm.errors.reason || bulkForm.errors.product_id }}</small>
            </form>
            <form v-if="bulkProducts.length && canInspectBulk" class="bulk-movement-form inspection-form" @submit.prevent="inspectBulk">
                <strong class="bulk-form-title">Inspect returned quantities</strong>
                <label>Product<select v-model="bulkInspectionForm.product_id" class="filter-select" required><option value="" disabled>Select bulk product</option><option v-for="product in bulkProducts" :key="product.id" :value="String(product.id)">{{ product.sku }} · {{ product.name }}</option></select></label>
                <label>Location<select v-model="bulkInspectionForm.location_id" class="filter-select" required><option v-for="item in locations" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
                <label>Stock condition<select v-model="bulkInspectionForm.source" class="filter-select"><option value="pending_inspection">Returned; awaiting inspection ({{ bulkStock.filter((row) => row.productId === Number(bulkInspectionForm.product_id) && row.locationId === Number(bulkInspectionForm.location_id)).reduce((total, row) => total + row.pendingInspection, 0) }})</option><option value="damaged">Damaged, repaired and re-inspected</option></select></label>
                <label>Decision<select v-model="bulkInspectionForm.result" class="filter-select"><option value="available">Pass; release to available</option><option value="damaged" :disabled="bulkInspectionForm.source === 'damaged'">Fail; mark damaged</option></select></label>
                <label>Quantity<input v-model="bulkInspectionForm.quantity" class="filter-select" type="number" min="1" max="1000000" step="1" required></label>
                <label class="bulk-reason">Inspection / repair notes<input v-model="bulkInspectionForm.reason" class="filter-select" minlength="5" maxlength="1000" required></label>
                <button class="primary-link" type="submit" :disabled="bulkInspectionForm.processing || !bulkInspectionForm.product_id">{{ bulkInspectionForm.processing ? 'Saving…' : 'Record inspection' }}</button>
                <small v-if="bulkInspectionForm.errors.quantity || bulkInspectionForm.errors.reason || bulkInspectionForm.errors.source || bulkInspectionForm.errors.result" class="form-error">{{ bulkInspectionForm.errors.quantity || bulkInspectionForm.errors.reason || bulkInspectionForm.errors.source || bulkInspectionForm.errors.result }}</small>
            </form>
            <div v-else class="bulk-empty"><span>No bulk tracked products are registered.</span><Link href="/inventory/create" class="secondary-link">Register bulk product</Link></div>
        </section>
        <section class="panel count-panel">
            <div class="count-heading"><div><h2><ClipboardCheck aria-hidden="true" /> Cycle count</h2><p>Scan every serialized asset physically present at one location. A different manager reviews discrepancies before stock changes.</p></div></div>
            <form class="count-form" @submit.prevent="startCycleCount">
                <label>Location<select v-model="countForm.location_id" class="filter-select" required><option value="" disabled>Select location</option><option v-for="item in locations" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
                <label class="scan-input">Scanned barcodes<textarea v-model="scannedCodes" rows="3" required placeholder="Scan or paste one asset barcode per line"></textarea><small>Only assets assigned to the selected location can be submitted.</small></label>
                <label>Count notes <textarea v-model="countForm.notes" rows="3" maxlength="1000" placeholder="Optional notes for the approving manager"></textarea></label>
                <div class="count-submit"><span v-if="countForm.errors.asset_codes" class="form-error">{{ countForm.errors.asset_codes }}</span><button class="primary-link" type="submit" :disabled="countForm.processing || !locations.length">Submit count for approval</button></div>
            </form>
            <div v-if="pendingCounts.length" class="pending-counts">
                <h3>Awaiting independent approval</h3>
                <div v-for="count in pendingCounts" :key="count.id" class="pending-count-row">
                    <div><strong>{{ count.reference }}</strong><span>{{ count.location }} · started by {{ count.startedBy }} · {{ count.createdAt ?? 'Recently' }}</span></div>
                    <div class="count-metrics"><span>{{ count.counted }} / {{ count.expected }} scanned</span><strong :class="count.missing ? 'count-alert' : 'count-clear'">{{ count.missing }} unscanned</strong></div>
                    <span v-if="count.unexpectedCodes.length" class="count-alert">{{ count.unexpectedCodes.length }} unexpected barcode(s): {{ count.unexpectedCodes.join(', ') }}</span>
                    <div class="count-actions"><button v-if="canApproveCycleCount && count.startedById !== actorId" class="write-off-button" type="button" @click="approveCount(count)">Review &amp; approve</button><button v-if="count.startedById === actorId || canApproveCycleCount" class="count-cancel" type="button" @click="cancelCount(count)">Cancel</button><span v-if="!canApproveCycleCount && count.startedById !== actorId" class="awaiting-label">Manager approval required</span><span v-else-if="count.startedById === actorId && !canApproveCycleCount" class="awaiting-label">Awaiting another manager</span></div>
                </div>
            </div>
            <div v-if="bulkProducts.length" class="bulk-count-form-wrap">
                <h3>Bulk quantity cycle count</h3>
                <p>Enter the physically counted on-hand units for every bulk product. A second manager reviews differences before stock changes.</p>
                <form class="count-form" @submit.prevent="startBulkCount">
                    <label>Location<select v-model="bulkCountForm.location_id" class="filter-select" required><option value="" disabled>Select location</option><option v-for="item in locations" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
                    <div class="bulk-count-lines"><label v-for="product in bulkProducts" :key="product.id">{{ product.sku }} · {{ product.name }}<input v-model="countedBulkQuantities[product.id]" class="filter-select" type="number" min="0" max="1000000" step="1" required :aria-label="`Counted on-hand for ${product.name}`" /></label></div>
                    <label>Count notes<textarea v-model="bulkCountForm.notes" class="filter-select" maxlength="1000" placeholder="Optional count notes"></textarea></label>
                    <div class="count-submit"><span v-if="bulkCountForm.errors.counts" class="form-error">{{ bulkCountForm.errors.counts }}</span><button class="primary-link" type="submit" :disabled="bulkCountForm.processing || !locations.length">Submit bulk count for approval</button></div>
                </form>
            </div>
            <div v-if="pendingBulkCounts.length" class="pending-counts">
                <h3>Bulk counts awaiting independent approval</h3>
                <div v-for="count in pendingBulkCounts" :key="count.id" class="pending-count-row">
                    <div><strong>{{ count.reference }}</strong><span>{{ count.location }} · started by {{ count.startedBy }} · {{ count.createdAt ?? 'Recently' }}</span></div>
                    <div class="count-metrics"><span>{{ count.counted }} units counted · {{ count.expected }} expected</span><strong :class="count.counted === count.expected ? 'count-clear' : 'count-alert'">{{ count.lines.filter((line) => line.expected !== line.counted).length }} product difference(s)</strong></div>
                    <small>{{ count.lines.filter((line) => line.expected !== line.counted).map((line) => `${line.sku}: ${line.expected} → ${line.counted}`).join(' · ') || 'No differences' }}</small>
                    <div class="count-actions"><button v-if="canApproveCycleCount && count.startedById !== actorId" class="write-off-button" type="button" @click="approveBulkCount(count)">Review &amp; approve</button><button v-if="count.startedById === actorId || canApproveCycleCount" class="count-cancel" type="button" @click="cancelBulkCount(count)">Cancel count</button><span v-if="!canApproveCycleCount && count.startedById !== actorId" class="awaiting-label">Manager approval required</span><span v-else-if="count.startedById === actorId && !canApproveCycleCount" class="awaiting-label">Awaiting another manager</span></div>
                </div>
            </div>
        </section>
        <section class="panel table-panel">
            <div v-if="assets.data.length" class="table-wrap">
                <table class="data-table">
                    <thead><tr><th><input type="checkbox" :checked="assets.data.length > 0 && assets.data.every((asset) => selectedAssets.includes(asset.id))" aria-label="Select all assets on this page for labels" @change="selectedAssets = ($event.target as HTMLInputElement).checked ? assets.data.map((asset) => asset.id) : []"></th><th>Asset / Barcode</th><th>Product</th><th>Classification</th><th>Size / Color</th><th>Location / Custody</th><th>Condition</th><th>Status</th><th>Last Verified</th><th>Action</th></tr></thead>
                    <tbody>
                        <tr v-for="asset in assets.data" :key="asset.id">
                            <td><input v-model="selectedAssets" type="checkbox" :value="asset.id" :aria-label="`Select ${asset.code} for a label`"></td>
                            <td><Link :href="`/inventory/${asset.id}`" class="asset-detail-link"><strong>{{ asset.code }}</strong></Link><small class="table-subline">{{ asset.sku }}</small></td>
                            <td><span class="product-cell"><img v-if="asset.imageUrl" :src="asset.imageUrl" :alt="`${asset.product} product photo`" class="product-thumbnail"><span>{{ asset.product }}<small class="table-subline">{{ asset.category }}</small></span></span></td>
                            <td>{{ asset.classification ?? '—' }}<small v-if="asset.qualification" class="table-subline">{{ asset.qualification }}</small></td>
                            <td>{{ asset.size ?? '—' }} <span v-if="asset.color">· {{ asset.color }}</span></td>
                            <td>{{ asset.location }}</td><td>{{ asset.condition.replaceAll('_', ' ') }}</td>
                            <td><span class="status-chip" :class="asset.status">{{ asset.status.replaceAll('_', ' ') }}</span></td><td>{{ asset.verifiedAt ?? 'Unverified' }}</td>
                            <td v-if="asset.status === 'returned_pending_inspection'" class="inspection-cell"><select v-model="inspectionResult[asset.id]" class="inspection-select" :aria-label="`Inspection result for ${asset.code}`"><option value="approved">Pass</option><option value="cleaning">Cleaning</option><option value="repair">Repair</option></select><button class="inspection-button" type="button" @click="inspect(asset)">Record</button></td><td v-else-if="canApproveWriteOff && asset.status === 'missing' && asset.missingReporterId !== actorId"><button class="write-off-button" type="button" @click="writeOff(asset)">Approve write-off</button></td><td v-else>—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="selectedAssets.length" class="label-actions"><span>{{ selectedAssets.length }} asset(s) selected</span><a :href="labelUrl" class="secondary-link">Print labels</a></div>
            <div v-else-if="!assets.data.length" class="empty-state"><div><Boxes aria-hidden="true" /><strong>No inventory records found</strong><span>Register a product and its physical gown assets to start tracking stock by location.</span><Link href="/inventory/create" class="primary-link empty-action">Register stock</Link></div></div>
            <Pagination v-if="assets.total > 0" :links="assets.links" :from="assets.from" :to="assets.to" :total="assets.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.product-cell { display:flex; align-items:center; gap:8px; }
.product-thumbnail { width:38px; height:38px; flex:0 0 38px; border:1px solid #e8eaed; border-radius:6px; background:#f5f6f7; object-fit:cover; }
.inventory-search { width: min(360px, 100%); }
.heading-actions { display: flex; align-items: center; gap: 8px; }
.table-subline { display: block; margin-top: 3px; color: #606874; font-size: 9px; }
.empty-action { margin-top: 14px; }
.inspection-cell { display: flex; align-items: center; gap: 5px; }
.inspection-select { min-height: 30px; border: 1px solid #e4e6e8; border-radius: 5px; background: #fff; padding: 0 5px; color: #596170; font-size: 9px; }
.inspection-button { min-height: 30px; border: 0; border-radius: 5px; background: #1f2734; padding: 0 8px; color: #fff; cursor: pointer; font-size: 9px; }
.write-off-button { min-height: 30px; border: 1px solid #c51f30; border-radius: 5px; background: #fff; padding: 0 8px; color: #a31928; cursor: pointer; font-size: 9px; }
.asset-detail-link { color: inherit; text-decoration: none; }
.asset-detail-link:hover { color: #bd1e2d; text-decoration: underline; }
.count-panel { margin-bottom: 12px; padding: 16px; }
.bulk-count-form-wrap { margin-top: 16px; border-top: 1px solid #eceef1; padding-top: 16px; }
.bulk-count-form-wrap > p { margin: 5px 0 12px; color: #6d7583; font-size: 12px; }
.bulk-count-lines { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
.bulk-count-lines label { display: grid; gap: 5px; color: #515a69; font-size: 11px; }
.bulk-panel { margin-bottom: 12px; padding: 16px; }
.bulk-table { margin-top: 12px; }
.bulk-history { margin-top: 15px; border-top: 1px solid #edf0f2; padding-top: 11px; }
.bulk-history h3 { margin: 0 0 6px; color: #303743; font-size: 11px; }
.bulk-history-row { display: grid; grid-template-columns: minmax(150px, 1fr) minmax(130px, 1fr) minmax(150px, 1fr) minmax(170px, 1.5fr); gap: 8px; align-items: center; border-top: 1px solid #f0f1f3; padding: 8px 0; color: #606874; font-size: 9px; }
.bulk-history-row strong { color: #303743; }
.bulk-history-row small { color: #606874; font-size: 8px; }
.bulk-movement-form { display: grid; grid-template-columns: repeat(4, minmax(130px, 1fr)); align-items: end; gap: 9px; margin-top: 13px; }
.bulk-movement-form label { display: grid; gap: 5px; color: #515967; font-size: 10px; font-weight: 600; }
.bulk-movement-form button { justify-self: start; min-height: 34px; }
.bulk-reason { grid-column: span 2; }
.bulk-empty { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; color: #606874; font-size: 11px; }
.count-heading h2 { display: flex; align-items: center; gap: 7px; margin: 0; font-size: 14px; }
.count-heading h2 :deep(svg) { width: 16px; height: 16px; color: #c51f30; }
.count-heading p { margin: 5px 0 0; color: #606874; font-size: 10px; }
.count-form { display: grid; grid-template-columns: minmax(150px, 1fr) minmax(220px, 1.5fr) minmax(170px, 1fr); align-items: start; gap: 12px; margin-top: 14px; }
.count-form label { display: grid; gap: 6px; color: #515967; font-size: 10px; font-weight: 600; }
.count-form textarea { width: 100%; min-height: 68px; border: 1px solid #e3e6ea; border-radius: 6px; padding: 8px; resize: vertical; color: #252a33; font: inherit; font-weight: 400; }
.count-form small { color: #606874; font-weight: 400; }
.count-submit { display: flex; grid-column: 1 / -1; align-items: center; justify-content: space-between; gap: 12px; }
.count-submit button { min-height: 34px; }
.count-submit button:disabled { opacity: .6; cursor: wait; }
.form-error { color: #bd1e2d; font-size: 10px; }
.pending-counts { margin-top: 15px; border-top: 1px solid #edf0f2; padding-top: 12px; }
.pending-counts h3 { margin: 0 0 8px; color: #303743; font-size: 11px; }
.pending-count-row { display: grid; grid-template-columns: minmax(190px, 1fr) auto auto; align-items: center; gap: 12px; border-top: 1px solid #f0f1f3; padding: 9px 0; font-size: 10px; }
.count-actions { display: flex; align-items: center; gap: 8px; }
.count-cancel { border: 0; padding: 6px; background: transparent; color: #a23b43; font-size: 10px; cursor: pointer; }
.pending-count-row > div:first-child { display: grid; gap: 3px; }
.pending-count-row > div:first-child span, .count-metrics { color: #626b78; }
.count-metrics { display: flex; gap: 10px; }
.count-alert { color: #bd1e2d; }
.count-clear { color: #128b53; }
.awaiting-label { color: #626b78; }
@media (max-width: 760px) { .count-form { grid-template-columns: 1fr; } .count-submit { grid-column: auto; align-items: flex-start; flex-direction: column; } .pending-count-row { grid-template-columns: 1fr; gap: 7px; } .count-metrics { justify-content: space-between; } .bulk-movement-form { grid-template-columns: 1fr 1fr; } .bulk-reason { grid-column: 1 / -1; } .bulk-history-row { grid-template-columns: 1fr 1fr; } }
</style>
