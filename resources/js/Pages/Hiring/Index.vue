<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarClock, Search, ScanLine, Plus } from '@lucide/vue';
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
        <div class="toolbar">
            <label class="global-search hiring-search"><Search aria-hidden="true" /><input v-model="query" type="search" placeholder="Search customer or hire reference" aria-label="Search hiring records"></label>
            <select v-model="status" class="filter-select" aria-label="Filter hiring status">
                <option value="">All statuses</option><option value="due_today">Due today</option><option value="overdue">Overdue</option><option value="issued">Issued</option><option value="partially_returned">Partially returned</option><option value="returned_pending_inspection">Returned for inspection</option><option value="completed">Completed</option><option value="reserved">Reserved</option><option value="cancelled">Cancelled</option>
            </select>
        </div>
        <section class="panel table-panel">
            <div v-if="hires.data.length" class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Hire reference</th><th>Customer</th><th>Items</th><th>Pickup location</th><th>Created</th><th>Due</th><th>Status</th><th v-if="canOperate">Action</th></tr></thead>
                    <tbody>
                        <tr v-for="hire in hires.data" :key="hire.id">
                            <td><strong>{{ hire.reference }}</strong></td><td>{{ hire.customer.name }}<small class="table-subline">{{ hire.customer.phone ?? 'No phone recorded' }}</small></td>
                            <td><span v-for="item in hire.items" :key="item.product_name_snapshot">{{ item.product_name_snapshot }} ({{ item.returned_quantity }}/{{ item.quantity }})<br></span></td>
                            <td>{{ hire.location.name }}</td><td>{{ dateLabel(hire.created_at) }}</td><td>{{ dateLabel(hire.due_at) }}</td><td><span class="status-chip" :class="hire.status">{{ hire.status.replaceAll('_', ' ') }}</span></td><td v-if="canOperate" class="hire-actions"><template v-if="hire.status === 'reserved'"><Link :href="`/hiring/${hire.id}/issue-reservation`" class="secondary-link compact-action">Handover</Link><button class="danger-link" type="button" @click="cancelReservation(hire)">Cancel</button></template><span v-else>—</span></td>
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
.hire-actions { display: flex; align-items: center; gap: 6px; }
.compact-action { min-height: 30px; padding: 5px 8px; font-size: 10px; }
.danger-link { border: 0; background: transparent; color: #b4232a; cursor: pointer; font-size: 10px; }
.hiring-search { width: min(360px, 100%); }
.empty-action { margin-top: 14px; }
@media (max-width: 700px) { .heading-actions { width: 100%; } .heading-actions > * { flex: 1; } }
</style>
