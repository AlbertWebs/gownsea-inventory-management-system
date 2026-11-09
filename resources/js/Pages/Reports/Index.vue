<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, Boxes, CalendarClock, MapPinned, Package, TrendingUp, Users, Coins, Receipt, ChartNoAxesColumn, ClipboardList, Activity, Download } from '@lucide/vue';
import { ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';

type Status = { status: string; total: number };
type Location = { id: number; name: string; type: string };
type CustomerOption = { id: number; name: string; number: string };
type Reconciliation = { receivedCharges: number; refunds: number; depositApplications: number; netRecognizedRevenue: number; bulkSalesRevenue: number; bulkCostOfGoodsSold: number; bulkSalesGrossMargin: number; bulkInventoryValue: number; directOrderCosts: number; grossContribution: number; approvedExpenses: number; paidExpenses: number; assetDepreciation: number; outputTaxInvoiced: number; vendorPayables: number; overdueVendorPayables: number; netManagementResult: number; unpaidApprovedExpenses: number; outstandingReceivables: number; hireReceivable: number; customOrderReceivable: number; depositLiability: number; paymentExceptions: number; paymentExceptionAmount: number };
const props = defineProps<{ stockByStatus: Status[]; locations: Location[]; customers: CustomerOption[]; locationId: number | null; customerId: number | null; reportCatalog: { slug: string; title: string; description: string; icon: string }[]; dateFrom: string; dateTo: string; financialReconciliation: Reconciliation | null; metrics: { assets: number; available: number; hired: number; overdue: number; inTransit: number; openTransfers: number; bulkOnHand: number } }>();
const iconMap: Record<string, unknown> = { boxes: Boxes, calendar: CalendarClock, alert: AlertTriangle, users: Users, map: MapPinned, trending: TrendingUp, coins: Coins, receipt: Receipt, package: Package, chart: ChartNoAxesColumn, clipboard: ClipboardList, activity: Activity };
const startDate = ref(props.dateFrom);
const endDate = ref(props.dateTo);
const locationId = ref(props.locationId ? String(props.locationId) : '');
const customerId = ref(props.customerId ? String(props.customerId) : '');
const kes = (minor: number): string => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 0 }).format(minor / 100);
function applyPeriod(): void {
    router.get('/reports', { start_date: startDate.value, end_date: endDate.value, location_id: locationId.value || undefined, customer_id: customerId.value || undefined }, { preserveScroll: true, preserveState: true, replace: true });
}
function exportUrl(slug: string, format: 'csv' | 'pdf'): string {
    const query = new URLSearchParams({ start_date: startDate.value, end_date: endDate.value, format });
    if (locationId.value) query.set('location_id', locationId.value);
    if (customerId.value && slug !== 'financial-reconciliation') query.set('customer_id', customerId.value);
    return `/reports/${slug}/export?${query.toString()}`;
}
</script>

<template>
    <Head title="Reports" />
    <AppShell active="reports">
        <div class="page-heading"><div><h1>Reports</h1><p>Live operational snapshots for locations you are authorized to view.</p></div></div>
        <div class="report-metrics">
            <article class="panel report-metric"><span>Total serialized assets</span><strong>{{ metrics.assets.toLocaleString() }}</strong></article>
            <article class="panel report-metric"><span>Available</span><strong>{{ metrics.available.toLocaleString() }}</strong></article>
            <article class="panel report-metric"><span>Hired out</span><strong>{{ metrics.hired.toLocaleString() }}</strong></article>
            <article class="panel report-metric alert-metric"><span>Overdue quantity</span><strong>{{ metrics.overdue.toLocaleString() }}</strong></article>
            <article class="panel report-metric"><span>In transit</span><strong>{{ metrics.inTransit.toLocaleString() }}</strong></article>
            <article class="panel report-metric"><span>Open transfers</span><strong>{{ metrics.openTransfers.toLocaleString() }}</strong></article>
            <article class="panel report-metric"><span>Bulk stock on hand</span><strong>{{ metrics.bulkOnHand.toLocaleString() }}</strong></article>
        </div>
        <section v-if="financialReconciliation" class="panel reconciliation-panel">
            <div class="report-heading"><div><h2 class="panel-title">Financial reconciliation</h2><p class="panel-subtitle">Cash basis management activity and current accrual balances · {{ dateFrom }} to {{ dateTo }}</p></div><label>Location <select v-model="locationId" aria-label="Filter reports by location"><option value="">All accessible locations</option><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select></label><label>From <input v-model="startDate" type="date" aria-label="Reconciliation start date"></label><label>To <input v-model="endDate" type="date" aria-label="Reconciliation end date"></label><button class="secondary-link" type="button" @click="applyPeriod">Apply filters</button></div>
            <div class="recon-grid">
                <article><span>Charge receipts, net of refunds</span><strong>{{ kes(financialReconciliation.receivedCharges - financialReconciliation.refunds) }}</strong><small>Cash collected for hire, order and penalty charges</small></article>
                <article><span>Deposit applications</span><strong>{{ kes(financialReconciliation.depositApplications) }}</strong><small>Deposit liability reclassified against earned charges</small></article>
                <article><span>Cash-basis charge activity</span><strong>{{ kes(financialReconciliation.netRecognizedRevenue) }}</strong><small>Receipts less refunds plus deposit applications; separate from invoice accruals</small></article>
                <article><span>Bulk product sales revenue</span><strong>{{ kes(financialReconciliation.bulkSalesRevenue) }}</strong><small>Posted product sales less sale refunds</small></article>
                <article><span>Bulk cost of goods sold</span><strong>{{ kes(financialReconciliation.bulkCostOfGoodsSold) }}</strong><small>Sale cost less returned stock cost</small></article>
                <article><span>Bulk sales gross margin</span><strong>{{ kes(financialReconciliation.bulkSalesGrossMargin) }}</strong><small>Bulk sale revenue less matched COGS</small></article>
                <article><span>Bulk inventory at cost</span><strong>{{ kes(financialReconciliation.bulkInventoryValue) }}</strong><small>Current branch scoped inventory asset balance</small></article>
                <article><span>Direct custom order costs</span><strong>{{ kes(financialReconciliation.directOrderCosts) }}</strong><small>Costs recorded in the period</small></article>
                <article><span>Gross contribution</span><strong>{{ kes(financialReconciliation.grossContribution) }}</strong><small>Recognized charge revenue less custom order costs; excludes bulk COGS</small></article>
                <article><span>Approved operating expenses</span><strong>{{ kes(financialReconciliation.approvedExpenses) }}</strong><small>By incurred date, whether paid or unpaid</small></article>
                <article class="recon-result"><span>Management result</span><strong>{{ kes(financialReconciliation.netManagementResult) }}</strong><small>Cash basis; excludes bulk COGS, inventory purchases, depreciation and tax accruals</small></article>
                <article><span>Operating expenses paid</span><strong>{{ kes(financialReconciliation.paidExpenses) }}</strong><small>Payments made in this period</small></article>
                <article><span>Hire asset depreciation</span><strong>{{ kes(financialReconciliation.assetDepreciation) }}</strong><small>Posted straight-line depreciation in the period</small></article>
                <article><span>Net output tax invoiced</span><strong>{{ kes(financialReconciliation.outputTaxInvoiced) }}</strong><small>Invoice tax less linked credit note tax in this period</small></article>
            </div>
            <h3 class="recon-subhead">Current balances and exceptions</h3>
            <div class="recon-controls"><span>Outstanding receivables <strong>{{ kes(financialReconciliation.outstandingReceivables) }}</strong><small>Hire {{ kes(financialReconciliation.hireReceivable) }} · custom orders {{ kes(financialReconciliation.customOrderReceivable) }}</small></span><span>Refundable deposits held <strong>{{ kes(financialReconciliation.depositLiability) }}</strong><small>After refunds and applications</small></span><span>Vendor payables outstanding <strong>{{ kes(financialReconciliation.vendorPayables) }}</strong><small>{{ kes(financialReconciliation.overdueVendorPayables) }} past due</small></span><span>Approved expenses unpaid <strong>{{ kes(financialReconciliation.unpaidApprovedExpenses) }}</strong><small>Current approved expense register</small></span><span>Unresolved M-Pesa requests <strong>{{ financialReconciliation.paymentExceptions }} · {{ kes(financialReconciliation.paymentExceptionAmount) }}</strong><small>Pending or manual review</small></span></div>
            <p class="report-note">This management reconciliation is not statutory accounts. Hire revenue posts at handover and custom-order revenue at completion; invoices, receipts and applied deposits reconcile through accounts receivable. Bulk product revenue, COGS and gross margin are reported separately; the cash-basis management result excludes COGS, inventory purchases, depreciation, tax and statutory accruals. Depreciation is listed as a separate period expense. Receivables, vendor payables and deposit liabilities are current totals.</p>
        </section>
        <div class="report-layout">
            <section class="panel report-list-panel"><div class="report-heading"><div><h2 class="panel-title">Operational reports</h2><p class="panel-subtitle">Download branch scoped CSV files that open in Excel or Sheets.</p></div><label>Location <select v-model="locationId" aria-label="Filter reports by location"><option value="">All accessible locations</option><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select></label><label>Customer activity <select v-model="customerId" aria-label="Filter customer reports"><option value="">All customers</option><option v-for="customer in customers" :key="customer.id" :value="String(customer.id)">{{ customer.name }} · {{ customer.number }}</option></select></label><label>From <input v-model="startDate" type="date" aria-label="Report start date" /></label><label>To <input v-model="endDate" type="date" aria-label="Report end date" /></label><button class="secondary-link" type="button" @click="applyPeriod">Apply filters</button></div>
                <div v-for="report in reportCatalog" :key="report.slug" class="report-link"><span class="report-icon"><component :is="iconMap[report.icon]" aria-hidden="true" /></span><span class="report-copy"><strong>{{ report.title }}</strong><small>{{ report.description }}</small></span><a class="export-link" :href="exportUrl(report.slug, 'pdf')" :aria-label="`Download ${report.title} PDF`"><Download aria-hidden="true" /> PDF</a><a class="export-link csv-export" :href="exportUrl(report.slug, 'csv')" :aria-label="`Download ${report.title} CSV`"><Download aria-hidden="true" /> CSV</a></div>
            </section>
            <section class="panel report-status-panel"><h2 class="panel-title">Inventory by status</h2><p class="panel-subtitle">Current serialized asset state distribution.</p>
                <div v-if="stockByStatus.length" class="status-report-list"><div v-for="row in stockByStatus" :key="row.status" class="status-report-row"><span>{{ row.status.replaceAll('_', ' ') }}</span><strong>{{ row.total.toLocaleString() }}</strong></div></div>
                <div v-else class="empty-state compact-empty"><div><Package aria-hidden="true" /><strong>No stock records</strong><span>Asset reports will populate when inventory is registered.</span></div></div>
            </section>
        </div>
        <p class="report-note">Downloads follow your assigned location access. Sales, expense, CRM and staff activity reports are restricted by role. CSV cells beginning with spreadsheet formula characters are escaped.</p>
    </AppShell>
</template>

<style scoped>
.report-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 13px; }
.report-metric { display: flex; min-height: 86px; flex-direction: column; justify-content: space-between; gap: 12px; padding: 14px 16px; }
.reconciliation-panel { margin-bottom: 13px; padding: 17px; }
.recon-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px; margin-top: 14px; }
.recon-grid article { display: flex; min-height: 96px; flex-direction: column; gap: 8px; border: 1px solid #eef0f1; border-radius: 7px; padding: 11px; }
.recon-grid article span, .recon-controls > span { color: #606874; font-size: 10px; }
.recon-grid article strong { color: #202633; font-size: 17px; }
.recon-grid article small, .recon-controls small { color: #606874; font-size: 9px; line-height: 1.4; }
.recon-grid .recon-result { background: #fff7f0; border-color: #f1ddc9; }
.recon-subhead { margin: 17px 0 7px; color: #303743; font-size: 11px; }
.recon-controls { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px; }
.recon-controls > span { display: flex; flex-direction: column; gap: 5px; }
.recon-controls strong { color: #202633; font-size: 14px; }
.report-metric span { color: #606874; font-size: 10px; }
.report-metric strong { color: #171c27; font-size: 20px; }
.alert-metric strong { color: #d42127; }
.report-layout { display: grid; grid-template-columns: 1.3fr .8fr; gap: 12px; }
.report-list-panel, .report-status-panel { padding: 17px; }
.report-heading { display: flex; align-items: end; flex-wrap: wrap; gap: 10px; justify-content: space-between; }
.report-heading label { display: grid; gap: 4px; color: #606874; font-size: 10px; }
.report-heading input, .report-heading select { border: 1px solid #e4e6e9; border-radius: 6px; padding: 6px 8px; color: #343a46; font: inherit; }
.report-link { display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eef0f1; padding: 14px 2px; }
.report-link:last-child { border-bottom: 0; }
.report-link:hover .report-copy strong { color: #d42127; }
.report-link > svg { width: 16px; color: #606874; }
.report-icon { display: grid; width: 38px; height: 38px; flex: 0 0 38px; place-items: center; border-radius: 50%; background: #fff0f0; color: #d42127; }
.report-icon svg { width: 19px; }
.report-copy { flex: 1; }
.report-copy strong { display: block; color: #262c38; font-size: 11px; }
.report-copy small { display: block; margin-top: 4px; color: #606874; font-size: 10px; }
.export-link { display: inline-flex; align-items: center; gap: 5px; flex: 0 0 auto; color: #c91f2a; font-size: 10px; font-weight: 700; }
.export-link svg { width: 14px; }
.status-report-list { margin-top: 16px; }
.status-report-row { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid #eff0f1; padding: 11px 0; color: #606874; font-size: 11px; text-transform: capitalize; }
.status-report-row strong { color: #202633; }
.compact-empty { min-height: 140px; }
.report-note { margin: 13px 0 0; color: #606874; font-size: 10px; line-height: 1.5; }
@media(max-width:700px) { .report-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; } .report-layout { grid-template-columns: 1fr; gap: 9px; } .recon-grid,.recon-controls { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
