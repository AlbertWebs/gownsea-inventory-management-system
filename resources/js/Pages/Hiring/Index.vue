<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, Check, Search, ScanLine, Plus, X } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type Hire = { id: number; reference: string; status: string; due_at: string | null; created_at: string; customer: { name: string; phone: string | null }; location: { name: string }; items: { product_name_snapshot: string; quantity: number; returned_quantity: number; status: string }[] };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
const props = defineProps<{ hires: Page<Hire>; filters: { q: string; status: string }; canOperate: boolean }>();
const query = ref(props.filters.q);
const status = ref(props.filters.status);
let timer: ReturnType<typeof setTimeout> | undefined;
watch([query, status], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => router.get('/hiring', { q: query.value || undefined, status: status.value || undefined }, { preserveState: true, preserveScroll: true, replace: true }), 240);
});

function dateLabel(value: string | null): string {
    return value ? new Date(value).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Not issued';
}

function dueState(hire: Hire): { label: string; tone: string } {
    if (!hire.due_at) return { label: 'Not issued', tone: 'neutral' };
    if (!['issued', 'partially_returned'].includes(hire.status)) return { label: dateLabel(hire.due_at), tone: 'neutral' };

    const due = new Date(hire.due_at);
    due.setHours(0, 0, 0, 0);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((due.getTime() - today.getTime()) / 86_400_000);
    if (days < 0) return { label: `${Math.abs(days)} day${days === -1 ? '' : 's'} overdue`, tone: 'overdue' };
    if (days === 0) return { label: 'Due today', tone: 'today' };
    if (days <= 3) return { label: `Due in ${days} day${days === 1 ? '' : 's'}`, tone: 'soon' };
    return { label: dateLabel(hire.due_at), tone: 'neutral' };
}

function statusLabel(value: string): string {
    return value.replaceAll('_', ' ');
}

function clearFilters(): void {
    query.value = '';
    status.value = '';
}

function cancelReservation(hire: Hire): void {
    if (!window.confirm(`Cancel reservation ${hire.reference} and release its gowns?`)) return;
    router.post(`/hiring/${hire.id}/cancel`, { reason: 'Cancelled by authorized staff.' }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Hiring & returns" />
    <AppShell active="hiring">
        <div class="page-heading">
            <div><h1>Hiring & returns</h1><p>Track active gown custody, due dates and completed returns.</p></div>
            <div class="heading-actions"><Link v-if="canOperate" href="/hiring/reservations/create" class="secondary-link"><Plus aria-hidden="true" /> Reserve gowns</Link><Link v-if="canOperate" href="/returns/scan" class="secondary-link"><ScanLine aria-hidden="true" /> Scan return</Link><Link v-if="canOperate" href="/hiring/issue" class="primary-link"><Plus aria-hidden="true" /> Issue gowns</Link></div>
        </div>
        <div class="hiring-toolbar">
            <label class="global-search hiring-search"><Search aria-hidden="true" /><input v-model="query" type="search" placeholder="Search customer, phone, or hire reference" aria-label="Search hiring records"></label>
            <label class="status-filter"><span>Status</span><select v-model="status" class="filter-select" aria-label="Filter hiring status">
                <option value="">All statuses</option><option value="due_today">Due today</option><option value="overdue">Overdue</option><option value="issued">Issued</option><option value="partially_returned">Partially returned</option><option value="returned_pending_inspection">Returned for inspection</option><option value="completed">Completed</option><option value="reserved">Reserved</option><option value="cancelled">Cancelled</option>
            </select></label>
            <button v-if="query || status" class="clear-hiring-filters" type="button" @click="clearFilters"><X aria-hidden="true" /> Clear filters</button>
        </div>
        <section class="panel table-panel">
            <div class="hiring-results-bar"><strong>Hire records</strong><span v-if="hires.total">Showing {{ hires.from ?? 0 }}–{{ hires.to ?? 0 }} of {{ hires.total }}</span><span v-else>No records</span></div>
            <div v-if="hires.data.length" class="table-wrap">
                <table class="data-table hiring-table">
                    <thead><tr><th>Hire reference</th><th>Customer</th><th>Items &amp; returns</th><th>Pickup location</th><th>Due date</th><th>Status</th><th v-if="canOperate">Next step</th></tr></thead>
                    <tbody>
                        <tr v-for="hire in hires.data" :key="hire.id" :class="{ 'hire-row-overdue': dueState(hire).tone === 'overdue' }">
                            <td data-label="Hire reference"><strong class="hire-reference">{{ hire.reference }}</strong><small class="table-subline">{{ dateLabel(hire.created_at) }} · {{ hire.items.reduce((sum, item) => sum + item.quantity, 0) }} gown{{ hire.items.reduce((sum, item) => sum + item.quantity, 0) === 1 ? '' : 's' }}</small></td>
                            <td data-label="Customer"><strong class="hire-customer-name">{{ hire.customer.name }}</strong><small class="table-subline">{{ hire.customer.phone ?? 'No phone recorded' }}</small></td>
                            <td data-label="Items & returns"><div class="hire-item-list"><div v-for="(item, index) in hire.items" :key="`${item.product_name_snapshot}-${index}`" class="hire-item"><span class="hire-item-copy"><strong>{{ item.product_name_snapshot }}</strong><small>{{ item.returned_quantity }} of {{ item.quantity }} returned</small></span><span class="hire-item-progress" role="img" :aria-label="`${item.returned_quantity} of ${item.quantity} returned`"><span :style="{ width: `${item.quantity ? Math.min(100, item.returned_quantity / item.quantity * 100) : 0}%` }" /></span></div></div></td>
                            <td data-label="Pickup location"><span class="hire-location">{{ hire.location.name }}</span></td>
                            <td data-label="Due date"><div class="hire-due" :class="`due-${dueState(hire).tone}`"><strong>{{ dueState(hire).label }}</strong><small v-if="hire.due_at">{{ dateLabel(hire.due_at) }}</small></div></td>
                            <td data-label="Status"><span class="status-chip hiring-status" :class="hire.status">{{ statusLabel(hire.status) }}</span></td>
                            <td v-if="canOperate" data-label="Next step" class="hire-actions">
                                <div v-if="hire.status === 'reserved'" class="hire-next-step next-ready">
                                    <span class="next-step-label"><i aria-hidden="true" /> Ready for handover</span>
                                    <Link :href="`/hiring/${hire.id}/issue-reservation`" class="hire-next-action"><span>Handover gowns</span><ArrowRight aria-hidden="true" /></Link>
                                    <button class="cancel-reservation-action" type="button" :aria-label="`Cancel reservation ${hire.reference}`" @click="cancelReservation(hire)"><X aria-hidden="true" /><span>Cancel reservation</span></button>
                                </div>
                                <div v-else-if="['issued', 'partially_returned'].includes(hire.status)" class="hire-next-step next-return">
                                    <span class="next-step-label"><i aria-hidden="true" /> {{ hire.status === 'partially_returned' ? 'Items still out' : 'Awaiting return' }}</span>
                                    <Link href="/returns/scan" class="hire-next-action"><ScanLine aria-hidden="true" /><span>Record return</span><ArrowRight aria-hidden="true" /></Link>
                                    <small>{{ hire.items.reduce((sum, item) => sum + item.quantity - item.returned_quantity, 0) }} gown{{ hire.items.reduce((sum, item) => sum + item.quantity - item.returned_quantity, 0) === 1 ? '' : 's' }} outstanding</small>
                                </div>
                                <div v-else-if="hire.status === 'returned_pending_inspection'" class="hire-next-step next-inspection">
                                    <span class="next-step-label"><i aria-hidden="true" /> Returned for inspection</span>
                                    <small>Gowns are awaiting an inspection decision.</small>
                                </div>
                                <div v-else-if="hire.status === 'completed'" class="hire-next-step next-done"><span class="next-step-label"><Check aria-hidden="true" /> Hire complete</span><small>All items have been returned.</small></div>
                                <div v-else class="hire-next-step next-inactive"><span class="next-step-label"><i aria-hidden="true" /> Reservation cancelled</span><small>No further action required.</small></div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="empty-state"><div><CalendarClock aria-hidden="true" /><strong>No matching hiring records</strong><span>Issue a gown to a customer or clear the current search filters.</span><Link href="/hiring/issue" class="primary-link empty-action"><Plus aria-hidden="true" /> Issue a gown</Link></div></div>
            <Pagination v-if="hires.total > 0" :links="hires.links" :from="hires.from" :to="hires.to" :total="hires.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.heading-actions { display: flex; gap: 8px; }
.hiring-toolbar { display: flex; align-items: end; gap: 11px; margin: 0 0 14px; }
.hiring-search { width: min(360px, 100%); }
.hiring-search input { font-size: 13px; }
.status-filter { display: grid; gap: 5px; color: #555e6b; font-size: 12px; font-weight: 650; }
.status-filter .filter-select { min-width: 170px; min-height: 42px; font-size: 13px; }
.clear-hiring-filters { display: inline-flex; min-height: 38px; align-items: center; gap: 5px; border: 0; background: transparent; padding: 0 7px; color: #a91e26; cursor: pointer; font-size: 11px; font-weight: 650; }
.clear-hiring-filters svg { width: 14px; height: 14px; }
.hiring-results-bar { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; border-bottom: 1px solid #eceef0; padding: 0 18px 12px; }
.hiring-results-bar strong { color: #252c37; font-size: 14px; font-weight: 750; }
.hiring-results-bar span { color: #626b78; font-size: 12px; font-variant-numeric: tabular-nums; }
.hiring-table { width: 100%; min-width: 1180px; table-layout: fixed; white-space: normal; }
.hiring-table th { white-space: nowrap; font-size: 13px; }
.hiring-table td { vertical-align: middle; font-size: 13px; }
.hiring-table th:nth-child(1), .hiring-table td:nth-child(1) { width: 16%; }
.hiring-table th:nth-child(2), .hiring-table td:nth-child(2) { width: 15%; }
.hiring-table th:nth-child(3), .hiring-table td:nth-child(3) { width: 21%; }
.hiring-table th:nth-child(4), .hiring-table td:nth-child(4) { width: 11%; }
.hiring-table th:nth-child(5), .hiring-table td:nth-child(5) { width: 13%; }
.hiring-table th:nth-child(6), .hiring-table td:nth-child(6) { width: 10%; }
.hiring-table th:nth-child(7), .hiring-table td:nth-child(7) { width: 14%; }
.hiring-table td:nth-child(5), .hiring-table td:nth-child(6) { white-space: nowrap; }
.hiring-table .table-subline { display: block; }
.hire-reference { color: #272e39; font-size: 14px; font-weight: 750; letter-spacing: .01em; white-space: nowrap; }
.hire-customer-name { color: #303744; font-size: 14px; font-weight: 700; }
.hiring-table .table-subline { margin-top: 4px; color: #555f6b; font-size: 12px; }
.hire-item-list { display: grid; min-width: 150px; gap: 8px; }
.hire-item { display: grid; gap: 5px; }
.hire-item-copy { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.hire-item-copy strong { overflow: hidden; color: #303945; font-size: 13px; font-weight: 600; text-overflow: ellipsis; }
.hire-item-copy small { flex: 0 0 auto; color: #515b68; font-size: 12px; }
.hire-item-progress { display: block; height: 4px; overflow: hidden; border-radius: 5px; background: #edf0f2; }
.hire-item-progress > span { display: block; height: 100%; border-radius: inherit; background: #35a176; transition: width .2s ease; }
.hire-location { color: #39424e; font-size: 13px; line-height: 1.45; }
.hire-due { display: grid; gap: 4px; min-width: 93px; }
.hire-due strong { color: #303945; font-size: 13px; font-weight: 700; }
.hire-due small { color: #555f6b; font-size: 12px; }
.hire-due.due-overdue strong { color: #b4232a; }
.hire-due.due-today strong { color: #ad5b00; }
.hire-due.due-soon strong { color: #8a641d; }
.hire-row-overdue { background: #fffafa; }
.hiring-status { display: inline-flex; min-height: 29px; align-items: center; border-radius: 20px; padding: 5px 10px; font-size: 12px; font-weight: 700; text-transform: capitalize; }
.hiring-status.issued { border: 1px solid #d3e7f8; background: #eef7ff; color: #28658d; }
.hiring-status.partially_returned { border: 1px solid #f2dfb6; background: #fff8e7; color: #916516; }
.hiring-status.reserved { border: 1px solid #e2d7f5; background: #f6f0ff; color: #7043a3; }
.hiring-status.completed { border: 1px solid #cce8dc; background: #edfaf4; color: #237451; }
.hiring-status.cancelled { border: 1px solid #e6e8eb; background: #f3f4f6; color: #656c78; }
.hiring-status.returned_pending_inspection { border: 1px solid #f4dcc0; background: #fff5e9; color: #a75b1e; }
.hire-actions { min-width: 0; }
.hire-next-step { display: grid; justify-items: start; gap: 7px; }
.next-step-label { display: inline-flex; align-items: center; gap: 7px; color: #566170; font-size: 12px; font-weight: 700; line-height: 1.3; }
.next-step-label i { width: 8px; height: 8px; flex: 0 0 auto; border-radius: 50%; background: #d39a32; box-shadow: 0 0 0 3px #fff3d9; }
.next-return .next-step-label i { background: #d89528; box-shadow: 0 0 0 3px #fff3d9; }
.next-inspection .next-step-label i { background: #d1762e; box-shadow: 0 0 0 3px #fff0e2; }
.next-done .next-step-label { color: #237451; }
.next-done .next-step-label svg { width: 15px; height: 15px; }
.next-inactive .next-step-label { color: #737b86; }
.next-inactive .next-step-label i { background: #9299a2; box-shadow: 0 0 0 3px #f0f1f3; }
.hire-next-step small { color: #697381; font-size: 11px; line-height: 1.35; }
.hire-next-action { display: inline-flex; min-height: 34px; width: 100%; align-items: center; justify-content: space-between; gap: 5px; border: 1px solid #dce2e8; border-radius: 7px; background: #fff; padding: 6px 8px; color: #354153; font-size: 12px; font-weight: 700; text-decoration: none; transition: border-color .16s ease, background .16s ease, color .16s ease, transform .16s ease; }
.hire-next-action svg { width: 14px; height: 14px; flex: 0 0 auto; }
.hire-next-action:hover { transform: translateY(-1px); border-color: #c91f2a; background: #fff8f8; color: #b91f2a; }
.next-ready .hire-next-action { border-color: #c91f2a; background: #c91f2a; color: #fff; }
.next-ready .hire-next-action:hover { border-color: #a91822; background: #a91822; color: #fff; }
.cancel-reservation-action { display: inline-flex; align-items: center; gap: 5px; border: 0; background: transparent; padding: 0; color: #9a3c42; cursor: pointer; font: inherit; font-size: 11px; }
.cancel-reservation-action svg { width: 13px; height: 13px; }
.cancel-reservation-action:hover { color: #b4232a; text-decoration: underline; }
.hire-next-action:focus-visible, .cancel-reservation-action:focus-visible { outline: 3px solid rgb(201 31 42 / 25%); outline-offset: 2px; }
.table-panel :deep(.pagination) { font-size: 12px; }
.table-panel :deep(.pagination-link) { min-width: 34px; min-height: 34px; font-size: 12px; }
.empty-action { margin-top: 14px; }
@media (max-width: 900px) { .hiring-table { min-width: 1180px; }.hiring-table th:first-child, .hiring-table td:first-child, .hiring-table td:nth-child(2), .hiring-table td:nth-child(3) { min-width: 0; } }
@media (max-width: 700px) {
    .heading-actions { width: 100%; flex-wrap: wrap; }
    .heading-actions > * { flex: 1 1 30%; justify-content: center; }
    .hiring-toolbar { align-items: stretch; flex-wrap: wrap; }
    .hiring-search { width: 100%; flex: 1 1 100%; }
    .status-filter { flex: 1; }
    .status-filter .filter-select { width: 100%; }
    .hiring-results-bar { padding: 0 13px 10px; }
    .hiring-results-bar span { text-align: right; }
    .hiring-table { min-width: 0; }
    .hiring-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; clip-path: inset(50%); }
    .hiring-table, .hiring-table tbody { display: block; width: 100%; }
    .hiring-table tbody { display: grid; gap: 10px; padding: 10px; }
    .hiring-table tbody tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 11px 14px; border: 1px solid #e9ebee; border-radius: 11px; background: #fff; padding: 13px; box-shadow: 0 2px 8px rgb(26 33 44 / 4%); }
    .hiring-table tbody tr.hire-row-overdue { border-color: #f0d9da; background: #fffafa; }
    .hiring-table td, .hiring-table td:nth-child(n) { display: grid; width: auto; min-width: 0; gap: 5px; border: 0; padding: 0 !important; white-space: normal; }
    .hiring-table td::before { color: #66707d; content: attr(data-label); font-size: 11px; font-weight: 700; letter-spacing: .035em; text-transform: uppercase; }
    .hiring-table td:first-child, .hiring-table td:nth-child(2), .hiring-table td:nth-child(3) { grid-column: 1 / -1; }
    .hiring-table td:first-child { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; border-bottom: 1px solid #eff0f2; padding-bottom: 9px !important; }
    .hiring-table td:first-child::before { display: none; }
    .hiring-table td:first-child .table-subline { margin: 0; }
    .hiring-table td:nth-child(2) { display: flex; flex-wrap: wrap; align-items: baseline; column-gap: 8px; row-gap: 2px; }
    .hiring-table td:nth-child(2)::before { flex: 0 0 100%; }
    .hiring-table td:nth-child(2) .table-subline { margin: 0; }
    .hiring-table td:nth-child(3) { padding-bottom: 2px !important; }
    .hiring-table td:nth-child(3)::before { margin-bottom: 1px; }
    .hire-item-list { min-width: 0; gap: 9px; }
    .hire-item-copy strong { font-size: 12px; }
    .hire-actions { min-width: 0; }
}
@media (max-width: 420px) { .heading-actions > * { flex-basis: 100%; }.hiring-table tbody tr { gap: 10px; padding: 11px; }.hiring-table td:nth-child(4) { grid-column: 1 / -1; } }
</style>
