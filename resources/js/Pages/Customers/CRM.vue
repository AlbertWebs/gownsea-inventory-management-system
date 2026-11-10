<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Save, UsersRound, X } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
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
const leadWizardSteps = [
    { label: 'Contact', description: 'Who should the team follow up with?' },
    { label: 'Organization', description: 'Choose the institution and branch responsible for this lead.' },
    { label: 'Opportunity', description: 'Set the pipeline stage and estimated value.' },
    { label: 'Next steps', description: 'Add a follow-up plan and any useful notes.' },
];
const animatedStages = reactive<Record<string, StageSummary>>(Object.fromEntries(stageOptions.map(({ id }) => [id, { count: props.stages[id]?.count ?? 0, value_minor: props.stages[id]?.value_minor ?? 0 }])));
const stageAnimations = new Map<string, number>();
const editingId = ref<number | null>(null);
const leadFormOpen = ref(false);
const activeLeadStep = ref(0);
const activityLead = ref<Lead | null>(null);
const draggingLeadId = ref<number | null>(null);
const dragOverStage = ref<string | null>(null);
const movingLeadId = ref<number | null>(null);
const empty = () => ({ location_id: '', customer_id: '', institution_id: '', contact_name: '', phone: '', email: '', source: 'direct', stage: 'new', estimated_value: '0.00', probability: '', next_action: '', follow_up_at: '', lost_reason: '', notes: '' });
const form = useForm(empty());
const activityForm = useForm({ activity_type: 'call', details: '', happened_at: '' });
const taskForm = useForm({ title: '', description: '', due_at: '', assigned_to: '' });
const leadsByStage = computed(() => Object.fromEntries(stageOptions.map((stage) => [stage.id, props.leads.data.filter((lead) => lead.stage === stage.id)])) as Record<string, Lead[]>);
const selectedStageLabel = computed(() => stageOptions.find((stage) => stage.id === form.stage)?.label ?? 'New lead');
watch(() => props.stages, (nextStages) => {
    for (const { id } of stageOptions) {
        const current = animatedStages[id] ?? { count: 0, value_minor: 0 };
        const startCount = current.count;
        const startValue = current.value_minor;
        const targetCount = nextStages[id]?.count ?? 0;
        const targetValue = nextStages[id]?.value_minor ?? 0;
        if (startCount === targetCount && startValue === targetValue) continue;
        const previousFrame = stageAnimations.get(id);
        if (previousFrame !== undefined) cancelAnimationFrame(previousFrame);
        const startedAt = performance.now();
        const duration = 850;
        const animate = (now: number): void => {
            const progress = Math.min((now - startedAt) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            animatedStages[id] = {
                count: Math.round(startCount + (targetCount - startCount) * eased),
                value_minor: Math.round(startValue + (targetValue - startValue) * eased),
            };
            if (progress < 1) stageAnimations.set(id, requestAnimationFrame(animate));
            else stageAnimations.delete(id);
        };
        stageAnimations.set(id, requestAnimationFrame(animate));
    }
}, { deep: true });
onBeforeUnmount(() => { for (const frame of stageAnimations.values()) cancelAnimationFrame(frame); });
const kes = (minor: number): string => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 0 }).format(minor / 100);
function dateTimeInput(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    const pad = (number: number): string => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
function beginEdit(lead: Lead): void {
    leadFormOpen.value = true;
    activeLeadStep.value = 0;
    editingId.value = lead.id; form.location_id = String(lead.location_id); form.customer_id = lead.customer_id ? String(lead.customer_id) : '';
    form.institution_id = lead.institution_id ? String(lead.institution_id) : ''; form.contact_name = lead.contact_name; form.phone = lead.phone ?? '';
    form.email = lead.email ?? ''; form.source = lead.source; form.stage = lead.stage; form.estimated_value = (lead.estimated_value_minor / 100).toFixed(2);
    form.probability = lead.probability === null ? '' : String(lead.probability); form.next_action = lead.next_action ?? '';
    form.follow_up_at = dateTimeInput(lead.follow_up_at); form.lost_reason = lead.lost_reason ?? ''; form.notes = lead.notes ?? '';
}
function openCreateLeadAtStage(stage = 'new'): void { editingId.value = null; activeLeadStep.value = 0; const values = empty(); values.stage = stage; form.reset(); form.defaults(values); form.stage = stage; leadFormOpen.value = true; }
function openCreateLead(): void { openCreateLeadAtStage('new'); }
function clearForm(): void { editingId.value = null; activeLeadStep.value = 0; leadFormOpen.value = false; form.reset(); form.defaults(empty()); }
function nextLeadStep(): void {
    const formElement = document.getElementById('lead-wizard-form') as HTMLFormElement | null;
    if (!formElement?.reportValidity()) return;
    activeLeadStep.value = Math.min(activeLeadStep.value + 1, leadWizardSteps.length - 1);
}
function previousLeadStep(): void { activeLeadStep.value = Math.max(activeLeadStep.value - 1, 0); }
function saveLead(): void {
    const options = {
        preserveScroll: true,
        onSuccess: clearForm,
        onError: (errors: Record<string, string>) => {
            const fields = Object.keys(errors);
            activeLeadStep.value = fields.some((field) => ['contact_name', 'phone', 'email', 'customer_id'].includes(field)) ? 0
                : fields.some((field) => ['institution_id', 'location_id', 'source'].includes(field)) ? 1
                    : fields.some((field) => ['stage', 'estimated_value', 'probability', 'lost_reason'].includes(field)) ? 2 : 3;
        },
    };
    if (editingId.value) form.patch(`/crm/leads/${editingId.value}`, options); else form.post('/crm/leads', options);
}
function moveLead(lead: Lead, stage: string): void {
    if (!props.canManage || lead.stage === stage || movingLeadId.value !== null) return;
    let lostReason: string | null = null;
    if (stage === 'lost') {
        lostReason = window.prompt(`Why was ${lead.contact_name} marked as lost? (At least 5 characters)`, lead.lost_reason ?? '')?.trim() ?? null;
        if (!lostReason || lostReason.length < 5) return;
    }
    movingLeadId.value = lead.id;
    router.patch(`/crm/leads/${lead.id}/stage`, { stage, lost_reason: lostReason }, { preserveScroll: true, onFinish: () => { movingLeadId.value = null; draggingLeadId.value = null; dragOverStage.value = null; } });
}
function dropLead(stage: string): void {
    const lead = props.leads.data.find((item) => item.id === draggingLeadId.value);
    if (lead) moveLead(lead, stage);
    dragOverStage.value = null;
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
    <Head title="CRM" />
    <AppShell active="crm">
        <div class="page-heading"><div><h1>Customer CRM</h1><p>Track institutional prospects, follow-ups and pipeline value by branch.</p></div><div class="heading-actions"><Link href="/quotations" class="secondary-link">Quotations</Link><Link href="/customers" class="secondary-link">Customer directory</Link><button v-if="canManage" class="primary-button add-lead-button" type="button" @click="openCreateLead"><Plus aria-hidden="true" /> Add a Lead</button></div></div>
        <div class="pipeline-summary"><article v-for="stage in stageOptions" :key="stage.id" class="panel pipeline-card" :class="`stage-${stage.id}`"><span>{{ stage.label }}</span><strong>{{ animatedStages[stage.id]?.count ?? 0 }}</strong><small>{{ kes(animatedStages[stage.id]?.value_minor ?? 0) }}</small></article></div>
        <div class="reminder-strip"><strong>Follow-up tasks</strong><span>{{ openTasks.length }} open</span><span :class="overdueTasks ? 'overdue-label' : ''">{{ overdueTasks }} overdue</span><small>Showing tasks attached to leads on this page.</small></div>
        <Transition name="lead-popup">
        <div v-if="canManage && leadFormOpen" class="lead-modal-backdrop" @click.self="clearForm">
        <section class="panel lead-form-panel lead-modal" role="dialog" aria-modal="true" aria-labelledby="lead-modal-title">
            <header class="form-title lead-dialog-heading"><div><span class="lead-dialog-eyebrow">{{ editingId ? 'Lead details' : 'New opportunity' }}</span><h2 id="lead-modal-title">{{ editingId ? 'Update lead' : 'Add a Lead' }}</h2><p>{{ editingId ? 'Update contact details, pipeline stage, and follow-up plan.' : `Create a lead and add it to ${selectedStageLabel}.` }}</p></div><button class="lead-dialog-close" type="button" aria-label="Close lead form" @click="clearForm"><X aria-hidden="true" /></button></header>
            <form id="lead-wizard-form" class="lead-form" @submit.prevent="saveLead">
                <p class="lead-form-required-note"><span aria-hidden="true">*</span> Required fields</p>
                <div class="lead-wizard-progress" aria-live="polite"><div><span>Step {{ activeLeadStep + 1 }} of {{ leadWizardSteps.length }}</span><strong>{{ leadWizardSteps[activeLeadStep].label }}</strong></div><div class="lead-wizard-track" role="progressbar" :aria-valuenow="activeLeadStep + 1" :aria-valuemin="1" :aria-valuemax="leadWizardSteps.length" :aria-label="`Step ${activeLeadStep + 1} of ${leadWizardSteps.length}`"><span :style="{ width: `${((activeLeadStep + 1) / leadWizardSteps.length) * 100}%` }"></span></div></div>
                <section v-if="activeLeadStep === 0" class="lead-form-section"><div class="lead-section-heading"><h3>Contact details</h3><p>{{ leadWizardSteps[0].description }}</p></div><div class="lead-form-grid">
                    <div class="form-field"><label for="lead-contact">Contact name <span class="required-mark">*</span></label><input id="lead-contact" v-model="form.contact_name" class="field" required maxlength="180" autocomplete="name" placeholder="Full name"><small v-if="form.errors.contact_name" class="form-error">{{ form.errors.contact_name }}</small></div>
                    <div class="form-field"><label for="lead-phone">Phone</label><input id="lead-phone" v-model="form.phone" class="field" type="tel" placeholder="0712 345 678" autocomplete="tel"><small v-if="form.errors.phone" class="form-error">{{ form.errors.phone }}</small></div>
                    <div class="form-field"><label for="lead-email">Email</label><input id="lead-email" v-model="form.email" class="field" type="email" autocomplete="email" placeholder="name@example.com"></div>
                    <div class="form-field"><label for="lead-customer">Existing customer</label><select id="lead-customer" v-model="form.customer_id" class="select-field"><option value="">Not linked</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option></select><small>Link this lead to an existing customer if available.</small></div>
                </div></section>
                <section v-if="activeLeadStep === 1" class="lead-form-section organization-section"><div class="lead-section-heading"><h3>Organization &amp; source</h3><p>{{ leadWizardSteps[1].description }}</p></div><div class="lead-form-grid">
                    <div class="form-field"><label for="lead-institution">Institution</label><select id="lead-institution" v-model="form.institution_id" class="select-field"><option value="">No institution selected</option><option v-for="institution in institutions" :key="institution.id" :value="institution.id">{{ institution.name }}</option></select></div>
                    <div class="form-field"><label for="lead-location">Branch <span class="required-mark">*</span></label><select id="lead-location" v-model="form.location_id" class="select-field" required><option value="" disabled>Select location</option><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small></div>
                    <div class="form-field"><label for="lead-source">Source</label><select id="lead-source" v-model="form.source" class="select-field"><option value="direct">Direct</option><option value="referral">Referral</option><option value="website">Website</option><option value="social">Social</option><option value="tender">Tender</option><option value="other">Other</option></select></div>
                </div></section>
                <section v-if="activeLeadStep === 2" class="lead-form-section opportunity-section"><div class="lead-section-heading"><h3>Opportunity</h3><p>{{ leadWizardSteps[2].description }}</p></div><div class="lead-form-grid">
                    <div class="form-field"><label for="lead-stage">Pipeline stage</label><select id="lead-stage" v-model="form.stage" class="select-field"><option v-for="stage in stageOptions" :key="stage.id" :value="stage.id">{{ stage.label }}</option></select></div>
                    <div class="form-field"><label for="lead-value">Estimated value (KES) <span class="required-mark">*</span></label><input id="lead-value" v-model="form.estimated_value" class="field" inputmode="decimal" required placeholder="0.00"><small v-if="form.errors.estimated_value" class="form-error">{{ form.errors.estimated_value }}</small></div>
                    <div class="form-field"><label for="lead-probability">Win probability (%)</label><input id="lead-probability" v-model="form.probability" class="field" type="number" min="0" max="100" placeholder="e.g. 50"></div>
                    <div v-if="form.stage === 'lost'" class="form-field"><label for="lead-lost-reason">Lost reason <span class="required-mark">*</span></label><input id="lead-lost-reason" v-model="form.lost_reason" class="field" minlength="5" maxlength="180" required placeholder="Why was this lead lost?"><small v-if="form.errors.lost_reason" class="form-error">{{ form.errors.lost_reason }}</small></div>
                </div></section>
                <section v-if="activeLeadStep === 3" class="lead-form-section"><div class="lead-section-heading"><h3>Next step</h3><p>{{ leadWizardSteps[3].description }}</p></div><div class="lead-form-grid">
                    <div class="form-field"><label for="lead-follow-up">Follow-up date &amp; time</label><input id="lead-follow-up" v-model="form.follow_up_at" class="field" type="datetime-local"></div>
                    <div class="form-field"><label for="lead-next-action">Next action</label><input id="lead-next-action" v-model="form.next_action" class="field" maxlength="180" placeholder="e.g. Call to confirm gown quantities"></div>
                    <div class="form-field lead-notes-field"><label for="lead-notes">Notes</label><textarea id="lead-notes" v-model="form.notes" class="field" rows="2" maxlength="3000" placeholder="Useful details for the team"></textarea></div>
                </div></section>
                <div class="lead-form-footer"><div class="wizard-footer-left"><button v-if="activeLeadStep > 0" class="secondary-link" type="button" :disabled="form.processing" @click="previousLeadStep">Back</button><button class="secondary-link wizard-cancel" type="button" :disabled="form.processing" @click="clearForm">Cancel</button></div><button v-if="activeLeadStep < leadWizardSteps.length - 1" class="primary-button" type="button" :disabled="form.processing" @click="nextLeadStep">Continue</button><button v-else class="primary-button" type="submit" :disabled="form.processing"><Save aria-hidden="true" /> {{ form.processing ? 'Saving…' : editingId ? 'Save lead' : 'Create lead' }}</button></div>
            </form>
        </section>
        </div>
        </Transition>
        <div v-if="activityLead && canManage" class="lead-modal-backdrop" @click.self="activityLead = null">
        <section class="panel lead-form-panel lead-modal activity-modal" role="dialog" aria-modal="true" aria-labelledby="activity-modal-title">
            <div class="form-title"><div><h2 id="activity-modal-title">Lead activity · {{ activityLead.contact_name }}</h2><p>Review conversations and follow-ups, or record a new interaction.</p></div><button class="secondary-link" type="button" @click="activityLead = null">Close</button></div>
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
        </div>
        <section class="panel board-panel">
            <div class="board-heading"><div><h2>Pipeline board</h2><p>Showing {{ leads.data.length }} of {{ leads.total }} branch accessible leads.</p></div></div>
            <p v-if="canManage" class="board-hint">Drag a lead card to another stage, or use its Move to menu.</p>
            <div class="lead-board" role="region" aria-label="Lead pipeline board" tabindex="0"><article v-for="stage in stageOptions" :key="stage.id" class="lead-lane" :class="[`stage-${stage.id}`, { 'drop-target': dragOverStage === stage.id }]" @dragover.prevent="canManage && (dragOverStage = stage.id)" @dragleave="dragOverStage = null" @drop.prevent="dropLead(stage.id)"><header><div class="lane-title"><strong>{{ stage.label }}</strong><span>{{ animatedStages[stage.id]?.count ?? 0 }} · {{ kes(animatedStages[stage.id]?.value_minor ?? 0) }}</span></div><button v-if="canManage" class="lane-add-button" type="button" :aria-label="`Add lead to ${stage.label}`" @click="openCreateLeadAtStage(stage.id)"><Plus aria-hidden="true" /></button></header><div v-for="lead in leadsByStage[stage.id]" :key="lead.id" class="lead-card-wrap" :class="{ 'is-dragging': draggingLeadId === lead.id, 'is-moving': movingLeadId === lead.id }"><button class="lead-card" type="button" :draggable="canManage" :aria-label="canManage ? `Drag ${lead.contact_name} to change stage, or click to edit` : `Edit ${lead.contact_name}`" @dragstart="draggingLeadId = lead.id" @dragend="{ draggingLeadId = null; dragOverStage = null }" @click="beginEdit(lead)"><strong>{{ lead.contact_name }}</strong><small>{{ lead.institution?.name ?? lead.customer?.name ?? 'New contact' }}</small><span>{{ kes(lead.estimated_value_minor) }}</span><small>{{ lead.next_action ?? 'No next action' }} · {{ followUpLabel(lead.follow_up_at) }}</small></button><label v-if="canManage" class="move-stage-control"><span>Move to</span><select :value="lead.stage" :disabled="movingLeadId !== null" :aria-label="`Move ${lead.contact_name} to another stage`" @click.stop @change="moveLead(lead, ($event.target as HTMLSelectElement).value)"><option v-for="option in stageOptions" :key="option.id" :value="option.id">{{ option.label }}</option></select></label><button v-if="canManage" class="convert-link" type="button" @click="openActivity(lead)">Activity &amp; follow-ups ({{ lead.activities.length }})</button><button v-if="lead.stage === 'won' && !lead.customer_id && canManage" class="convert-link" type="button" @click="convertLead(lead)">Convert to customer</button></div><p v-if="!leadsByStage[stage.id]?.length" class="lane-empty">Drop a lead here to move it to {{ stage.label.toLowerCase() }}.</p></article></div>
        </section>
        <section class="panel table-panel">
            <div v-if="leads.data.length" class="table-wrap"><table class="data-table"><thead><tr><th>Lead</th><th>Institution / customer</th><th>Branch / owner</th><th>Stage</th><th>Value</th><th>Next action</th><th>Follow-up</th><th v-if="canManage">Manage</th></tr></thead><tbody><tr v-for="lead in leads.data" :key="lead.id"><td><strong>{{ lead.contact_name }}</strong><small class="table-subline">{{ lead.reference }} · {{ lead.source }}</small></td><td>{{ lead.institution?.name ?? '—' }} / {{ lead.customer?.name ?? 'Unlinked' }}</td><td>{{ lead.location.name }} / {{ lead.owner.name }}</td><td><span class="stage-badge" :class="`stage-${lead.stage}`">{{ lead.stage.replaceAll('_', ' ') }}</span></td><td class="amount">{{ kes(lead.estimated_value_minor) }}</td><td>{{ lead.next_action ?? '—' }}</td><td>{{ followUpLabel(lead.follow_up_at) }}</td><td v-if="canManage"><div class="lead-manage-actions"><button class="secondary-link compact-action" type="button" @click="beginEdit(lead)">Edit</button><button class="conversation-action" type="button" @click="openActivity(lead)">Conversations</button><button v-if="lead.stage === 'won' && !lead.customer_id" class="convert-link" type="button" @click="convertLead(lead)">Convert</button></div></td></tr></tbody></table></div>
            <div v-else class="empty-state"><div><UsersRound aria-hidden="true" /><strong>No leads yet</strong><span>Add a lead to start tracking conversations and follow-ups.</span></div></div>
            <Pagination v-if="leads.total > 0" :links="leads.links" :from="leads.from" :to="leads.to" :total="leads.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.pipeline-summary { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 9px; margin-bottom: 12px; }
.stage-new { --stage-color: #7c3aed; --stage-tint: #f4efff; }
.stage-contacted { --stage-color: #0284c7; --stage-tint: #eaf7ff; }
.stage-quotation_sent { --stage-color: #d97706; --stage-tint: #fff6e8; }
.stage-negotiation { --stage-color: #d42127; --stage-tint: #fff0f0; }
.stage-won { --stage-color: #159454; --stage-tint: #eaf8f0; }
.stage-lost { --stage-color: #be3b59; --stage-tint: #fff0f3; }
.reminder-strip { display: flex; align-items: center; gap: 14px; margin: -4px 0 12px; padding: 10px 13px; border: 1px solid #eceef0; border-radius: 8px; background: #fff; color: #68707e; font-size: 13px; }.reminder-strip strong { color: #202632; }.reminder-strip small { margin-left: auto; color: #656c78; font-size: 12px; }
.pipeline-card { display: flex; flex-direction: column; gap: 6px; padding: 12px; border-top: 3px solid var(--stage-color); background: linear-gradient(180deg, var(--stage-tint), #fff 65%); }
.pipeline-card span { color: var(--stage-color); font-size: 13px; font-weight: 700; }.pipeline-card small { color: #4d5968; font-size: 13px; }
.pipeline-card strong { color: #161b25; font-size: 21px; }
.heading-actions { display: flex; align-items: center; justify-content: flex-end; gap: 12px; flex-wrap: wrap; }
.lead-form-panel,.board-panel { margin-bottom: 12px; padding: 17px; }
.lead-modal-backdrop { position: fixed; z-index: 1000; inset: 0; display: grid; place-items: center; overflow-y: auto; padding: 24px; background: rgb(17 24 39 / 48%); }
.lead-modal { width: min(1120px, 100%); max-height: min(90vh, 900px); overflow-y: auto; margin: auto; padding: 22px; box-shadow: 0 20px 60px rgb(0 0 0 / 22%); }
.lead-modal { scrollbar-width: none; -ms-overflow-style: none; }
.lead-modal::-webkit-scrollbar { display: none; }
.lead-dialog-heading { align-items: flex-start; margin: -4px 0 14px; border: 1px solid #f0d6d8; border-left: 4px solid #d42127; border-radius: 9px; background: linear-gradient(105deg, #fff5f5, #fff 76%); padding: 16px 18px; }
.lead-dialog-heading h2 { margin-top: 4px; font-size: 22px; }
.lead-dialog-heading p { margin-top: 5px; }
.lead-dialog-eyebrow { display: inline-flex; border-radius: 20px; background: #ffe7e8; padding: 4px 9px; color: #a91f28; font-size: 11px; font-weight: 750; letter-spacing: .06em; text-transform: uppercase; }
.lead-dialog-close { display: grid; width: 38px; height: 38px; flex: 0 0 38px; place-items: center; border: 1px solid #e1e3e6; border-radius: 8px; background: #fff; color: #4c5562; cursor: pointer; }.lead-dialog-close:hover { border-color: #efc1c5; background: #fff5f5; color: #b31f2b; }.lead-dialog-close :deep(svg) { width: 18px; height: 18px; }
.lead-modal > .lead-form { display: block; width: 100%; min-width: 0; }
.lead-form-required-note { margin: 0; color: #697382; font-size: 12px; }.required-mark,.lead-form-required-note span { color: #bd1d2a; font-weight: 750; }
.lead-wizard-progress { display: grid; gap: 8px; margin: 10px 0 2px; }.lead-wizard-progress > div:first-child { display: flex; align-items: baseline; gap: 8px; color: #68717e; font-size: 12px; }.lead-wizard-progress strong { color: #263143; font-size: 13px; }
.lead-wizard-track { height: 5px; overflow: hidden; border-radius: 99px; background: #eceff3; }.lead-wizard-track span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #d42127, #e1ac57); transition: width 220ms ease; }
.lead-form-section { display: block; width: 100%; box-sizing: border-box; padding: 16px 0; border-bottom: 1px solid #edf0f3; }
.lead-section-heading { margin-bottom: 13px; }.lead-section-heading h3 { margin: 0; color: #202632; font-size: 15px; font-weight: 750; }.lead-section-heading p { margin: 4px 0 0; color: #68717e; font-size: 12px; }
.lead-form-grid { display: grid; width: 100%; box-sizing: border-box; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px 18px; align-items: start; }
.organization-section .lead-form-grid,.opportunity-section .lead-form-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.lead-form-grid .lead-notes-field { grid-column: 1 / -1; }
.lead-form-footer { position: sticky; bottom: -22px; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0 -22px -22px; padding: 14px 22px; border-top: 1px solid #e9ebee; background: rgb(255 255 255 / 96%); backdrop-filter: blur(8px); }
.wizard-footer-left { display: flex; align-items: center; gap: 8px; }
.lead-form-footer button { min-width: 112px; }
.lead-popup-enter-active,.lead-popup-leave-active { transition: opacity 180ms ease; }
.lead-popup-enter-active .lead-modal,.lead-popup-leave-active .lead-modal { transition: transform 180ms ease, opacity 180ms ease; }
.lead-popup-enter-from,.lead-popup-leave-to { opacity: 0; }
.lead-popup-enter-from .lead-modal,.lead-popup-leave-to .lead-modal { transform: translateY(8px) scale(.98); opacity: 0; }
.activity-modal { width: min(760px, 100%); }
.add-lead-button { display: inline-flex; align-items: center; gap: 7px; }.add-lead-button :deep(svg) { width: 16px; height: 16px; }
.conversation-list { display: grid; gap: 8px; margin: 0 0 14px; padding: 0; list-style: none; }.conversation-list li { padding: 10px; border: 1px solid #e9ebee; border-radius: 7px; }.conversation-list strong,.conversation-list time { display: block; color: #596271; font-size: 12px; }.conversation-list p { margin: 6px 0 0; color: #202632; font-size: 13px; white-space: pre-wrap; }.conversation-form { margin-top: 12px; }
.subheading { margin: 12px 0 8px; color: #202632; font-size: 14px; }.task-list { display: grid; gap: 7px; margin: 0; padding: 0; list-style: none; }.task-list li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 11px; border: 1px solid #e9ebee; border-radius: 7px; }.task-list li.overdue { border-color: #f3c7c9; background: #fff8f8; }.task-list strong,.task-list small { display: block; font-size: 13px; }.task-list small,.task-list p { color: #606874; }.task-list p { margin: 4px 0 0; font-size: 12px; }.task-done { color: #13864e; font-size: 12px; font-weight: 700; }.task-form { margin: 10px 0 18px; }
.form-title,.board-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 13px; }
.form-title h2,.board-heading h2 { margin: 0; font-size: 16px; }
.form-title p,.board-heading p { margin: 4px 0 0; color: #656c78; font-size: 13px; }
.lead-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; align-items: end; }
.lead-form .full,.lead-form .form-actions { grid-column: 1 / -1; }
.lead-form .form-actions { justify-content: flex-start; margin-top: 0; }
.lead-board { display: grid; grid-template-columns: repeat(6, minmax(170px, 1fr)); gap: 9px; overflow-x: auto; padding-bottom: 4px; }
.lead-lane { min-height: 130px; padding: 9px; border: 1px solid #e9ebee; border-top: 3px solid var(--stage-color); border-radius: 8px; background: linear-gradient(180deg, var(--stage-tint), #f8f9fa 80px); }
.lead-lane.drop-target { border-color: #c61e25; background: #fff5f5; box-shadow: inset 0 0 0 1px #c61e25; }
.board-hint { margin: -5px 0 9px; color: #656c78; font-size: 13px; }
.lead-lane header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 8px; color: #3a4250; font-size: 13px; }
.lane-title { display: grid; gap: 3px; }.lane-title strong { color: var(--stage-color); }.lane-title span { width: fit-content; border-radius: 20px; background: var(--stage-tint); padding: 2px 7px; color: var(--stage-color); font-size: 12px; font-weight: 650; }
.stage-badge { display: inline-flex; border-radius: 20px; background: var(--stage-tint); padding: 4px 9px; color: var(--stage-color); font-size: 12px; font-weight: 700; text-transform: capitalize; }
.lead-manage-actions { display: flex; align-items: center; gap: 8px; min-width: 205px; flex-wrap: wrap; }
.lead-manage-actions .compact-action { min-height: 34px; padding: 5px 11px; white-space: nowrap; }
.conversation-action { display: inline-flex; min-height: 34px; align-items: center; justify-content: center; border: 1px solid #f0c9cc; border-radius: 7px; background: #fff7f7; padding: 5px 10px; color: #b91f2b; cursor: pointer; font: inherit; font-size: 12px; font-weight: 650; white-space: nowrap; }
.conversation-action:hover { border-color: #d42127; background: #fff0f0; }
.lane-add-button { display: grid; width: 25px; height: 25px; flex: 0 0 25px; place-items: center; border: 1px solid #e1e4e8; border-radius: 6px; background: #fff; color: #c61e25; cursor: pointer; }.lane-add-button:hover { border-color: #c61e25; background: #fff5f5; }.lane-add-button :deep(svg) { width: 15px; height: 15px; }
.lead-lane header span { color: #606874; }
.lead-card { width: 100%; display: flex; flex-direction: column; gap: 5px; margin-bottom: 7px; padding: 10px; border: 1px solid #e8eaed; border-radius: 7px; background: white; text-align: left; cursor: pointer; }
.lead-card-wrap { margin-bottom: 7px; padding: 8px; border: 1px solid #e8eaed; border-left: 3px solid var(--stage-color); border-radius: 7px; background: white; box-shadow: 0 1px 2px rgb(22 34 51 / 4%); }.lead-card-wrap:hover { border-color: color-mix(in srgb, var(--stage-color) 45%, #e8eaed); border-left-color: var(--stage-color); box-shadow: 0 3px 10px rgb(22 34 51 / 8%); }.lead-card-wrap .lead-card { border: 0; margin: 0; padding: 2px; }.lead-card-wrap .lead-card span { color: var(--stage-color); }.convert-link { margin: 5px 0 0; padding: 0; border: 0; background: transparent; color: #c61e25; font-size: 12px; font-weight: 700; cursor: pointer; }
.lead-card-wrap.is-dragging { opacity: .45; }.lead-card-wrap.is-moving { opacity: .6; pointer-events: none; }.lead-card[draggable="true"] { cursor: grab; }.lead-card[draggable="true"]:active { cursor: grabbing; }
.move-stage-control { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 7px; color: #656c78; font-size: 12px; }.move-stage-control select { max-width: 140px; min-height: 32px; padding: 4px 7px; border: 1px solid #d9dee5; border-radius: 5px; background: #fff; color: #27364a; font-size: 12px; }
.lead-card strong { color: #202632; font-size: 14px; }.lead-card span { color: #d42127; font-size: 13px; font-weight: 700; }.lead-card small,.lane-empty { color: #656c78; font-size: 12px; }
.compact-action { min-height: 29px; padding: 5px 9px; }
@media(max-width:900px) { .pipeline-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }.lead-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }.organization-section .lead-form-grid,.opportunity-section .lead-form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media(max-width:600px) { .pipeline-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }.lead-form { grid-template-columns: 1fr; }.lead-form .full,.lead-form .form-actions { grid-column: auto; }.lead-form-grid,.organization-section .lead-form-grid,.opportunity-section .lead-form-grid { grid-template-columns: 1fr; gap: 13px; }.lead-form-grid .lead-notes-field { grid-column: auto; }.lead-modal .lead-form-footer { bottom: -15px; margin: 0 -15px -15px; padding: 12px 15px; }.lead-form-footer button { flex: 1; min-width: 0; } }
@media(max-width:600px) { .reminder-strip { flex-wrap: wrap; gap: 8px; }.reminder-strip small { width: 100%; margin-left: 0; } }
@media(max-width:600px) { .lead-modal-backdrop { align-items: start; padding: 10px; }.lead-modal { max-height: calc(100vh - 20px); padding: 15px; }.page-heading .heading-actions { width: 100%; justify-content: flex-start; gap: 8px; }.page-heading .heading-actions > * { flex: 1 1 auto; } }
@media(prefers-reduced-motion: reduce) { .lead-popup-enter-active,.lead-popup-leave-active,.lead-popup-enter-active .lead-modal,.lead-popup-leave-active .lead-modal { transition-duration: .01ms; } }
</style>
