<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type LedgerLine = { accountCode: string; accountName: string; locationName: string | null; debitMinor: number; creditMinor: number; description: string | null };
type JournalEntry = { id: number; reference: string; sourceType: string; sourceId: number; sourceReference: string; entryType: string; date: string; description: string; debitMinor: number; creditMinor: number; lines: LedgerLine[]; isReversed: boolean; reversalOfId: number | null; reversalReason: string | null };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
type Account = { code: string; name: string; type: string; debitMinor: number; creditMinor: number; balanceMinor: number };
const props = defineProps<{ entries: Page<JournalEntry>; accounts: Account[]; dateFrom: string; dateTo: string; selectedLocation: number | null; locations: { id: number; name: string }[]; canReverse: boolean }>();
const filter = reactive({ start_date: props.dateFrom, end_date: props.dateTo, location: props.selectedLocation ? String(props.selectedLocation) : '' });
const kes = (amount: number): string => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', minimumFractionDigits: 2 }).format(amount / 100);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(filter, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => router.get('/finance/ledger', { start_date: filter.start_date, end_date: filter.end_date, location: filter.location || undefined }, { preserveState: true, preserveScroll: true, replace: true }), 250);
});
function reverseEntry(entry: JournalEntry): void {
    if (entry.isReversed || entry.reversalOfId !== null) return;
    const reason = window.prompt(`Post a full reversal for ${entry.reference}? Enter a reason:`);
    if (!reason || reason.trim().length < 5) return;
    router.post(`/finance/ledger/${entry.id}/reverse`, { reason: reason.trim() }, { preserveScroll: true });
}
</script>

<template>
    <Head title="General ledger" />
    <AppShell active="finance">
        <div class="page-heading"><div><h1>General ledger</h1><p>Immutable, balanced postings for invoices, receipts, inventory movements, refunds, deposit applications and expenses.</p></div><div class="ledger-links"><Link href="/finance/invoices" class="secondary-link">Customer invoices</Link><Link href="/payments" class="secondary-link">Sales &amp; payments</Link></div></div>
        <section class="panel ledger-policy"><strong>Recognition basis</strong><span>Hire revenue posts at physical handover and custom-order revenue at completion; receipts, refunds and applied deposits clear those receivables. Custom-order receipts before completion are customer advances. Bulk product sales post revenue and cost of goods sold when sold; inventory receipts capitalize stock and branch transfers carry its value. Serialized hire assets capitalize at acquisition and depreciate monthly on a straight-line book basis. Vendor invoices post to payables and payments reduce payables. Refundable deposits remain liabilities until refunded or applied. Tax accounting is not included.</span></section>
        <div class="ledger-filters">
            <label>From<input v-model="filter.start_date" class="filter-select" type="date" aria-label="Ledger start date"></label>
            <label>To<input v-model="filter.end_date" class="filter-select" type="date" aria-label="Ledger end date"></label>
            <label>Location<select v-model="filter.location" class="filter-select"><option value="">All assigned locations</option><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select></label>
        </div>
        <section class="panel account-panel">
            <div class="ledger-section-title"><h2>Account balances</h2><span>Through {{ dateTo }}</span></div>
            <div class="account-grid"><article v-for="account in accounts" :key="account.code" class="account-card"><span>{{ account.code }} · {{ account.type }}</span><strong>{{ account.name }}</strong><b :class="account.balanceMinor < 0 ? 'negative-balance' : ''">{{ kes(account.balanceMinor) }}</b></article></div>
        </section>
        <section class="panel ledger-panel">
            <div class="ledger-section-title"><h2>Posted journal entries</h2><span>{{ entries.total.toLocaleString() }} entry(s) · {{ dateFrom }} to {{ dateTo }}</span></div>
            <div v-if="entries.data.length" class="table-wrap">
                <table class="data-table"><thead><tr><th>Date / reference</th><th>Source</th><th>Description</th><th>Debits</th><th>Credits</th><th>Lines</th></tr></thead>
                    <tbody><tr v-for="entry in entries.data" :key="entry.id"><td><strong>{{ entry.date }}</strong><small class="table-subline">{{ entry.reference }}</small></td><td>{{ entry.entryType }}<small class="table-subline">{{ entry.sourceType }} · {{ entry.sourceReference }}</small><small v-if="entry.reversalOfId" class="table-subline">Reverses entry #{{ entry.reversalOfId }} · {{ entry.reversalReason }}</small><span v-else-if="entry.isReversed" class="reversed-chip">Reversed</span></td><td>{{ entry.description }}</td><td class="amount">{{ kes(entry.debitMinor) }}</td><td class="amount">{{ kes(entry.creditMinor) }}</td><td><div v-for="(line, index) in entry.lines" :key="`${entry.id}-${line.accountCode}-${line.locationName}-${index}`" class="journal-line"><span>{{ line.accountCode }} · {{ line.accountName }}<small v-if="line.locationName" class="table-subline">{{ line.locationName }}</small></span><strong>{{ line.debitMinor ? `Dr ${kes(line.debitMinor)}` : `Cr ${kes(line.creditMinor)}` }}</strong></div><button v-if="canReverse && !entry.isReversed && entry.reversalOfId === null && !(entry.sourceType === 'StockMovement' && entry.entryType === 'inventory valuation')" class="reverse-entry" type="button" @click="reverseEntry(entry)">Reverse with reason</button></td></tr></tbody>
                </table>
            </div>
            <div v-else class="empty-state"><div><strong>No journal entries in this period</strong><span>Recorded transactions appear here after posting.</span></div></div>
            <Pagination v-if="entries.total > 0" :links="entries.links" :from="entries.from" :to="entries.to" :total="entries.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.ledger-links{display:flex;gap:8px}.ledger-policy{display:flex;gap:12px;align-items:flex-start;margin-bottom:13px;padding:14px 17px;border-left:3px solid #d7a750}.ledger-policy strong{flex:none;color:#3d414a;font-size:12px}.ledger-policy span{color:#606874;font-size:11px;line-height:1.55}.ledger-filters{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:13px}.ledger-filters label{display:grid;gap:5px;min-width:185px;color:#5c6472;font-size:11px}.account-panel,.ledger-panel{margin-bottom:13px;padding:17px}.ledger-section-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:13px}.ledger-section-title h2{margin:0;color:#171c26;font-size:14px}.ledger-section-title>span{color:#606874;font-size:10px}.account-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.account-card{display:grid;gap:4px;padding:11px;border:1px solid #eceef1;border-radius:8px}.account-card span{color:#606874;font-size:9px;text-transform:uppercase;letter-spacing:.04em}.account-card strong{font-size:11px;color:#303644}.account-card b{color:#171c26;font-size:14px}.account-card .negative-balance{color:#c7282e}.journal-line{display:flex;justify-content:space-between;gap:12px;min-width:260px;padding:3px 0;border-bottom:1px solid #f0f1f3;font-size:10px}.journal-line:last-child{border-bottom:0}.journal-line span{color:#606874}.journal-line strong{white-space:nowrap;color:#303644}.reverse-entry{margin-top:6px;padding:4px 7px;border:1px solid #e7c9ca;border-radius:5px;background:#fff;color:#b4232b;font-size:9px}.reversed-chip{display:inline-block;margin-top:4px;padding:3px 6px;border-radius:4px;background:#f4e7e7;color:#a3262b;font-size:9px}.amount{white-space:nowrap}@media(max-width:950px){.account-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.account-grid{grid-template-columns:1fr}.ledger-policy{display:grid}.ledger-section-title{align-items:flex-start;flex-direction:column}.ledger-filters{display:grid}.ledger-filters label{min-width:0}.ledger-links{flex-wrap:wrap}}
</style>
