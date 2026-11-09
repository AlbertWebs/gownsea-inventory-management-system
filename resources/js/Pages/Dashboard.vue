<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Chart, Doughnut } from 'vue-chartjs';
import { Chart as ChartJS, registerables, type ChartData, type ChartOptions } from 'chart.js';
import {
    AlertTriangle, ArrowRight, BellRing, CalendarDays, ChevronRight, CircleAlert,
    Clock3, MapPin, Package, ScanLine, TrendingUp, TriangleAlert, ClipboardCheck,
} from '@lucide/vue';
import AppShell from '../Components/AppShell.vue';

ChartJS.register(...registerables);

type Location = { id: number; code: string; name: string; type: string };
type RevenueMonth = { label: string; value: number };
type ReturnStatus = { label: string; value: number; color: string };
type HireRow = { id: number; reference: string; date: string; customer: string; institution: string; items: number; itemLabel: string; dueDate: string; amount: number; status: string };
type EventRow = { id: number; institution: string; name: string; date: string; month: string; expected: number; venue: string | null };

const props = defineProps<{
    filters: { locationId: number | null; locations: Location[]; period: number };
    metrics: { inventoryTotal: number; available: number; currentlyHired: number; dueToday: number; overdue: number; damaged: number; missing: number; underRepair: number; reserved: number; inTransit: number; inspection: number; receivedThisPeriod: number; customOrdersDue: number; activeCustomOrders: number; activeLeads: number; leadFollowUpsDue: number; outstandingReceivables: number; smsRemindersPending: number };
    returnStatus: ReturnStatus[];
    revenue: RevenueMonth[];
    recentHires: HireRow[];
    upcomingEvents: EventRow[];
    today: string;
}>();

const page = usePage();
const staff = computed(() => (page.props.auth as { user: { name: string; role: string } | null }).user);
const greeting = computed(() => {
    const hour = new Date().getHours();
    return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
});

const revenueChart = computed<ChartData<'bar' | 'line'>>(() => ({
    labels: props.revenue.map((month) => month.label),
    datasets: [{
        type: 'bar',
        label: 'Service charges received',
        data: props.revenue.map((month) => month.value / 100),
        backgroundColor: '#d42127',
        borderRadius: 4,
        maxBarThickness: 39,
    }, {
        type: 'line',
        label: 'Three-month revenue trend',
        data: props.revenue.map((_, index, months) => {
            const window = months.slice(Math.max(0, index - 2), index + 1);

            return window.reduce((total, month) => total + month.value / 100, 0) / window.length;
        }),
        borderColor: '#c89f56',
        backgroundColor: 'transparent',
        pointBackgroundColor: '#c89f56',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 4,
        borderWidth: 2,
        tension: 0.3,
        fill: false,
    }],
}));

const revenueOptions: ChartOptions<'bar' | 'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 180 },
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => ` KES ${Number(context.raw).toLocaleString('en-KE')}` } } },
    scales: {
        x: { grid: { display: false }, border: { display: false }, ticks: { color: '#6f7683', font: { size: 10 } } },
        y: { beginAtZero: true, grid: { color: '#eceef0' }, border: { display: false }, ticks: { color: '#7b828d', maxTicksLimit: 5, font: { size: 9 }, callback: (value) => Number(value) >= 1000 ? `${Number(value) / 1000}k` : value } },
    },
};

const visibleReturnStatus = computed(() => props.returnStatus.filter((status) => status.value > 0));
const returnChart = computed<ChartData<'doughnut'>>(() => ({
    labels: visibleReturnStatus.value.map((status) => status.label),
    datasets: [{ data: visibleReturnStatus.value.map((status) => status.value), backgroundColor: visibleReturnStatus.value.map((status) => status.color), borderWidth: 2, borderColor: '#fff', hoverOffset: 3 }],
}));
const returnOptions: ChartOptions<'doughnut'> = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '70%',
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => ` ${context.label}: ${Number(context.raw).toLocaleString('en-KE')}` } } },
};
const totalSnapshot = computed(() => props.returnStatus.reduce((total, status) => total + status.value, 0));
const dashboardActionCount = computed(() => [
    props.metrics.overdue,
    props.metrics.dueToday,
    props.metrics.customOrdersDue,
    props.metrics.smsRemindersPending,
    props.metrics.inspection,
    props.metrics.missing,
    props.metrics.inTransit,
    props.metrics.leadFollowUpsDue,
].reduce((total, count) => total + count, 0));
const revenueMonthChange = computed(() => {
    const currentMonth = props.revenue.at(-1)?.value ?? 0;
    const previousMonth = props.revenue.at(-2)?.value ?? 0;

    return previousMonth > 0 ? Math.round(((currentMonth - previousMonth) / previousMonth) * 100) : null;
});
const priorities = computed(() => [
    { count: props.metrics.overdue, title: `${props.metrics.overdue.toLocaleString()} ${props.metrics.overdue === 1 ? 'gown' : 'gowns'} overdue`, note: 'Contact customers', tone: 'red', icon: TriangleAlert, href: '/hiring?status=overdue' },
    { count: props.metrics.dueToday, title: `${props.metrics.dueToday.toLocaleString()} due today`, note: 'Awaiting return', tone: 'amber', icon: Clock3, href: '/hiring?status=due_today' },
    { count: props.metrics.customOrdersDue, title: `${props.metrics.customOrdersDue.toLocaleString()} custom orders due`, note: 'Production follow-up', tone: 'amber', icon: Package, href: '/custom-orders' },
    { count: props.metrics.smsRemindersPending, title: `${props.metrics.smsRemindersPending.toLocaleString()} SMS reminders pending`, note: 'Scheduled for today', tone: 'red', icon: BellRing, href: '/customers' },
    { count: props.metrics.inspection, title: `${props.metrics.inspection.toLocaleString()} awaiting inspection`, note: 'Confirm condition before hire', tone: 'neutral', icon: Package, href: '/inventory?status=returned_pending_inspection' },
    { count: props.metrics.missing, title: `${props.metrics.missing.toLocaleString()} items missing`, note: 'Reconcile stock custody', tone: 'red', icon: TriangleAlert, href: '/inventory?status=missing' },
    { count: props.metrics.inTransit, title: `${props.metrics.inTransit.toLocaleString()} gowns in transit`, note: 'Confirm branch receipt', tone: 'neutral', icon: BellRing, href: '/transfers' },
    { count: props.metrics.leadFollowUpsDue, title: `${props.metrics.leadFollowUpsDue.toLocaleString()} lead follow-ups due`, note: 'Overdue or scheduled for today', tone: 'amber', icon: ClipboardCheck, href: '/crm' },
].filter((priority) => priority.count > 0).slice(0, 5));

function formatKes(amountMinor: number): string {
    return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 0 }).format(amountMinor / 100);
}

function changeLocation(event: Event): void {
    const location = (event.target as HTMLSelectElement).value;
    router.get('/', { ...(location ? { location } : {}), period: props.filters.period }, { preserveScroll: true, preserveState: true, replace: true });
}

function changePeriod(event: Event): void {
    const period = (event.target as HTMLSelectElement).value;
    router.get('/', { ...(props.filters.locationId ? { location: props.filters.locationId } : {}), period }, { preserveScroll: true, preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Dashboard" />
    <AppShell active="dashboard" :action-count="dashboardActionCount">
        <section class="intro-row" aria-label="Business overview">
            <div class="intro-copy">
                <p class="eyebrow">{{ today }}</p>
                <h1 class="intro-title">{{ greeting }}, <span>GOWNSEA</span></h1>
                <p class="intro-subtitle">Here's what's happening with your business today.</p>
                <label v-if="filters.locations.length" class="location-filter">
                    <span>Location</span>
                    <select :value="filters.locationId ?? ''" aria-label="Filter dashboard by location" @change="changeLocation">
                        <option v-if="staff && ['super_admin', 'management'].includes(staff.role)" value="">All Locations</option>
                        <option v-for="location in filters.locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                    </select>
                </label>
            </div>
            <div class="hero-art" role="img" aria-label="Graduates celebrating in academic gowns" />
            <div class="brand-quote">“Quality Gowns<br>for Greater Futures”</div>
        </section>

        <section class="summary-grid" aria-label="Key business figures">
            <Link class="stat-card" href="/inventory">
                <span class="stat-icon"><Package aria-hidden="true" /></span>
                <span class="stat-main">
                    <span class="stat-top">Total Inventory<ChevronRight aria-hidden="true" /></span>
                    <strong class="stat-value">{{ metrics.inventoryTotal.toLocaleString() }}</strong>
                        <span class="stat-note"><strong>{{ metrics.available.toLocaleString() }} available</strong></span>
                </span>
            </Link>
            <Link class="stat-card" href="/hiring">
                <span class="stat-icon red"><CalendarDays aria-hidden="true" /></span>
                <span class="stat-main">
                    <span class="stat-top">Currently Hired<ChevronRight aria-hidden="true" /></span>
                    <strong class="stat-value">{{ metrics.currentlyHired.toLocaleString() }}</strong>
                    <span class="stat-note">Customer custody now</span>
                </span>
            </Link>
            <Link class="stat-card" href="/hiring?status=due_today">
                <span class="stat-icon rose"><Clock3 aria-hidden="true" /></span>
                <span class="stat-main">
                    <span class="stat-top">Due Today<ChevronRight aria-hidden="true" /></span>
                    <strong class="stat-value">{{ metrics.dueToday.toLocaleString() }}</strong>
                    <span class="stat-note alert">Awaiting return</span>
                </span>
            </Link>
            <Link class="stat-card" href="/hiring?status=overdue">
                <span class="stat-icon rose"><CircleAlert aria-hidden="true" /></span>
                <span class="stat-main">
                    <span class="stat-top">Overdue<ChevronRight aria-hidden="true" /></span>
                    <strong class="stat-value">{{ metrics.overdue.toLocaleString() }}</strong>
                    <span class="stat-note alert danger">Action required</span>
                </span>
            </Link>
            <div class="quick-actions">
                <Link class="action-button primary" href="/hiring/issue">
                    <ScanLine aria-hidden="true" /><span class="action-copy"><strong>Issue Gowns</strong><small>Create new hiring</small></span><ArrowRight aria-hidden="true" />
                </Link>
                <Link class="action-button secondary" href="/returns/scan">
                    <ScanLine aria-hidden="true" /><span class="action-copy"><strong>Scan Return</strong><small>Process returned items</small></span><ArrowRight aria-hidden="true" />
                </Link>
            </div>
        </section>

        <section class="dashboard-grid" aria-label="Business activity">
            <article class="panel revenue-panel">
                <div class="panel-title-row">
                    <div><h2 class="panel-title">Revenue Overview</h2><p class="panel-subtitle">Received hire charges and penalties · deposits excluded</p></div>
                    <select class="panel-filter" :value="filters.period" aria-label="Revenue chart time period" @change="changePeriod">
                        <option value="6">Last 6 Months</option><option value="12">Last 12 Months</option>
                    </select>
                </div>
                <div class="revenue-total">
                    <strong>{{ formatKes(metrics.receivedThisPeriod) }}</strong>
                    <span v-if="revenueMonthChange !== null" class="revenue-change" :class="revenueMonthChange >= 0 ? 'positive' : 'negative'"><TrendingUp aria-hidden="true" />{{ revenueMonthChange > 0 ? '+' : '' }}{{ revenueMonthChange }}%</span>
                    <span>{{ revenueMonthChange !== null ? 'vs last month' : 'received this month' }}</span>
                </div>
                <div v-if="metrics.receivedThisPeriod || revenue.some((month) => month.value > 0)" class="chart-wrap">
                    <Chart type="bar" :data="revenueChart" :options="revenueOptions" aria-label="Monthly revenue and three-month trend in Kenyan shillings" />
                </div>
                <div v-else class="chart-empty empty-state"><div><TrendingUp aria-hidden="true" /><strong>No payment records yet</strong><span>Monthly figures will appear when staff record received service charge payments.</span></div></div>
                <div class="chart-legend"><span class="legend-key"><i class="legend-dot" />Revenue</span><span class="legend-key"><i class="legend-dot gold" />3-month trend</span></div>
            </article>

            <article class="panel returns-panel">
                <div class="panel-title-row"><div><h2 class="panel-title">Return Status</h2><p class="panel-subtitle">Current, non-overlapping asset states</p></div><span class="panel-filter">All active</span></div>
                <div class="returns-body">
                    <div class="donut-wrap">
                        <Doughnut :data="returnChart" :options="returnOptions" aria-label="Inventory state distribution" />
                        <div class="donut-center"><strong>{{ totalSnapshot.toLocaleString() }}</strong><small>Assets</small></div>
                    </div>
                    <div class="status-legend" role="list" aria-label="Asset status values">
                        <div v-for="status in visibleReturnStatus" :key="status.label" class="status-row" role="listitem"><i class="status-swatch" :style="{ backgroundColor: status.color }" /><span>{{ status.label }}</span><strong>{{ status.value.toLocaleString() }}</strong></div>
                    </div>
                </div>
            </article>

            <article class="panel priority-panel">
                <div class="panel-title-row"><h2 class="panel-title">Today's Priorities</h2><Link class="view-all" href="/hiring">View All</Link></div>
                <div v-if="priorities.length" class="priority-list">
                    <Link v-for="priority in priorities" :key="priority.title" class="priority-item" :href="priority.href">
                        <span class="priority-icon" :class="priority.tone"><component :is="priority.icon" aria-hidden="true" /></span>
                        <span class="priority-copy"><strong>{{ priority.title }}</strong><small>{{ priority.note }}</small></span>
                        <ChevronRight class="priority-arrow" aria-hidden="true" />
                    </Link>
                </div>
                <div v-else class="priority-empty"><AlertTriangle :size="17" aria-hidden="true" /> No overdue or due return actions are waiting at this location.</div>
            </article>
        </section>

        <section class="lower-grid" aria-label="Recent activity and upcoming events">
            <article class="panel transactions-panel">
                <div class="panel-title-row"><h2 class="panel-title">Recent Transactions</h2><Link class="view-all" href="/hiring">View All</Link></div>
                <div v-if="recentHires.length" class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Date</th><th>Customer</th><th>Institution / Location</th><th>Items</th><th>Due Date</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-for="hire in recentHires" :key="hire.id">
                                <td>{{ hire.date }}</td><td>{{ hire.customer }}</td><td>{{ hire.institution }}</td><td :title="hire.itemLabel">{{ hire.items }} {{ hire.items === 1 ? 'item' : 'items' }}</td><td>{{ hire.dueDate }}</td><td class="amount">{{ formatKes(hire.amount) }}</td><td><span class="status-chip" :class="hire.status">{{ hire.status.replaceAll('_', ' ') }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state"><div><CalendarClock aria-hidden="true" /><strong>No hiring transactions yet</strong><span>Once staff issue gowns, the latest transactions will appear here.</span></div></div>
            </article>

            <article class="panel events-panel">
                <div class="panel-title-row"><h2 class="panel-title">Upcoming Events</h2><Link class="view-all" href="/events">View All</Link></div>
                <div v-if="upcomingEvents.length" class="event-list">
                    <div v-for="event in upcomingEvents" :key="event.id" class="event-item">
                        <span class="event-date"><strong>{{ event.date }}</strong><small>{{ event.month }}</small></span>
                        <span><span class="event-title">{{ event.institution }} {{ event.name }}</span><span class="event-place"><MapPin aria-hidden="true" />{{ event.venue ?? 'Venue to be confirmed' }}</span></span>
                        <strong class="event-quantity">{{ event.expected.toLocaleString() }}<small>Expected</small></strong>
                    </div>
                </div>
                <div v-else class="empty-state"><div><CalendarDays aria-hidden="true" /><strong>No upcoming events recorded</strong><span>Graduation events added for your locations will appear here.</span></div></div>
            </article>
        </section>
    </AppShell>
</template>
