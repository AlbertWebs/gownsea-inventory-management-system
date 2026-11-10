<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CalendarClock, ChevronLeft, ChevronRight, Save, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type SimplePage<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
type Option = { id: number; name: string };
type GraduationEvent = { id: number; name: string; institution_id: number; location_id: number; event_date: string; venue: string | null; expected_quantity: number; booked_quantity: number; collection_date: string | null; delivery_date: string | null; notes: string | null; status: string; institution: Option; location: Option; owner: Option | null };
type CalendarItem = { date: string; time?: string; title: string; type: string; detail: string; status: string };
const props = defineProps<{ events: SimplePage<GraduationEvent>; locations: Option[]; institutions: Option[]; canManage: boolean; calendar: { month: string; items: CalendarItem[] } }>();
const monthDate = computed(() => new Date(`${props.calendar.month}-01T12:00:00`));
const monthTitle = computed(() => monthDate.value.toLocaleDateString('en-KE', { month: 'long', year: 'numeric' }));
const todayKey = (() => {
    const today = new Date();
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
})();
const calendarDays = computed(() => {
    const start = new Date(monthDate.value);
    start.setDate(1 - ((start.getDay() + 6) % 7));
    const count = Math.ceil((new Date(monthDate.value.getFullYear(), monthDate.value.getMonth() + 1, 0).getDate() + ((monthDate.value.getDay() + 6) % 7)) / 7) * 7;
    return Array.from({ length: count }, (_, index) => {
        const date = new Date(start); date.setDate(start.getDate() + index);
        const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        return { key, number: date.getDate(), inMonth: date.getMonth() === monthDate.value.getMonth(), items: props.calendar.items.filter(item => item.date.slice(0, 10) === key) };
    });
});
const monthLinks = computed(() => {
    const prev = new Date(monthDate.value.getFullYear(), monthDate.value.getMonth() - 1, 1);
    const next = new Date(monthDate.value.getFullYear(), monthDate.value.getMonth() + 1, 1);
    const fmt = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    return { previous: fmt(prev), next: fmt(next) };
});
const editingId = ref<number | null>(null);
const eventDialogOpen = ref(false);
const empty = () => ({ institution_id: '', location_id: '', name: '', event_date: '', venue: '', expected_quantity: '0', collection_date: '', delivery_date: '', notes: '', status: 'planned' });
const form = useForm(empty());
function dateInput(date: string | null): string { return date ? date.slice(0, 10) : ''; }
function startCreate(date: string): void {
    editingId.value = null;
    form.defaults(empty());
    form.reset();
    form.clearErrors();
    form.event_date = date;
    eventDialogOpen.value = true;
}
function startEdit(event: GraduationEvent): void {
    editingId.value = event.id;
    form.clearErrors();
    form.institution_id = String(event.institution_id); form.location_id = String(event.location_id); form.name = event.name;
    form.event_date = dateInput(event.event_date); form.venue = event.venue ?? ''; form.expected_quantity = String(event.expected_quantity);
    form.collection_date = dateInput(event.collection_date); form.delivery_date = dateInput(event.delivery_date); form.notes = event.notes ?? ''; form.status = event.status;
    eventDialogOpen.value = true;
}
function clearForm(): void { eventDialogOpen.value = false; editingId.value = null; form.defaults(empty()); form.reset(); form.clearErrors(); }
function save(): void {
    const options = { preserveScroll: true, onSuccess: clearForm };
    if (editingId.value) form.patch(`/events/${editingId.value}`, options);
    else form.post('/events', options);
}
function dateLabel(date: string): string { return new Date(`${date.slice(0, 10)}T12:00:00`).toLocaleDateString('en-KE', { dateStyle: 'medium' }); }
function calendarItemTone(item: CalendarItem): string {
    const status = item.status.toLowerCase();
    const type = item.type.toLowerCase();

    if (status === 'cancelled') return 'cancelled';
    if (['completed', 'returned'].includes(status)) return 'complete';
    if (type.includes('graduation')) return 'graduation';
    if (type.includes('collection') || type.includes('delivery')) return 'logistics';
    if (type.includes('return')) return 'return';
    if (type.includes('hire')) return 'hire';
    if (type.includes('custom order')) return 'custom';
    if (type.includes('fitting')) return 'fitting';

    return 'default';
}
function eventStatusTone(status: string): string {
    return ['planned', 'confirmed', 'completed', 'cancelled'].includes(status) ? status : 'planned';
}
</script>

<template>
    <Head title="Graduation events" />
    <AppShell active="events">
        <div class="page-heading"><div><h1>Graduation events</h1><p>Plan branch demand, expected gown quantities and collection or delivery windows.</p></div><Link href="/" class="secondary-link">Dashboard</Link></div>
        <section class="panel calendar-panel">
            <div class="calendar-heading"><div><div class="calendar-title-row"><span class="calendar-title-icon"><CalendarClock :size="18" aria-hidden="true" /></span><h2>Operational calendar</h2></div><p>Graduations, gown collections and deliveries, hire events and returns, fittings, and custom order deadlines.</p></div><div class="calendar-nav"><Link :href="`/events?month=${monthLinks.previous}`" preserve-scroll aria-label="Previous month"><ChevronLeft :size="17" aria-hidden="true" /></Link><strong>{{ monthTitle }}</strong><Link :href="`/events?month=${monthLinks.next}`" preserve-scroll aria-label="Next month"><ChevronRight :size="17" aria-hidden="true" /></Link></div></div>
            <div class="calendar-meta"><span>{{ calendar.items.length }} scheduled {{ calendar.items.length === 1 ? 'item' : 'items' }}</span><div class="calendar-legend" aria-label="Calendar event types"><span><i class="tone-graduation" />Graduation</span><span><i class="tone-logistics" />Collection / delivery</span><span><i class="tone-hire" />Hire / return</span><span><i class="tone-custom" />Order / fitting</span></div></div>
            <div class="calendar-scroll">
                <div class="calendar-grid calendar-weekdays"><span v-for="day in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="day">{{ day }}</span></div>
                <div class="calendar-grid calendar-days"><div v-for="day in calendarDays" :key="day.key" class="calendar-day" :class="{ muted: !day.inMonth, today: day.key === todayKey, 'can-add-event': canManage }"><button v-if="canManage" class="calendar-cell-action" type="button" :aria-label="`Add an event on ${dateLabel(day.key)}`" :title="`Add an event on ${dateLabel(day.key)}`" @click="startCreate(day.key)"/><span class="day-number">{{ day.number }}</span><div class="calendar-day-items"><div v-for="(item, index) in day.items.slice(0, 3)" :key="`${item.type}-${item.title}-${index}`" class="calendar-item" :class="`tone-${calendarItemTone(item)}`" :title="`${item.type}: ${item.title} · ${item.detail}`" role="group" :aria-label="`${item.type}: ${item.title}. ${item.detail}. Status: ${item.status}.`"><b>{{ item.time ? `${item.time} · ` : '' }}{{ item.type }}</b><span>{{ item.title }}</span></div><span v-if="day.items.length > 3" class="calendar-more">+{{ day.items.length - 3 }} more</span></div><span v-if="canManage" class="calendar-add-hint" aria-hidden="true">+ Add event</span></div></div>
            </div>
            <div v-if="calendar.items.length === 0" class="calendar-empty">No events or operational bookings in {{ monthTitle }}.</div>
        </section>
        <div v-if="canManage && eventDialogOpen" class="event-modal-backdrop" role="presentation" @click.self="clearForm" @keydown.esc="clearForm">
            <section class="event-modal" role="dialog" aria-modal="true" aria-labelledby="event-dialog-title">
                <header class="event-modal-heading"><div><h2 id="event-dialog-title">{{ editingId ? 'Update event' : 'Add an event' }}</h2><p>Plan gowns for a graduation, including branch and delivery dates.</p></div><button class="modal-close" type="button" aria-label="Close event form" @click="clearForm"><X :size="18" aria-hidden="true" /></button></header>
            <form class="event-form" @submit.prevent="save">
                <div class="form-field"><label for="event-name">Event name</label><input id="event-name" v-model="form.name" class="field" maxlength="180" required><small v-if="form.errors.name" class="form-error">{{ form.errors.name }}</small></div>
                <div class="form-field"><label for="event-institution">Institution</label><select id="event-institution" v-model="form.institution_id" class="select-field" required><option value="" disabled>Select institution</option><option v-for="institution in institutions" :key="institution.id" :value="institution.id">{{ institution.name }}</option></select><small v-if="form.errors.institution_id" class="form-error">{{ form.errors.institution_id }}</small></div>
                <div class="form-field"><label for="event-location">Branch</label><select id="event-location" v-model="form.location_id" class="select-field" required><option value="" disabled>Select branch</option><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small></div>
                <div class="form-field"><label for="event-date">Event date</label><input id="event-date" v-model="form.event_date" class="field" type="date" required><small v-if="form.errors.event_date" class="form-error">{{ form.errors.event_date }}</small></div>
                <div class="form-field"><label for="event-quantity">Expected gowns</label><input id="event-quantity" v-model="form.expected_quantity" class="field" type="number" min="0" max="1000000" required><small v-if="form.errors.expected_quantity" class="form-error">{{ form.errors.expected_quantity }}</small></div>
                <div class="form-field"><label for="event-venue">Venue</label><input id="event-venue" v-model="form.venue" class="field" maxlength="180"></div>
                <div class="form-field"><label for="event-collection">Collection date</label><input id="event-collection" v-model="form.collection_date" class="field" type="date"></div>
                <div class="form-field"><label for="event-delivery">Delivery date</label><input id="event-delivery" v-model="form.delivery_date" class="field" type="date"></div>
                <div class="form-field"><label for="event-status">Status</label><select id="event-status" v-model="form.status" class="select-field"><option value="planned">Planned</option><option value="confirmed">Confirmed</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
                <div class="form-field full"><label for="event-notes">Notes</label><textarea id="event-notes" v-model="form.notes" class="field" rows="2" maxlength="3000"></textarea></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="form.processing"><Save aria-hidden="true" /> {{ form.processing ? 'Saving…' : editingId ? 'Save changes' : 'Add event' }}</button><button v-if="editingId" class="secondary-link" type="button" @click="clearForm">Cancel</button></div>
            </form>
            </section>
        </div>
        <section class="panel table-panel">
            <div v-if="events.data.length" class="table-wrap"><table class="data-table"><thead><tr><th>Event</th><th>Institution</th><th>Date</th><th>Venue</th><th>Expected / booked</th><th>Branch</th><th>Collection / delivery</th><th>Status</th><th v-if="canManage">Manage</th></tr></thead><tbody><tr v-for="event in events.data" :key="event.id"><td><strong>{{ event.name }}</strong><small class="table-subline">Owner: {{ event.owner?.name ?? 'Unassigned' }}</small></td><td>{{ event.institution.name }}</td><td>{{ dateLabel(event.event_date) }}</td><td>{{ event.venue ?? '—' }}</td><td><strong>{{ event.booked_quantity.toLocaleString() }} / {{ event.expected_quantity.toLocaleString() }}</strong><small class="table-subline" :class="event.booked_quantity > event.expected_quantity ? 'demand-over' : ''">{{ event.booked_quantity > event.expected_quantity ? `${(event.booked_quantity - event.expected_quantity).toLocaleString()} above expected` : `${(event.expected_quantity - event.booked_quantity).toLocaleString()} remaining to expected demand` }}</small></td><td>{{ event.location.name }}</td><td>{{ event.collection_date ? dateLabel(event.collection_date) : '—' }} / {{ event.delivery_date ? dateLabel(event.delivery_date) : '—' }}</td><td><span class="event-status" :class="`event-status--${eventStatusTone(event.status)}`">{{ event.status.replaceAll('_', ' ') }}</span></td><td v-if="canManage"><button class="secondary-link compact-action" type="button" @click="startEdit(event)">Edit</button></td></tr></tbody></table></div>
            <div v-else class="empty-state"><div><CalendarClock aria-hidden="true" /><strong>No graduation events recorded</strong><span>Events you can access will appear here.</span></div></div>
            <Pagination v-if="events.total > 0" :links="events.links" :from="events.from" :to="events.to" :total="events.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.calendar-panel { margin-bottom: 16px; padding: 19px; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; }
.calendar-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:15px; }
.calendar-title-row { display:flex; align-items:center; gap:9px; }
.calendar-title-icon { display:grid; width:34px; height:34px; flex:none; place-items:center; border:1px solid #f0d9da; border-radius:9px; background:#fff7f7; color:#c8202f; }
.calendar-heading h2 { margin:0; color:#171c26; font-size:16px; font-weight:720; letter-spacing:-.02em; }
.calendar-heading p { max-width:670px; margin:7px 0 0 43px; color:#606874; font-size:11px; line-height:1.5; }
.calendar-nav { display:flex; align-items:center; gap:10px; color:#252b37; font-size:13px; }
.calendar-nav strong { min-width:128px; text-align:center; font-size:12px; font-weight:700; }
.calendar-nav a { display:grid; width:34px; height:34px; place-items:center; border:1px solid #e5e8ed; border-radius:9px; background:#fff; color:#c8202f; transition:background-color 150ms ease,border-color 150ms ease,transform 150ms ease; }
.calendar-nav a:hover { transform:translateY(-1px); border-color:#e6b9bc; background:#fff7f7; }
.calendar-nav a:focus-visible { outline:3px solid #f3b7ba; outline-offset:2px; }
.calendar-meta { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 10px; }
.calendar-meta > span { color:#68707c; font-size:10px; font-weight:650; white-space:nowrap; }
.calendar-legend { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:7px 14px; color:#515866; font-size:9px; }
.calendar-legend span { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.calendar-legend i { width:8px; height:8px; flex:none; border-radius:2px; background:var(--event-accent,#8993a3); box-shadow:0 0 0 2px color-mix(in srgb,var(--event-accent,#8993a3) 16%,transparent); }
.calendar-scroll { overflow-x:auto; overscroll-behavior-x:contain; }
.calendar-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); }
.calendar-weekdays { color:#68707c; font-size:9px; font-weight:750; letter-spacing:.08em; text-align:center; text-transform:uppercase; }
.calendar-weekdays span { padding:8px 3px; }
.calendar-days { border-top:1px solid #e8eaee; border-left:1px solid #e8eaee; border-radius:7px; overflow:hidden; }
.calendar-day { position:relative; min-width:0; min-height:112px; overflow:hidden; border-right:1px solid #e8eaee; border-bottom:1px solid #e8eaee; padding:7px; background:#fff; transition:background-color 150ms ease,box-shadow 150ms ease,transform 150ms ease; }
.calendar-day:nth-child(7n) { border-right:0; }
.calendar-day:nth-last-child(-n+7) { border-bottom:0; }
.calendar-day.muted { background:#fafbfc; color:#77808d; }
.calendar-day:hover,.calendar-day:focus-within { z-index:1; background:#fff8f8; box-shadow:inset 0 0 0 2px #e9aeb3,0 4px 12px rgb(37 48 65 / 10%); transform:translateY(-1px); }
.calendar-day.muted:hover,.calendar-day.muted:focus-within { background:#fffafa; }
.calendar-day.can-add-event { cursor:pointer; }
.calendar-cell-action { position:absolute; inset:0; z-index:0; width:100%; height:100%; border:0; padding:0; background:transparent; cursor:pointer; }
.calendar-cell-action:focus-visible { z-index:2; outline:3px solid #d42127; outline-offset:-4px; border-radius:4px; }
.calendar-day > .day-number,.calendar-day-items { position:relative; z-index:1; }
.calendar-day > .day-number { pointer-events:none; }
.calendar-day-items { pointer-events:none; }
.calendar-item,.calendar-more { pointer-events:auto; cursor:default; }
.calendar-add-hint { z-index:1; }
.calendar-add-hint { position:absolute; right:7px; bottom:6px; pointer-events:none; border:1px solid #f0d4d6; border-radius:999px; padding:3px 7px; background:#fff; color:#b91f2b; font-size:9px; font-weight:700; opacity:0; transform:translateY(3px); transition:opacity 140ms ease,transform 140ms ease; }
.calendar-day.can-add-event:hover .calendar-add-hint,.calendar-day.can-add-event:focus-within .calendar-add-hint { opacity:1; transform:translateY(0); }
.calendar-day.today { box-shadow:inset 0 0 0 1px #d42127; }
.calendar-day.today:hover,.calendar-day.today:focus-within { box-shadow:inset 0 0 0 2px #d42127,0 4px 12px rgb(37 48 65 / 10%); }
.day-number { display:grid; width:24px; height:24px; place-items:center; margin:0 0 5px; border-radius:50%; color:#424a57; font-size:11px; font-weight:700; font-variant-numeric:tabular-nums; }
.day-number-button { border:0; background:transparent; font-family:inherit; cursor:pointer; transition:background-color 140ms ease,color 140ms ease,transform 140ms ease; }
.day-number-button:hover { background:#fff0f2; color:#bd1f30; transform:scale(1.06); }
.day-number-button:focus-visible { outline:3px solid #f3b7ba; outline-offset:2px; }
.calendar-day.today .day-number { background:#d42127; color:#fff; }
.calendar-day.today .day-number-button:hover { background:#b81926; color:#fff; }
.calendar-day-items { display:grid; gap:4px; }
.calendar-item { min-width:0; overflow:hidden; border-left:3px solid var(--event-accent,#8993a3); border-radius:4px; padding:4px 5px 4px 6px; background:var(--event-soft,#f1f3f6); color:var(--event-text,#46505e); font-size:10px; line-height:1.3; transition:filter 120ms ease; }
.calendar-item:hover { filter:saturate(1.18); }
.calendar-item b,.calendar-item span { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.calendar-item b { margin-bottom:2px; color:var(--event-accent,#657182); font-size:7px; font-weight:750; letter-spacing:.035em; text-transform:uppercase; }
.calendar-item span { font-size:9px; font-weight:600; }
.tone-graduation { --event-accent:#cf3346; --event-soft:#fff0f2; --event-text:#812735; }
.tone-logistics { --event-accent:#2871c7; --event-soft:#edf5ff; --event-text:#234c79; }
.tone-hire { --event-accent:#6754c8; --event-soft:#f2efff; --event-text:#493f83; }
.tone-return { --event-accent:#c57a12; --event-soft:#fff5e4; --event-text:#765014; }
.tone-custom { --event-accent:#9b4db7; --event-soft:#f9effd; --event-text:#673779; }
.tone-fitting { --event-accent:#128879; --event-soft:#eaf8f5; --event-text:#1e635a; }
.tone-complete { --event-accent:#168251; --event-soft:#eaf7ef; --event-text:#205c3b; }
.tone-cancelled { --event-accent:#7b8491; --event-soft:#f0f2f4; --event-text:#505966; }
.tone-default { --event-accent:#687587; --event-soft:#f1f3f6; --event-text:#46505e; }
.calendar-more { color:#636b78; font-size:9px; font-weight:650; }
.calendar-empty { border:1px dashed #dfe3e8; border-radius:7px; margin-top:8px; padding:12px; color:#606874; font-size:11px; text-align:center; }
.event-modal-backdrop { position:fixed; inset:0; z-index:1200; display:grid; place-items:center; overflow-y:auto; padding:24px; background:rgb(17 24 39 / 58%); backdrop-filter:blur(3px); }
.event-modal { width:min(760px,100%); max-height:min(90vh,900px); overflow-y:auto; border:1px solid #e5e7eb; border-radius:16px; padding:22px; background:#fff; box-shadow:0 24px 80px rgb(0 0 0 / 24%); }
.event-modal-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:18px; }
.event-modal-heading h2 { margin:0; color:#171c26; font-size:17px; font-weight:750; letter-spacing:-.02em; }
.event-modal-heading p { margin:6px 0 0; color:#606874; font-size:11px; }
.modal-close { display:grid; width:34px; height:34px; flex:none; place-items:center; border:1px solid #e5e8ed; border-radius:9px; background:#fff; color:#505968; cursor:pointer; }
.modal-close:hover { border-color:#e6b9bc; background:#fff7f7; color:#c8202f; }
.modal-close:focus-visible { outline:3px solid #f3b7ba; outline-offset:2px; }
.event-form { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; align-items:end; }
.event-form .full,.event-form .form-actions { grid-column:1 / -1; }
.event-form .form-actions { justify-content:flex-start; margin-top:0; }
.table-panel { border-color:#e6e8eb; box-shadow:0 2px 8px rgb(20 28 40 / 4%); }
.table-panel .data-table th { color:#46505d; font-size:13px; letter-spacing:.025em; }
.table-panel .data-table td { vertical-align:middle; }
.table-panel .data-table tbody tr { transition:background-color 120ms ease; }
.table-panel .data-table tbody tr:hover { background:#fffafa; }
.table-panel .data-table td strong { color:#242b36; font-size:13px; }
.table-panel .table-subline { font-size:12px; line-height:1.45; }
.event-status { display:inline-flex; min-height:27px; align-items:center; border:1px solid transparent; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:700; line-height:1; text-transform:capitalize; white-space:nowrap; }
.event-status--planned { border-color:#dce5f1; background:#f0f5fb; color:#43617f; }
.event-status--confirmed { border-color:#bfdefc; background:#edf6ff; color:#2161a0; }
.event-status--completed { border-color:#c8e9d5; background:#ebf8ef; color:#197044; }
.event-status--cancelled { border-color:#e2e5e9; background:#f2f3f5; color:#636c78; }
.compact-action { min-height:31px; padding:5px 10px; }
.demand-over { color:#b4232d !important; font-weight:700; }
@media(max-width:900px) { .calendar-day { min-height:104px; padding:6px; }.calendar-legend { gap:6px 10px; } }
@media(max-width:800px) { .event-form { grid-template-columns:repeat(2,minmax(0,1fr)); }.calendar-meta { align-items:flex-start; flex-direction:column; }.calendar-legend { justify-content:flex-start; } }
@media(max-width:700px) { .calendar-scroll { margin:0 -2px; padding:0 2px 7px; }.calendar-grid { min-width:660px; }.calendar-day { min-height:100px; }.calendar-item { font-size:9px; } }
@media(max-width:600px) { .event-form { grid-template-columns:1fr; }.event-form .full,.event-form .form-actions { grid-column:auto; }.calendar-panel { padding:14px; }.event-modal-backdrop { align-items:end; padding:8px; }.event-modal { max-height:92vh; border-radius:16px 16px 10px 10px; padding:17px; }.calendar-heading { flex-direction:column; gap:12px; }.calendar-heading p { margin-left:0; }.calendar-nav { align-self:flex-end; }.calendar-legend { gap:7px 11px; }.calendar-day { min-height:94px; padding:5px; } }
@media(prefers-reduced-motion:reduce) { .calendar-day,.day-number-button,.calendar-add-hint { transition:none; }.calendar-day:hover,.calendar-day:focus-within,.day-number-button:hover,.calendar-add-hint { transform:none; } }
</style>
