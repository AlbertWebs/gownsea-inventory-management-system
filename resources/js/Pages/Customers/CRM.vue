<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Save, UsersRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type Option = { id: number; name: string };
type Activity = { id: number; activity_type: string; details: string | null; happened_at: string; actor: Option | null };
type LeadTask = { id: number; title: string; description: string | null; due_at: string; completed_at: string | null; assignee: Option | null };
type Lead = { id: number; reference: string; location_id: number; contact_name: string; phone: string | null; email: string | null; institution_id: number | null; customer_id: number | null; source: string; stage: string; estimated_value_minor: number; probability: number | null; next_action: string | null; follow_up_at: string | null; lost_reason: string | null; notes: string | null; location: Option; owner: Option; customer: Option | null; institution: Option | null; activities: Activity[]; tasks: LeadTask[] };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
type StageSummary = { count: number; value_minor: number };
type StaffOption = Option & { locations: Option[] };
const props = defineProps<{ leads: Page<Lead>; locations: Option[]; institutions: Option[]; customers: Option[]; staff: StaffOption[]; stages: Record<string, StageSummary>; canManage: boolean }>();
const stageOptions = [
    { id: 'new', label: 'New lead' }, { id: 'contacted', label: 'Contacted' }, { id: 'quotation_sent', label: 'Quotation sent' },
    { id: 'negotiation', label: 'Negotiation' }, { id: 'won', label: 'Won' }, { id: 'lost', label: 'Lost' },
];
const editingId = ref<number | null>(null);
const activityLead = ref<Lead | null>(null);
const empty = () => ({ location_id: '', customer_id: '', institution_id: '', contact_name: '', phone: '', email: '', source: 'direct', stage: 'new', estimated_value: '0.00', probability: '', next_action: '', follow_up_at: '', lost_reason: '', notes: '' });
const form = useForm(empty());
const activityForm = useForm({ activity_type: 'call', details: '', happened_at: '' });
const taskForm = useForm({ title: '', description: '', due_at: '', assigned_to: '' });
const leadsByStage = computed(() => Object.fromEntries(stageOptions.map((stage) => [stage.id, props.leads.data.filter((lead) => lead.stage === stage.id)])) as Record<string, Lead[]>);
const kes = (minor: number): string => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 0 }).format(minor / 100);
function dateTimeInput(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    const pad = (number: number): string => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
function beginEdit(lead: Lead): void {
    editingId.value = lead.id; form.location_id = String(lead.location_id); form.customer_id = lead.customer_id ? String(lead.customer_id) : '';
    form.institution_id = lead.institution_id ? String(lead.institution_id) : ''; form.contact_name = lead.contact_name; form.phone = lead.phone ?? '';
    form.email = lead.email ?? ''; form.source = lead.source; form.stage = lead.stage; form.estimated_value = (lead.estimated_value_minor / 100).toFixed(2);
    form.probability = lead.probability === null ? '' : String(lead.probability); form.next_action = lead.next_action ?? '';
    form.follow_up_at = dateTimeInput(lead.follow_up_at); form.lost_reason = lead.lost_reason ?? ''; form.notes = lead.notes ?? '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function clearForm(): void { editingId.value = null; form.reset(); form.defaults(empty()); }
function saveLead(): void {
    const options = { preserveScroll: true, onSuccess: clearForm };
    if (editingId.value) form.patch(`/crm/leads/${editingId.value}`, options); else form.post('/crm/leads', options);
}
function convertLead(lead: Lead): void { router.post(`/crm/leads/${lead.id}/convert`, {}, { preserveScroll: true }); }
function openActivity(lead: Lead): void { activityLead.value = lead; activityForm.reset(); activityForm.activity_type = 'call'; activityForm.happened_at = ''; }
function recordActivity(): void {
    if (!activityLead.value) return;
    activityForm.post(`/crm/leads/${activityLead.value.id}/activities`, { preserveScroll: true, onSuccess: () => { activityForm.reset(); activityForm.activity_type = 'call'; activityForm.happened_at = ''; activityLead.value = null; router.reload({ only: ['leads'] }); } });
}
function createTask(): void {
    if (!activityLead.value) return;
    taskForm.post(`/crm/leads/${activityLead.value.id}/tasks`, { preserveScroll: true, onSuccess: () => { taskForm.reset(); activityLead.value = null; router.reload({ only: ['leads'] }); } });
}
function completeTask(task: LeadTask): void {
    if (!activityLead.value) return;
    router.post(`/crm/leads/${activityLead.value.id}/tasks/${task.id}/complete`, {}, { preserveScroll: true, onSuccess: () => { activityLead.value = null; router.reload({ only: ['leads'] }); } });
}
const openTasks = computed(() => props.leads.data.flatMap((lead) => lead.tasks.filter((task) => !task.completed_at)));
const overdueTasks = computed(() => openTasks.value.filter((task) => new Date(task.due_at).getTime() < Date.now()).length);
function followUpLabel(value: string | null): string {
    if (!value) return 'No follow-up';
    const date = new Date(value);
    return `${date.toLocaleDateString('en-KE', { dateStyle: 'medium' })} ${date.toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit' })}`;
}
</script>

<template>
    <Head title="Customers & CRM" />
    <AppShell active="customers">
        <div class="page-heading"><div><h1>Customers & CRM</h1><p>Track institutional prospects, follow-ups and pipeline value by branch.</p></div><div class="heading-actions"><Link href="/quotations" class="secondary-link">Quotations</Link><Link href="/customers" class="secondary-link">Customer directory</Link></div></div>
        <div class="pipeline-summary"><article v-for="stage in stageOptions" :key="stage.id" class="panel pipeline-card"><span>{{ stage.label }}</span><strong>{{ stages[stage.id]?.count ?? 0 }}</strong><small>{{ kes(stages[stage.id]?.value_minor ?? 0) }}</small></article></div>
        <div class="reminder-strip"><strong>Follow-up tasks</strong><span>{{ openTasks.length }} open</span><span :class="overdueTasks ? 'overdue-label' : ''">{{ overdueTasks }} overdue</span><small>Showing tasks attached to leads on this page.</small></div>
        <section v-if="canManage" class="panel lead-form-panel">
            <div class="form-title"><div><h2>{{ editingId ? 'Update lead' : 'Add a lead' }}</h2><p>Attach an existing customer when one is already in the directory.</p></div><button v-if="editingId" class="secondary-link" type="button" @click="clearForm">Cancel edit</button></div>
            <form class="lead-form" @submit.prevent="saveLead">
                <div class="form-field"><label for="lead-contact">Contact name</label><input id="lead-contact" v-model="form.contact_name" class="field" required maxlength="180"><small v-if="form.errors.contact_name" class="form-error">{{ form.errors.contact_name }}</small></div>
                <div class="form-field"><label for="lead-institution">Institution</label><select id="lead-institution" v-model="form.institution_id" class="select-field"><option value="">No institution selected</option><option v-for="institution in institutions" :key="institution.id" :value="institution.id">{{ institution.name }}</option></select></div>
                <div class="form-field"><label for="lead-customer">Existing customer</label><select id="lead-customer" v-model="form.customer_id" class="select-field"><option value="">Not linked</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option></select></div>
                <div class="form-field"><label for="lead-phone">Phone</label><input id="lead-phone" v-model="form.phone" class="field" type="tel" placeholder="0712 345 678"><small v-if="form.errors.phone" class="form-error">{{ form.errors.phone }}</small></div>
                <div class="form-field"><label for="lead-email">Email</label><input id="lead-email" v-model="form.email" class="field" type="email"></div>
                <div class="form-field"><label for="lead-location">Branch</label><select id="lead-location" v-model="form.location_id" class="select-field" required><option value="" disabled>Select location</option><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small></div>
                <div class="form-field"><label for="lead-source">Source</label><select id="lead-source" v-model="form.source" class="select-field"><option value="direct">Direct</option><option value="referral">Referral</option><option value="website">Website</option><option value="social">Social</option><option value="tender">Tender</option><option value="other">Other</option></select></div>
                <div class="form-field"><label for="lead-stage">Pipeline stage</label><select id="lead-stage" v-model="form.stage" class="select-field"><option v-for="stage in stageOptions" :key="stage.id" :value="stage.id">{{ stage.label }}</option></select></div>
                <div class="form-field"><label for="lead-value">Estimated value (KES)</label><input id="lead-value" v-model="form.estimated_value" class="field" inputmode="decimal" required><small v-if="form.errors.estimated_value" class="form-error">{{ form.errors.estimated_value }}</small></div>
                <div class="form-field"><label for="lead-probability">Win probability (%)</label><input id="lead-probability" v-model="form.probability" class="field" type="number" min="0" max="100"></div>
                <div class="form-field"><label for="lead-follow-up">Follow-up</label><input id="lead-follow-up" v-model="form.follow_up_at" class="field" type="datetime-local"></div>
                <div class="form-field"><label for="lead-next-action">Next action</label><input id="lead-next-action" v-model="form.next_action" class="field" maxlength="180"></div>
                <div v-if="form.stage === 'lost'" class="form-field"><label for="lead-lost-reason">Lost reason</label><input id="lead-lost-reason" v-model="form.lost_reason" class="field" minlength="5" maxlength="180" required><small v-if="form.errors.lost_reason" class="form-error">{{ form.errors.lost_reason }}</small></div>
                <div class="form-field full"><label for="lead-notes">Notes</label><textarea id="lead-notes" v-model="form.notes" class="field" rows="2" maxlength="3000"></textarea></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="form.processing"><Save aria-hidden="true" /> {{ form.processing ? 'Saving…' : editingId ? 'Save lead' : 'Add lead' }}</button></div>
            </form>
        </section>
        <section v-if="activityLead && canManage" class="panel lead-form-panel">
            <div class="form-title"><div><h2>Conversation history · {{ activityLead.contact_name }}</h2><p>Record calls, emails, meetings or notes. Entries are timestamped and retained in the lead history.</p></div><button class="secondary-link" type="button" @click="activityLead = null">Close</button></div>
            <h3 class="subheading">Follow-up reminders</h3>
            <ul v-if="activityLead.tasks.length" class="task-list"><li v-for="task in activityLead.tasks" :key="task.id" :class="{ overdue: !task.completed_at && new Date(task.due_at).getTime() < Date.now() }"><div><strong>{{ task.title }}</strong><small>{{ task.assignee?.name ?? 'Unassigned' }} · due {{ followUpLabel(task.due_at) }}</small><p v-if="task.description">{{ task.description }}</p></div><button v-if="!task.completed_at" class="secondary-link compact-action" type="button" @click="completeTask(task)">Complete</button><span v-else class="task-done">Completed</span></li></ul><p v-else class="lane-empty">No follow-up tasks scheduled.</p>
            <form class="lead-form task-form" @submit.prevent="createTask">
                <div class="form-field"><label for="lead-task-title">Task</label><input id="lead-task-title" v-model="taskForm.title" class="field" maxlength="180" required><small v-if="taskForm.errors.title" class="form-error">{{ taskForm.errors.title }}</small></div>
                <div class="form-field"><label for="lead-task-assignee">Assign to</label><select id="lead-task-assignee" v-model="taskForm.assigned_to" class="select-field"><option value="">Unassigned</option><option v-for="person in staff.filter((member) => member.locations.some((location) => location.id === activityLead?.location_id))" :key="person.id" :value="person.id">{{ person.name }}</option></select><small v-if="taskForm.errors.assigned_to" class="form-error">{{ taskForm.errors.assigned_to }}</small></div>
                <div class="form-field"><label for="lead-task-due">Due at</label><input id="lead-task-due" v-model="taskForm.due_at" class="field" type="datetime-local" required><small v-if="taskForm.errors.due_at" class="form-error">{{ taskForm.errors.due_at }}</small></div>
                <div class="form-field full"><label for="lead-task-description">Details</label><textarea id="lead-task-description" v-model="taskForm.description" class="field" rows="2" maxlength="3000"></textarea></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="taskForm.processing">{{ taskForm.processing ? 'Saving…' : 'Schedule follow-up' }}</button></div>
            </form>
            <ol v-if="activityLead.activities.length" class="conversation-list"><li v-for="activity in activityLead.activities" :key="activity.id"><strong>{{ activity.activity_type.replace('conversation.', '') }} · {{ activity.actor?.name ?? 'Staff' }}</strong><time>{{ followUpLabel(activity.happened_at) }}</time><p>{{ activity.details }}</p></li></ol><p v-else class="lane-empty">No conversation entries recorded for this lead yet.</p>
            <form class="lead-form conversation-form" @submit.prevent="recordActivity">
                <div class="form-field"><label for="conversation-type">Contact type</label><select id="conversation-type" v-model="activityForm.activity_type" class="select-field"><option value="call">Call</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="note">Note</option></select></div>
                <div class="form-field"><label for="conversation-happened">Occurred at</label><input id="conversation-happened" v-model="activityForm.happened_at" class="field" type="datetime-local"></div>
                <div class="form-field full"><label for="conversation-details">Summary</label><textarea id="conversation-details" v-model="activityForm.details" class="field" rows="3" minlength="2" maxlength="5000" required></textarea><small v-if="activityForm.errors.details" class="form-error">{{ activityForm.errors.details }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="activityForm.processing">{{ activityForm.processing ? 'Saving…' : 'Record conversation' }}</button></div>
            </form>
        </section>
        <section class="panel board-panel">
            <div class="board-heading"><div><h2>Pipeline board</h2><p>Showing {{ leads.data.length }} of {{ leads.total }} branch accessible leads.</p></div></div>
            <div class="lead-board" role="region" aria-label="Lead pipeline board" tabindex="0"><article v-for="stage in stageOptions" :key="stage.id" class="lead-lane"><header><strong>{{ stage.label }}</strong><span>{{ stages[stage.id]?.count ?? 0 }}</span></header><div v-for="lead in leadsByStage[stage.id]" :key="lead.id" class="lead-card-wrap"><button class="lead-card" type="button" @click="beginEdit(lead)"><strong>{{ lead.contact_name }}</strong><small>{{ lead.institution?.name ?? lead.customer?.name ?? 'New contact' }}</small><span>{{ kes(lead.estimated_value_minor) }}</span><small>{{ lead.next_action ?? 'No next action' }} · {{ followUpLabel(lead.follow_up_at) }}</small></button><button v-if="canManage" class="convert-link" type="button" @click="openActivity(lead)">Conversation ({{ lead.activities.length }})</button><button v-if="lead.stage === 'won' && !lead.customer_id && canManage" class="convert-link" type="button" @click="convertLead(lead)">Convert to customer</button></div><p v-if="!leadsByStage[stage.id]?.length" class="lane-empty">No leads in this page</p></article></div>
        </section>
        <section class="panel table-panel">
            <div v-if="leads.data.length" class="table-wrap"><table class="data-table"><thead><tr><th>Lead</th><th>Institution / customer</th><th>Branch / owner</th><th>Stage</th><th>Value</th><th>Next action</th><th>Follow-up</th><th v-if="canManage">Manage</th></tr></thead><tbody><tr v-for="lead in leads.data" :key="lead.id"><td><strong>{{ lead.contact_name }}</strong><small class="table-subline">{{ lead.reference }} · {{ lead.source }}</small></td><td>{{ lead.institution?.name ?? '—' }} / {{ lead.customer?.name ?? 'Unlinked' }}</td><td>{{ lead.location.name }} / {{ lead.owner.name }}</td><td>{{ lead.stage.replaceAll('_', ' ') }}</td><td class="amount">{{ kes(lead.estimated_value_minor) }}</td><td>{{ lead.next_action ?? '—' }}</td><td>{{ followUpLabel(lead.follow_up_at) }}</td><td v-if="canManage"><button class="secondary-link compact-action" type="button" @click="beginEdit(lead)">Edit</button><button class="convert-link" type="button" @click="openActivity(lead)">Conversations</button><button v-if="lead.stage === 'won' && !lead.customer_id" class="convert-link" type="button" @click="convertLead(lead)">Convert</button></td></tr></tbody></table></div>
            <div v-else class="empty-state"><div><UsersRound aria-hidden="true" /><strong>No leads yet</strong><span>Add a lead to start tracking conversations and follow-ups.</span></div></div>
            <Pagination v-if="leads.total > 0" :links="leads.links" :from="leads.from" :to="leads.to" :total="leads.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.pipeline-summary { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 9px; margin-bottom: 12px; }
.reminder-strip { display: flex; align-items: center; gap: 14px; margin: -4px 0 12px; padding: 10px 13px; border: 1px solid #eceef0; border-radius: 8px; background: #fff; color: #68707e; font-size: 11px; }.reminder-strip strong { color: #202632; }.reminder-strip small { margin-left: auto; color: #656c78; }
.pipeline-card { display: flex; flex-direction: column; gap: 6px; padding: 12px; }
.pipeline-card span,.pipeline-card small { color: #656c78; font-size: 10px; }
.pipeline-card strong { color: #161b25; font-size: 21px; }
.lead-form-panel,.board-panel { margin-bottom: 12px; padding: 17px; }
.conversation-list { display: grid; gap: 8px; margin: 0 0 14px; padding: 0; list-style: none; }.conversation-list li { padding: 10px; border: 1px solid #e9ebee; border-radius: 7px; }.conversation-list strong,.conversation-list time { display: block; color: #596271; font-size: 10px; }.conversation-list p { margin: 6px 0 0; color: #202632; font-size: 11px; white-space: pre-wrap; }.conversation-form { margin-top: 12px; }
.subheading { margin: 12px 0 8px; color: #202632; font-size: 12px; }.task-list { display: grid; gap: 7px; margin: 0; padding: 0; list-style: none; }.task-list li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 11px; border: 1px solid #e9ebee; border-radius: 7px; }.task-list li.overdue { border-color: #f3c7c9; background: #fff8f8; }.task-list strong,.task-list small { display: block; font-size: 10px; }.task-list small,.task-list p { color: #606874; }.task-list p { margin: 4px 0 0; font-size: 10px; }.task-done { color: #13864e; font-size: 10px; font-weight: 700; }.task-form { margin: 10px 0 18px; }
.form-title,.board-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 13px; }
.form-title h2,.board-heading h2 { margin: 0; font-size: 14px; }
.form-title p,.board-heading p { margin: 4px 0 0; color: #656c78; font-size: 10px; }
.lead-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; align-items: end; }
.lead-form .full,.lead-form .form-actions { grid-column: 1 / -1; }
.lead-form .form-actions { justify-content: flex-start; margin-top: 0; }
.lead-board { display: grid; grid-template-columns: repeat(6, minmax(170px, 1fr)); gap: 9px; overflow-x: auto; padding-bottom: 4px; }
.lead-lane { min-height: 130px; padding: 9px; border: 1px solid #e9ebee; border-radius: 8px; background: #f8f9fa; }
.lead-lane header { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 8px; color: #3a4250; font-size: 10px; }
.lead-lane header span { color: #606874; }
.lead-card { width: 100%; display: flex; flex-direction: column; gap: 5px; margin-bottom: 7px; padding: 10px; border: 1px solid #e8eaed; border-radius: 7px; background: white; text-align: left; cursor: pointer; }
.lead-card-wrap { margin-bottom: 7px; padding: 8px; border: 1px solid #e8eaed; border-radius: 7px; background: white; }.lead-card-wrap .lead-card { border: 0; margin: 0; padding: 2px; }.convert-link { margin: 5px 0 0; padding: 0; border: 0; background: transparent; color: #c61e25; font-size: 9px; font-weight: 700; cursor: pointer; }
.lead-card strong { color: #202632; font-size: 11px; }.lead-card span { color: #d42127; font-size: 11px; font-weight: 700; }.lead-card small,.lane-empty { color: #656c78; font-size: 9px; }
.compact-action { min-height: 29px; padding: 5px 9px; }
@media(max-width:900px) { .pipeline-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }.lead-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media(max-width:600px) { .pipeline-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }.lead-form { grid-template-columns: 1fr; }.lead-form .full,.lead-form .form-actions { grid-column: auto; } }
@media(max-width:600px) { .reminder-strip { flex-wrap: wrap; gap: 8px; }.reminder-strip small { width: 100%; margin-left: 0; } }
</style>
