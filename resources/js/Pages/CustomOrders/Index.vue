<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ClipboardList, Pencil, Plus, Save, X } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type Option = { id: number; name: string };
type Order = {
    id: number; reference: string; customer: string; institution: string | null; location: string; owner: string | null;
    status: string; itemType: string; size: string | null; color: string | null; quantity: number; measurements: Record<string, string> | null;
    designSpecs: string | null; specialRequirements: string | null; agreedTotal: string; depositRequired: string; chargePaidMinor: number; chargeBalanceMinor: number;
    depositPaidMinor: number; depositBalanceMinor: number; orderDate: string; dueDate: string;
    overdue: boolean; priority: string; deliveryMethod: string; deliveryAddress: string | null; courier: string | null;
    trackingNumber: string | null; notes: string | null; timeline: { type: string; reason: string | null; at: string }[];
    tasks: { id: number; type: string; title: string; description: string | null; assignee: string | null; assignedTo: number | null; dueDate: string | null; status: string }[];
    fittings: { id: number; assignee: string | null; appointmentAt: string; status: string; measurements: Record<string, string> | null; outcome: string | null }[];
    shipmentEvents: { id: number; type: string; providerReference: string | null; details: string | null; occurredAt: string }[];
    costTotalMinor: number; contributionEstimateMinor: number; costs: { id: number; category: string; description: string; amountMinor: number; incurredOn: string }[];
};
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };

const props = defineProps<{ view: 'orders' | 'create'; orders: Page<Order>; locations: Option[]; customers: (Option & { institution_id: number | null; location_ids: number[] })[]; institutions: Option[]; staff: Option[]; canCreate: boolean; canEdit: boolean; isProduction: boolean }>();
const editing = ref<Order | null>(null);
const measurementRows = ref<{ name: string; value: string }[]>([]);
const statusOptions = ['new', 'quoted', 'in_production', 'ready_for_collection', 'dispatched', 'delivered', 'completed', 'cancelled'];
const visibleCustomers = computed(() => props.customers.filter((customer) => !form.location_id || customer.location_ids.includes(Number(form.location_id))));
const form = useForm({
    location_id: '', customer_id: '', institution_id: '', item_type: '', size: '', color: '', quantity: '1', measurements: null as Record<string, string> | null,
    design_specs: '', special_requirements: '', agreed_total: '0.00', deposit_required: '0.00', order_date: localDate(),
    expected_completion_date: '', priority: 'normal', delivery_method: 'collection', delivery_address: '', courier: '', tracking_number: '',
    notes: '', status: 'new', reason: '',
});
const taskForm = useForm({ task_type: 'sewing', title: '', description: '', assigned_to: '', due_date: '', status: 'todo', reason: '', order: '' });
const editingTaskId = ref<number | null>(null);
const fittingMeasurementRows = ref<{ name: string; value: string }[]>([]);
const editingFittingId = ref<number | null>(null);
const fittingScheduleForm = useForm({ appointment_at: '', assigned_to: '' });
const fittingUpdateForm = useForm({ status: 'completed', measurements: null as Record<string, string> | null, outcome: '' });
const shipmentForm = useForm({ event_type: 'picked_up', provider_reference: '', details: '', occurred_at: localDateTime(), idempotency_key: crypto.randomUUID() });
const costForm = useForm({ category: 'materials', description: '', amount: '', incurred_on: localDate(), idempotency_key: crypto.randomUUID() });
watch(() => props.orders.data, (orders) => {
    if (editing.value) editing.value = orders.find((order) => order.id === editing.value?.id) ?? editing.value;
});

function localDate(): string {
    const today = new Date();
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
}

function localDateTime(): string {
    const date = new Date();
    const pad = (number: number): string => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function resetForm(): void {
    editing.value = null;
    measurementRows.value = [];
    form.reset();
    form.clearErrors();
    form.order_date = localDate();
    form.quantity = '1';
    form.agreed_total = '0.00';
    form.deposit_required = '0.00';
    form.priority = 'normal';
    form.delivery_method = 'collection';
    form.status = 'new';
}

function rowsFromMeasurements(measurements: Record<string, string> | null): { name: string; value: string }[] {
    return measurements ? Object.entries(measurements).map(([name, value]) => ({ name, value })) : [];
}

function measurementsFromRows(rows: { name: string; value: string }[]): Record<string, string> | null {
    const filledRows = rows.filter((row) => row.name.trim() || row.value.trim());
    if (filledRows.some((row) => !row.name.trim() || !row.value.trim())) return null;
    return filledRows.length ? Object.fromEntries(filledRows.map((row) => [row.name.trim(), row.value.trim()])) : null;
}

async function beginEdit(order: Order): Promise<void> {
    editing.value = order;
    form.clearErrors();
    form.location_id = String(props.locations.find((location) => location.name === order.location)?.id ?? '');
    form.customer_id = String(props.customers.find((customer) => customer.name === order.customer)?.id ?? '');
    form.institution_id = String(props.institutions.find((institution) => institution.name === order.institution)?.id ?? '');
    form.item_type = order.itemType;
    form.size = order.size ?? '';
    form.color = order.color ?? '';
    form.quantity = String(order.quantity);
    measurementRows.value = rowsFromMeasurements(order.measurements);
    form.design_specs = order.designSpecs ?? '';
    form.special_requirements = order.specialRequirements ?? '';
    form.agreed_total = order.agreedTotal;
    form.deposit_required = order.depositRequired;
    form.order_date = order.orderDate;
    form.expected_completion_date = order.dueDate;
    form.priority = order.priority;
    form.delivery_method = order.deliveryMethod;
    form.delivery_address = order.deliveryAddress ?? '';
    form.courier = order.courier ?? '';
    form.tracking_number = order.trackingNumber ?? '';
    form.notes = order.notes ?? '';
    form.status = order.status;
    form.reason = '';
    await nextTick();
    document.querySelector('#custom-order-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.querySelector<HTMLInputElement>('#order-item')?.focus({ preventScroll: true });
}

function submit(): void {
    const measurements = measurementsFromRows(measurementRows.value);
    if (measurementRows.value.some((row) => row.name.trim() || row.value.trim()) && !measurements) {
        form.setError('measurements', 'Complete both the measurement name and value, or remove the empty row.');
        return;
    }

    form.clearErrors('measurements');
    form.transform((data) => ({ ...data, measurements, institution_id: data.institution_id || null, size: data.size || null, color: data.color || null, delivery_address: data.delivery_address || null, courier: data.courier || null, tracking_number: data.tracking_number || null, notes: data.notes || null }));
    if (editing.value) {
        form.patch(`/custom-orders/${editing.value.id}`, { preserveScroll: true, onSuccess: resetForm });
    } else {
        form.post('/custom-orders', { preserveScroll: true, onSuccess: resetForm });
    }
}

function startTaskEdit(task: Order['tasks'][number]): void {
    editingTaskId.value = task.id;
    taskForm.clearErrors();
    taskForm.task_type = task.type;
    taskForm.title = task.title;
    taskForm.description = task.description ?? '';
    taskForm.assigned_to = task.assignedTo ? String(task.assignedTo) : '';
    taskForm.due_date = task.dueDate ?? '';
    taskForm.status = task.status;
    taskForm.reason = '';
}

function resetTaskForm(): void {
    editingTaskId.value = null;
    taskForm.reset();
    taskForm.clearErrors();
    taskForm.task_type = 'sewing';
    taskForm.status = 'todo';
}

function submitTask(): void {
    if (!editing.value) return;
    taskForm.transform((data) => ({ ...data, assigned_to: data.assigned_to || null, due_date: data.due_date || null, description: data.description || null }));
    const options = { preserveScroll: true, onSuccess: resetTaskForm };
    if (editingTaskId.value) {
        taskForm.patch(`/custom-orders/${editing.value.id}/tasks/${editingTaskId.value}`, options);
    } else {
        taskForm.post(`/custom-orders/${editing.value.id}/tasks`, options);
    }
}

function startFittingEdit(fitting: Order['fittings'][number]): void {
    editingFittingId.value = fitting.id;
    fittingUpdateForm.clearErrors();
    fittingUpdateForm.status = 'completed';
    fittingUpdateForm.outcome = fitting.outcome ?? '';
    fittingMeasurementRows.value = rowsFromMeasurements(fitting.measurements);
}

function resetFittingEdit(): void {
    editingFittingId.value = null;
    fittingUpdateForm.reset();
    fittingUpdateForm.status = 'completed';
    fittingMeasurementRows.value = [];
}

function scheduleFitting(): void {
    if (!editing.value) return;
    fittingScheduleForm.transform((data) => ({ ...data, assigned_to: data.assigned_to || null }));
    fittingScheduleForm.post(`/custom-orders/${editing.value.id}/fittings`, { preserveScroll: true, onSuccess: () => { fittingScheduleForm.reset(); resetFittingEdit(); } });
}

function submitFittingUpdate(): void {
    if (!editing.value || !editingFittingId.value) return;
    const measurements = measurementsFromRows(fittingMeasurementRows.value);
    if (fittingMeasurementRows.value.some((row) => row.name.trim() || row.value.trim()) && !measurements) {
        fittingUpdateForm.setError('measurements', 'Complete both the measurement name and value, or remove the empty row.');
        return;
    }
    fittingUpdateForm.transform((data) => ({ ...data, measurements }));
    fittingUpdateForm.patch(`/custom-orders/${editing.value.id}/fittings/${editingFittingId.value}`, { preserveScroll: true, onSuccess: resetFittingEdit });
}

function recordShipmentEvent(): void {
    if (!editing.value) return;
    shipmentForm.transform((data) => ({ ...data, provider_reference: data.provider_reference || null, details: data.details || null }));
    shipmentForm.post(`/custom-orders/${editing.value.id}/shipment-events`, { preserveScroll: true, onSuccess: () => { shipmentForm.reset(); shipmentForm.event_type = 'picked_up'; shipmentForm.occurred_at = localDateTime(); shipmentForm.idempotency_key = crypto.randomUUID(); } });
}

function recordCost(): void {
    if (!editing.value) return;
    costForm.post(`/custom-orders/${editing.value.id}/costs`, { preserveScroll: true, onSuccess: () => { costForm.reset(); costForm.category = 'materials'; costForm.incurred_on = localDate(); costForm.idempotency_key = crypto.randomUUID(); } });
}

function kes(amount: string): string {
    return `KES ${Number(amount).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function kesMinor(amount: number): string {
    return `KES ${(amount / 100).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function dateLabel(value: string): string {
    return new Intl.DateTimeFormat('en-KE', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`));
}
</script>

<template>
    <Head :title="view === 'create' ? 'Create custom order' : 'Orders'" />
    <AppShell active="orders">
        <div class="page-heading"><div><h1>{{ view === 'create' ? 'Create custom order' : 'Orders' }}</h1><p>{{ view === 'create' ? 'Capture customer requirements, garment details, pricing, and delivery plans.' : 'Track made-to-order graduation wear from customer brief through collection or delivery.' }}</p></div><div class="heading-actions"><template v-if="view === 'orders'"><Link href="/crm" class="secondary-link">CRM pipeline</Link><Link href="/quotations" class="secondary-link">Quotations</Link><Link v-if="canCreate" href="/custom-orders/create" class="primary-link"><Plus aria-hidden="true" /> Create custom order</Link></template><Link v-else href="/custom-orders" class="secondary-link">Orders</Link></div></div>

        <section v-if="editing || (view === 'create' && canCreate)" id="custom-order-editor" class="panel order-editor">
            <div class="panel-heading"><div><h2>{{ editing ? `Update ${editing.reference}` : 'Create a custom order' }}</h2><p>{{ isProduction ? 'Production staff can update status and tracking after assignment.' : 'Record specifications, pricing, deposit requirement and delivery details.' }}</p></div><button v-if="editing" class="icon-button" aria-label="Close order form" type="button" @click="resetForm"><X aria-hidden="true" /></button></div>
            <div v-if="editing" class="order-update-notice"><ClipboardList aria-hidden="true" /><span>Changes are added to the order history. Enter a reason below before saving.</span></div>
            <form class="form-grid" @submit.prevent="submit">
                <div v-if="!editing" class="form-field"><label for="order-location">Branch</label><select id="order-location" v-model="form.location_id" class="select-field" required><option value="" disabled>Select branch</option><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select><small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small></div>
                <div v-if="!editing" class="form-field"><label for="order-customer">Customer</label><select id="order-customer" v-model="form.customer_id" class="select-field" required><option value="" disabled>Select customer</option><option v-for="customer in visibleCustomers" :key="customer.id" :value="String(customer.id)">{{ customer.name }}</option></select><small v-if="form.errors.customer_id" class="form-error">{{ form.errors.customer_id }}</small></div>
                <div class="form-field"><label for="order-institution">Institution</label><select id="order-institution" v-model="form.institution_id" class="select-field" :disabled="isProduction"><option value="">Use customer default</option><option v-for="institution in institutions" :key="institution.id" :value="String(institution.id)">{{ institution.name }}</option></select></div>
                <div class="form-field"><label for="order-item">Item type</label><input id="order-item" v-model="form.item_type" class="text-field" required maxlength="120" :disabled="isProduction"></div>
                <div class="form-field"><label for="order-size">Size</label><input id="order-size" v-model="form.size" class="text-field" maxlength="48" :disabled="isProduction"></div>
                <div class="form-field"><label for="order-color">Color</label><input id="order-color" v-model="form.color" class="text-field" maxlength="64" :disabled="isProduction"></div>
                <div class="form-field"><label for="order-quantity">Quantity</label><input id="order-quantity" v-model="form.quantity" class="text-field" type="number" min="1" required :disabled="isProduction"></div>
                <div class="form-field"><label for="order-total">Agreed total (KES)</label><input id="order-total" v-model="form.agreed_total" class="text-field" inputmode="decimal" required :disabled="isProduction"><small v-if="form.errors.agreed_total" class="form-error">{{ form.errors.agreed_total }}</small></div>
                <div class="form-field"><label for="order-deposit">Deposit required (KES)</label><input id="order-deposit" v-model="form.deposit_required" class="text-field" inputmode="decimal" :disabled="isProduction"><small v-if="form.errors.deposit_required" class="form-error">{{ form.errors.deposit_required }}</small></div>
                <div class="form-field"><label for="order-due">Expected completion</label><input id="order-due" v-model="form.expected_completion_date" class="text-field" type="date" required :disabled="isProduction"><small v-if="form.errors.expected_completion_date" class="form-error">{{ form.errors.expected_completion_date }}</small></div>
                <div class="form-field"><label for="order-priority">Priority</label><select id="order-priority" v-model="form.priority" class="select-field" :disabled="isProduction"><option v-for="priority in ['low','normal','high','urgent']" :key="priority" :value="priority">{{ priority }}</option></select></div>
                <div class="form-field"><label for="order-delivery">Delivery method</label><select id="order-delivery" v-model="form.delivery_method" class="select-field" :disabled="isProduction"><option value="collection">Customer collection</option><option value="courier">Courier delivery</option></select></div>
                <div v-if="editing" class="form-field"><label for="order-status">Status</label><select id="order-status" v-model="form.status" class="select-field"><option v-for="status in statusOptions" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option></select><small v-if="form.errors.status" class="form-error">{{ form.errors.status }}</small></div>
                <div v-if="editing" class="form-field full"><label for="order-reason">Reason for this update <span class="required-order-mark">*</span></label><input id="order-reason" v-model="form.reason" class="text-field" minlength="5" maxlength="1000" required placeholder="Explain the changes or status update"><small v-if="form.errors.reason" class="form-error">{{ form.errors.reason }}</small></div>
                <div class="form-field full"><label for="order-address">Delivery address</label><input id="order-address" v-model="form.delivery_address" class="text-field" maxlength="255" :disabled="isProduction"></div>
                <div v-if="editing" class="form-field"><label for="order-courier">Courier</label><input id="order-courier" v-model="form.courier" class="text-field" maxlength="120"></div>
                <div v-if="editing" class="form-field"><label for="order-tracking">Tracking number</label><input id="order-tracking" v-model="form.tracking_number" class="text-field" maxlength="120"></div>
                <div class="form-field full measurement-field"><div class="measurement-heading"><div><label>Garment measurements <span class="optional-label">Optional</span></label><small>Add measurements such as chest, waist, sleeve, or garment length. Include the unit with each value, for example 96 cm.</small></div><button class="secondary-link measurement-add" type="button" :disabled="isProduction" @click="measurementRows.push({ name: '', value: '' })"><Plus aria-hidden="true" /> Add measurement</button></div><div v-if="measurementRows.length" class="measurement-rows"><div v-for="(row, index) in measurementRows" :key="index" class="measurement-row"><div class="form-field"><label :for="`order-measure-name-${index}`">Measurement</label><input :id="`order-measure-name-${index}`" v-model="row.name" class="text-field" :disabled="isProduction" placeholder="e.g. Chest"></div><div class="form-field"><label :for="`order-measure-value-${index}`">Value and unit</label><input :id="`order-measure-value-${index}`" v-model="row.value" class="text-field" :disabled="isProduction" placeholder="e.g. 96 cm"></div><button class="measurement-remove" type="button" :disabled="isProduction" :aria-label="`Remove ${row.name || 'measurement'}`" @click="measurementRows.splice(index, 1)"><X aria-hidden="true" /></button></div></div><p v-else class="measurement-empty">No measurements added. You can add them when needed.</p><small v-if="form.errors.measurements" class="form-error">{{ form.errors.measurements }}</small></div>
                <div class="form-field full"><label for="order-specs">Design specifications</label><textarea id="order-specs" v-model="form.design_specs" class="textarea-field" rows="3" maxlength="5000" :disabled="isProduction"/><small v-if="form.errors.design_specs" class="form-error">{{ form.errors.design_specs }}</small></div>
                <div class="form-field full"><label for="order-requirements">Special requirements</label><textarea id="order-requirements" v-model="form.special_requirements" class="textarea-field" rows="2" maxlength="3000" :disabled="isProduction"/></div>
                <div class="form-field full"><label for="order-notes">Notes</label><textarea id="order-notes" v-model="form.notes" class="textarea-field" rows="2" maxlength="3000"/></div>
                <div v-if="form.errors.status" class="form-error full">{{ form.errors.status }}</div>
                <div class="form-actions full"><Link v-if="!editing" href="/custom-orders" class="secondary-link">Cancel</Link><button v-if="editing" type="button" class="secondary-link" @click="resetForm">Cancel</button><button type="submit" class="primary-button" :disabled="form.processing"><Save aria-hidden="true" />{{ form.processing ? 'Saving…' : editing ? 'Save order update' : 'Create order' }}</button></div>
            </form>
            <p v-if="!editing" class="workflow-note"><ClipboardList :size="16" aria-hidden="true" /> The deposit field records the required amount only. Record any receipt through Finance once custom order payment allocation is available.</p>
            <section v-if="editing" class="task-section">
                <div class="panel-heading"><div><h3>Production tasks and quality checks</h3><p>Assign work, due dates and status; each change is added to the order history.</p></div></div>
                <form class="form-grid task-form" @submit.prevent="submitTask">
                    <div class="form-field"><label for="task-type">Work type</label><select id="task-type" v-model="taskForm.task_type" class="select-field"><option v-for="type in ['measurement','cutting','sewing','embroidery','fitting','alteration','quality_check','packaging','other']" :key="type" :value="type">{{ type.replaceAll('_', ' ') }}</option></select></div>
                    <div class="form-field"><label for="task-title">Task</label><input id="task-title" v-model="taskForm.title" class="text-field" maxlength="160" required><small v-if="taskForm.errors.title" class="form-error">{{ taskForm.errors.title }}</small></div>
                    <div class="form-field"><label for="task-assignee">Assigned staff</label><select id="task-assignee" v-model="taskForm.assigned_to" class="select-field"><option value="">Unassigned</option><option v-for="member in staff" :key="member.id" :value="String(member.id)">{{ member.name }}</option></select><small v-if="taskForm.errors.assigned_to" class="form-error">{{ taskForm.errors.assigned_to }}</small></div>
                    <div class="form-field"><label for="task-due">Due date</label><input id="task-due" v-model="taskForm.due_date" class="text-field" type="date"><small v-if="taskForm.errors.due_date" class="form-error">{{ taskForm.errors.due_date }}</small></div>
                    <div v-if="editingTaskId" class="form-field"><label for="task-status">Task status</label><select id="task-status" v-model="taskForm.status" class="select-field"><option v-for="status in ['todo','in_progress','blocked','completed','cancelled']" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option></select><small v-if="taskForm.errors.status" class="form-error">{{ taskForm.errors.status }}</small></div>
                    <div class="form-field full"><label for="task-description">Work notes</label><textarea id="task-description" v-model="taskForm.description" class="textarea-field" rows="2" maxlength="3000"/></div>
                    <div v-if="editingTaskId" class="form-field full"><label for="task-reason">Reason for update</label><input id="task-reason" v-model="taskForm.reason" class="text-field" minlength="5" maxlength="1000" required><small v-if="taskForm.errors.reason" class="form-error">{{ taskForm.errors.reason }}</small></div>
                    <div v-if="taskForm.errors.order" class="form-error full">{{ taskForm.errors.order }}</div>
                    <div class="form-actions full"><button v-if="editingTaskId" type="button" class="secondary-link" @click="resetTaskForm">Cancel task edit</button><button type="submit" class="primary-button" :disabled="taskForm.processing"><Plus aria-hidden="true" />{{ editingTaskId ? 'Save task' : 'Add task' }}</button></div>
                </form>
                <div v-if="editing.tasks.length" class="task-list"><article v-for="task in editing.tasks" :key="task.id" class="task-card"><div><strong>{{ task.title }}</strong><small>{{ task.type.replaceAll('_', ' ') }} · {{ task.assignee ?? 'Unassigned' }} · {{ task.dueDate ? dateLabel(task.dueDate) : 'No due date' }}</small><p v-if="task.description">{{ task.description }}</p></div><div><span class="status-chip" :class="task.status === 'completed' ? 'completed' : task.status === 'blocked' ? 'overdue' : 'pending'">{{ task.status.replaceAll('_', ' ') }}</span><button v-if="!['completed','cancelled'].includes(task.status)" class="secondary-link compact-action" type="button" @click="startTaskEdit(task)">Update</button></div></article></div>
                <div v-else class="empty-tasks">No production tasks yet. Add measurement, cutting, fitting or quality check work above.</div>
                <div class="fitting-section">
                    <div class="panel-heading"><div><h3>Fitting appointments</h3><p>Record fitting attendance, outcome and updated garment measurements.</p></div></div>
                    <form class="form-grid task-form" @submit.prevent="scheduleFitting">
                        <div class="form-field"><label for="fitting-time">Appointment</label><input id="fitting-time" v-model="fittingScheduleForm.appointment_at" class="text-field" type="datetime-local" required><small v-if="fittingScheduleForm.errors.appointment_at" class="form-error">{{ fittingScheduleForm.errors.appointment_at }}</small></div>
                        <div class="form-field"><label for="fitting-assignee">Fitter</label><select id="fitting-assignee" v-model="fittingScheduleForm.assigned_to" class="select-field"><option value="">Unassigned</option><option v-for="member in staff" :key="member.id" :value="String(member.id)">{{ member.name }}</option></select><small v-if="fittingScheduleForm.errors.assigned_to" class="form-error">{{ fittingScheduleForm.errors.assigned_to }}</small></div>
                        <div class="form-actions"><button type="submit" class="primary-button" :disabled="fittingScheduleForm.processing"><Plus aria-hidden="true"/>Schedule fitting</button></div>
                    </form>
                    <div v-if="editing.fittings.length" class="task-list"><article v-for="fitting in editing.fittings" :key="fitting.id" class="task-card"><div><strong>{{ new Date(fitting.appointmentAt).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' }) }}</strong><small>{{ fitting.assignee ?? 'Unassigned fitter' }} · {{ fitting.status.replaceAll('_', ' ') }}</small><p v-if="fitting.outcome">{{ fitting.outcome }}</p><p v-if="fitting.measurements">Measurements: {{ Object.entries(fitting.measurements).map(([key, value]) => `${key}: ${value}`).join(' · ') }}</p></div><button v-if="fitting.status === 'scheduled'" class="secondary-link compact-action" type="button" @click="startFittingEdit(fitting)">Record outcome</button></article></div>
                    <div v-else class="empty-tasks">No fitting appointments scheduled.</div>
                    <form v-if="editingFittingId" class="form-grid task-form fitting-outcome-form" @submit.prevent="submitFittingUpdate">
                        <div class="form-field"><label for="fitting-status">Outcome</label><select id="fitting-status" v-model="fittingUpdateForm.status" class="select-field"><option value="completed">Completed</option><option value="no_show">Customer did not attend</option><option value="cancelled">Cancelled</option></select></div>
                        <div class="form-field full measurement-field"><div class="measurement-heading"><div><label>Updated garment measurements <span class="optional-label">Optional</span></label><small>Enter each updated measurement and its unit, such as 98 cm.</small></div><button class="secondary-link measurement-add" type="button" @click="fittingMeasurementRows.push({ name: '', value: '' })"><Plus aria-hidden="true" /> Add measurement</button></div><div v-if="fittingMeasurementRows.length" class="measurement-rows"><div v-for="(row, index) in fittingMeasurementRows" :key="index" class="measurement-row"><div class="form-field"><label :for="`fitting-measure-name-${index}`">Measurement</label><input :id="`fitting-measure-name-${index}`" v-model="row.name" class="text-field" placeholder="e.g. Chest"></div><div class="form-field"><label :for="`fitting-measure-value-${index}`">Value and unit</label><input :id="`fitting-measure-value-${index}`" v-model="row.value" class="text-field" placeholder="e.g. 98 cm"></div><button class="measurement-remove" type="button" :aria-label="`Remove ${row.name || 'measurement'}`" @click="fittingMeasurementRows.splice(index, 1)"><X aria-hidden="true" /></button></div></div><p v-else class="measurement-empty">No updated measurements added.</p><small v-if="fittingUpdateForm.errors.measurements" class="form-error">{{ fittingUpdateForm.errors.measurements }}</small></div>
                        <div class="form-field full"><label for="fitting-outcome">Fitting notes / alterations</label><textarea id="fitting-outcome" v-model="fittingUpdateForm.outcome" class="textarea-field" rows="2" maxlength="3000"/><small v-if="fittingUpdateForm.errors.outcome" class="form-error">{{ fittingUpdateForm.errors.outcome }}</small></div>
                        <div class="form-error full" v-if="fittingUpdateForm.errors.status">{{ fittingUpdateForm.errors.status }}</div>
                        <div class="form-actions full"><button type="button" class="secondary-link" @click="resetFittingEdit">Cancel</button><button type="submit" class="primary-button" :disabled="fittingUpdateForm.processing">Save fitting outcome</button></div>
                    </form>
                </div>
                <div v-if="['dispatched', 'delivered', 'completed'].includes(editing.status)" class="fitting-section">
                    <div class="panel-heading"><div><h3>Courier tracking events</h3><p>Record pickup, transit, delivery attempt, delay or exception updates against the active tracking number.</p></div></div>
                    <div v-if="editing.shipmentEvents.length" class="task-list shipment-list"><article v-for="event in editing.shipmentEvents" :key="event.id" class="task-card"><div><strong>{{ event.type.replaceAll('_', ' ') }}</strong><small>{{ new Date(event.occurredAt).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' }) }}{{ event.providerReference ? ` · ${event.providerReference}` : '' }}</small><p v-if="event.details">{{ event.details }}</p></div></article></div><div v-else class="empty-tasks">No courier updates recorded yet.</div>
                    <form class="form-grid task-form" @submit.prevent="recordShipmentEvent">
                        <div class="form-field"><label for="shipment-type">Shipment update</label><select id="shipment-type" v-model="shipmentForm.event_type" class="select-field"><option v-for="type in ['picked_up','in_transit','attempted_delivery','delivered','delayed','exception']" :key="type" :value="type">{{ type.replaceAll('_', ' ') }}</option></select></div>
                        <div class="form-field"><label for="shipment-at">Occurred at</label><input id="shipment-at" v-model="shipmentForm.occurred_at" class="text-field" type="datetime-local" required><small v-if="shipmentForm.errors.occurred_at" class="form-error">{{ shipmentForm.errors.occurred_at }}</small></div>
                        <div class="form-field"><label for="shipment-reference">Courier event reference</label><input id="shipment-reference" v-model="shipmentForm.provider_reference" class="text-field" maxlength="120"></div>
                        <div class="form-field full"><label for="shipment-details">Details</label><textarea id="shipment-details" v-model="shipmentForm.details" class="textarea-field" rows="2" maxlength="2000"/><small v-if="shipmentForm.errors.event_type" class="form-error">{{ shipmentForm.errors.event_type }}</small></div>
                        <div class="form-actions full"><button type="submit" class="primary-button" :disabled="shipmentForm.processing">{{ shipmentForm.processing ? 'Saving…' : 'Record tracking update' }}</button></div>
                    </form>
                </div>
                <div class="fitting-section">
                    <div class="panel-heading"><div><h3>Direct order costs</h3><p>Materials, production labor, delivery and other direct costs. The estimate below is agreed value less tracked costs; it is not a complete accounting profit.</p></div></div>
                    <div class="contribution-card"><span>Contribution estimate</span><strong>{{ kesMinor(editing.contributionEstimateMinor) }}</strong><small>Agreed total {{ kes(editing.agreedTotal) }} − direct costs {{ kesMinor(editing.costTotalMinor) }}</small></div>
                    <div v-if="editing.costs.length" class="task-list cost-list"><article v-for="cost in editing.costs" :key="cost.id" class="task-card"><div><strong>{{ cost.description }}</strong><small>{{ cost.category }} · {{ dateLabel(cost.incurredOn) }}</small></div><strong>{{ kesMinor(cost.amountMinor) }}</strong></article></div><div v-else class="empty-tasks">No direct costs recorded.</div>
                    <form class="form-grid task-form" @submit.prevent="recordCost">
                        <div class="form-field"><label for="order-cost-category">Category</label><select id="order-cost-category" v-model="costForm.category" class="select-field"><option value="materials">Materials</option><option value="labor">Production labor</option><option value="delivery">Delivery</option><option value="other">Other direct cost</option></select></div>
                        <div class="form-field"><label for="order-cost-description">Description</label><input id="order-cost-description" v-model="costForm.description" class="text-field" maxlength="180" required><small v-if="costForm.errors.description" class="form-error">{{ costForm.errors.description }}</small></div>
                        <div class="form-field"><label for="order-cost-amount">Amount (KES)</label><input id="order-cost-amount" v-model="costForm.amount" class="text-field" inputmode="decimal" required><small v-if="costForm.errors.amount" class="form-error">{{ costForm.errors.amount }}</small></div>
                        <div class="form-field"><label for="order-cost-date">Incurred on</label><input id="order-cost-date" v-model="costForm.incurred_on" class="text-field" type="date" required><small v-if="costForm.errors.incurred_on" class="form-error">{{ costForm.errors.incurred_on }}</small></div>
                        <div class="form-actions"><button type="submit" class="primary-button" :disabled="costForm.processing">{{ costForm.processing ? 'Saving…' : 'Record direct cost' }}</button></div>
                    </form>
                </div>
            </section>
        </section>

        <section v-if="view === 'orders'" class="panel">
            <div class="panel-heading"><div><h2>Order register</h2><p>{{ orders.total.toLocaleString() }} orders</p></div></div>
            <div v-if="orders.data.length" class="table-wrap"><table class="data-table orders-table"><thead><tr><th>Order</th><th>Customer / institution</th><th>Branch / owner</th><th>Item</th><th>Due</th><th>Amount</th><th>Status</th><th>Timeline</th><th v-if="canEdit">Action</th></tr></thead><tbody>
                <tr v-for="order in orders.data" :key="order.id"><td><strong>{{ order.reference }}</strong><small class="table-subline">{{ order.priority }} priority</small></td><td>{{ order.customer }}<small class="table-subline">{{ order.institution ?? 'No institution' }}</small></td><td>{{ order.location }}<small class="table-subline">{{ order.owner ?? 'Unassigned' }}</small></td><td>{{ order.quantity }} × {{ order.itemType }}<small class="table-subline">{{ [order.size, order.color].filter(Boolean).join(' · ') }}</small></td><td><span :class="order.overdue ? 'overdue-label' : ''">{{ dateLabel(order.dueDate) }}{{ order.overdue ? ' · overdue' : '' }}</span></td><td class="amount">{{ kes(order.agreedTotal) }}<small class="table-subline">Charge paid {{ kesMinor(order.chargePaidMinor) }} · due {{ kesMinor(order.chargeBalanceMinor) }}</small><small class="table-subline">Deposit held {{ kesMinor(order.depositPaidMinor) }} · due {{ kesMinor(order.depositBalanceMinor) }}</small><small class="table-subline">Contribution estimate {{ kesMinor(order.contributionEstimateMinor) }}</small></td><td><span class="status-chip" :class="order.status === 'completed' ? 'completed' : order.status === 'cancelled' ? 'damaged' : order.overdue ? 'overdue' : 'pending'">{{ order.status.replaceAll('_', ' ') }}</span><small v-if="order.courier || order.trackingNumber" class="table-subline">{{ [order.courier, order.trackingNumber].filter(Boolean).join(' · ') }}</small></td><td><details class="order-timeline"><summary>{{ order.timeline.length }} updates</summary><ol><li v-for="(event, index) in order.timeline" :key="`${event.type}-${index}`"><strong>{{ event.type.replaceAll('.', ' ') }}</strong><span>{{ event.reason ?? 'Order created' }} · {{ new Date(event.at).toLocaleString('en-KE') }}</span></li></ol></details></td><td v-if="canEdit"><button class="secondary-link compact-action update-order-button" type="button" @click="beginEdit(order)"><Pencil aria-hidden="true" /> Update</button></td></tr>
            </tbody></table></div>
            <div v-else class="empty-state"><div><ClipboardList aria-hidden="true"/><strong>No custom orders yet</strong><span>New made-to-order work will appear here.</span></div></div>
            <Pagination v-if="orders.total > 0" :links="orders.links" :from="orders.from" :to="orders.to" :total="orders.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.heading-actions { display:flex; align-items:center; justify-content:flex-end; gap:9px; flex-wrap:wrap; }
.order-editor { margin-bottom: 18px; }
.panel-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
.panel-heading h2 { margin:0; font-size:17px; }
.panel-heading p { margin:5px 0 0; color:#606874; font-size:13px; }
.order-update-notice { display:flex; align-items:center; gap:9px; margin:0 0 16px; padding:11px 13px; border:1px solid #f1c9cd; border-radius:8px; background:#fff5f5; color:#8f1824; font-size:13px; }
.order-update-notice :deep(svg) { width:17px; height:17px; flex:0 0 auto; }
.form-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
.form-field { display:grid; gap:6px; min-width:0; }
.form-field label { font-size:12px; font-weight:650; color:#333b49; }
.measurement-field { gap:10px; }
.measurement-heading { display:flex; justify-content:space-between; align-items:center; gap:16px; }
.measurement-heading > div { display:grid; gap:4px; }
.measurement-heading small,.measurement-empty { color:#606874; font-size:12px; }
.measurement-heading small { line-height:1.45; }
.optional-label { margin-left:5px; color:#737b86; font-size:11px; font-weight:500; }
.measurement-add { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.measurement-add svg { width:15px; height:15px; }
.measurement-rows { display:grid; gap:9px; }
.measurement-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) 36px; align-items:end; gap:10px; padding:11px; border:1px solid #e6e8eb; border-radius:8px; background:#fafbfc; }
.measurement-remove { width:36px; height:40px; display:grid; place-items:center; border:1px solid #e4c4c7; border-radius:7px; background:#fff; color:#a51d2a; cursor:pointer; }
.measurement-remove:hover { background:#fff0f1; }
.measurement-remove:disabled { opacity:.55; cursor:not-allowed; }
.measurement-remove svg { width:16px; height:16px; }
.measurement-empty { margin:0; padding:10px 12px; border:1px dashed #d9dde3; border-radius:7px; background:#fafbfc; }
.text-field,.select-field,.textarea-field { width:100%; min-height:40px; border:1px solid #dfe2e7; border-radius:7px; background:#fff; color:#171b24; padding:9px 11px; font:inherit; font-size:13px; }
.textarea-field { resize:vertical; }
.full { grid-column:1 / -1; }
.form-error { color:#c82333; font-size:12px; }
.form-actions { display:flex; justify-content:flex-end; gap:10px; }
.primary-button { display:inline-flex; align-items:center; gap:8px; border:0; border-radius:7px; padding:10px 15px; color:white; background:#c91f2e; font-weight:650; cursor:pointer; }
.primary-button:disabled { opacity:.6; cursor:wait; }
.workflow-note { display:flex; gap:8px; align-items:flex-start; margin:14px 0 0; padding:10px 12px; border-radius:7px; background:#fff8ec; color:#80561f; font-size:12px; }
.order-timeline summary { cursor:pointer; color:#9f1724; font-size:12px; white-space:nowrap; }
.order-timeline ol { position:absolute; z-index:2; width:min(340px,75vw); margin:6px 0; padding:12px 12px 12px 28px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 28px #10182820; }
.order-timeline li { margin:0 0 9px; font-size:11px; }
.order-timeline li:last-child { margin-bottom:0; }
.order-timeline li span { display:block; color:#606874; margin-top:2px; }
.overdue-label { color:#c82333; font-weight:650; }
.task-section { margin-top:22px; padding-top:20px; border-top:1px solid #eceef1; }
.fitting-section { margin-top:22px; padding-top:20px; border-top:1px solid #eceef1; }.fitting-outcome-form { padding:14px; border:1px solid #eceef1; border-radius:8px; background:#fafbfc; }
.contribution-card { display:grid; gap:5px; margin:0 0 10px; padding:12px; border:1px solid #e6e8eb; border-radius:8px; background:#fbfcfd; }.contribution-card span,.contribution-card small { color:#606874; font-size:11px; }.contribution-card strong { color:#171b24; font-size:20px; }.cost-list { margin-bottom:12px; }
.task-section h3 { margin:0; font-size:15px; }
.task-form { margin-bottom:18px; }
.task-list { display:grid; gap:9px; }
.task-card { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; border:1px solid #e6e8eb; border-radius:8px; padding:12px; }
.task-card > div:first-child { display:grid; gap:4px; }
.task-card small,.task-card p,.empty-tasks { margin:0; color:#606874; font-size:12px; }
.task-card p { margin-top:4px; }
.task-card > div:last-child { display:grid; justify-items:end; gap:5px; }
.empty-tasks { padding:12px; background:#f7f8fa; border-radius:7px; }
.table-subline { display:block; margin-top:4px; color:#596371; font-size:12px; line-height:1.4; }
.compact-action { margin-top:5px; padding:4px 7px; font-size:12px; }
.update-order-button { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:34px; margin:0; padding:7px 10px; border:1px solid #f0c6ca; border-radius:7px; background:#fff7f7; color:#a51d2a; font-size:13px; font-weight:650; white-space:nowrap; cursor:pointer; transition:background .15s ease,border-color .15s ease,transform .15s ease; }
.update-order-button:hover { border-color:#d72b38; background:#feecee; }
.update-order-button:focus-visible { outline:3px solid #d72b3840; outline-offset:2px; }
.update-order-button :deep(svg) { width:15px; height:15px; }
.orders-table th:last-child,.orders-table td:last-child { min-width:100px; }
@media (max-width:850px) { .form-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .table-wrap { overflow-x:auto; } }
@media (max-width:550px) { .form-grid { grid-template-columns:1fr; } .full { grid-column:auto; } .measurement-heading { align-items:flex-start; flex-direction:column; } .measurement-row { grid-template-columns:minmax(0,1fr) minmax(0,1fr) 36px; gap:7px; padding:8px; } }
</style>
