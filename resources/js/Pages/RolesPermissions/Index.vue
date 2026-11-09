<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound, Plus, Save } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';

type Location = { id: number; name: string; is_active: boolean };
type Staff = { id: number; name: string; email: string; role: string; is_active: boolean; permissions: string[]; locations: { id: number; name: string; pivot: { is_primary: boolean } }[] };
const props = defineProps<{ locations: Location[]; staff: Staff[]; permissionOptions: Record<string, Record<string, string>>; actorRole: string }>();
const editingId = ref<number | null>(null);
const staffForm = useForm({ role: '', is_active: true, location_ids: [] as string[], primary_location_id: '', permissions: [] as string[] });
const createForm = useForm({ name: '', email: '', role: 'storekeeper', location_ids: [] as string[], primary_location_id: '' });
function beginEdit(staff: Staff): void {
    editingId.value = staff.id;
    staffForm.role = staff.role;
    staffForm.is_active = staff.is_active;
    staffForm.permissions = [...staff.permissions];
    staffForm.location_ids = staff.locations.filter((location) => props.locations.some((active) => active.id === location.id && active.is_active)).map((location) => String(location.id));
    staffForm.primary_location_id = String(staff.locations.find((location) => location.pivot.is_primary)?.id ?? staff.locations[0]?.id ?? '');
}
function saveStaff(): void {
    if (editingId.value === null) return;
    staffForm.transform((data) => ({ ...data, primary_location_id: data.primary_location_id || null }));
    staffForm.patch(`/settings/staff/${editingId.value}`, { preserveScroll: true, onSuccess: () => { editingId.value = null; staffForm.reset(); } });
}
function createStaff(): void {
    createForm.transform((data) => ({ ...data, primary_location_id: data.primary_location_id || null }));
    createForm.post('/settings/staff', { preserveScroll: true, onSuccess: () => createForm.reset() });
}
</script>

<template>
    <Head title="Roles & Permissions" />
    <AppShell active="roles-permissions">
        <div class="page-heading"><div><h1>Roles &amp; Permissions</h1><p>Manage staff accounts, role permissions and branch access.</p></div></div>
        <section class="panel roles-panel">
            <div class="section-title"><KeyRound aria-hidden="true" /><div><h2 class="panel-title">Create staff account</h2><p class="panel-subtitle">New accounts receive a one-time password setup link. Role and location changes are audited.</p></div></div>
            <form class="staff-create-form" @submit.prevent="createStaff">
                <div class="form-field"><label for="new-staff-name">Full name</label><input id="new-staff-name" v-model="createForm.name" class="field" required maxlength="160"><small v-if="createForm.errors.name" class="form-error">{{ createForm.errors.name }}</small></div>
                <div class="form-field"><label for="new-staff-email">Work email</label><input id="new-staff-email" v-model="createForm.email" class="field" type="email" required maxlength="255"><small v-if="createForm.errors.email" class="form-error">{{ createForm.errors.email }}</small></div>
                <div class="form-field"><label for="new-staff-role">Role</label><select id="new-staff-role" v-model="createForm.role" class="select-field"><option value="storekeeper">Storekeeper</option><option value="sales_crm">Sales / CRM</option><option value="finance">Finance</option><option value="production">Production</option><option v-if="actorRole === 'super_admin'" value="management">Management</option><option v-if="actorRole === 'super_admin'" value="super_admin">Super administrator</option></select><small v-if="createForm.errors.role" class="form-error">{{ createForm.errors.role }}</small></div>
                <div class="form-field"><label for="new-staff-locations">Assigned branches</label><select id="new-staff-locations" v-model="createForm.location_ids" class="select-field" multiple size="4"><option v-for="location in locations.filter((item) => item.is_active)" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select><small v-if="createForm.errors.location_ids" class="form-error">{{ createForm.errors.location_ids }}</small></div>
                <div class="form-field"><label for="new-staff-primary">Primary branch</label><select id="new-staff-primary" v-model="createForm.primary_location_id" class="select-field"><option value="">First selected</option><option v-for="id in createForm.location_ids" :key="id" :value="id">{{ locations.find((location) => String(location.id) === id)?.name ?? id }}</option></select><small v-if="createForm.errors.primary_location_id" class="form-error">{{ createForm.errors.primary_location_id }}</small></div>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="createForm.processing"><Plus aria-hidden="true" />{{ createForm.processing ? 'Creating…' : 'Create staff account' }}</button></div>
            </form>
        </section>
        <section class="panel roles-panel">
            <div class="section-title"><KeyRound aria-hidden="true" /><div><h2 class="panel-title">Staff accounts and branch access</h2><p class="panel-subtitle">{{ staff.length }} staff accounts</p></div></div>
            <div v-if="staff.length" class="table-wrap"><table class="data-table"><thead><tr><th>Staff member</th><th>Role</th><th>Assigned branches</th><th>Status</th><th>Manage</th></tr></thead><tbody>
                <template v-for="member in staff" :key="member.id"><tr><td><strong>{{ member.name }}</strong><small class="table-subline">{{ member.email }}</small></td><td>{{ member.role.replaceAll('_', ' ') }}</td><td>{{ member.locations.map((location) => location.name).join(', ') || 'Company-wide' }}</td><td><span class="status-chip" :class="member.is_active ? 'completed' : 'damaged'">{{ member.is_active ? 'Active' : 'Inactive' }}</span></td><td><button v-if="actorRole === 'super_admin' || !['super_admin','management'].includes(member.role)" class="secondary-link compact-action" type="button" @click="beginEdit(member)">{{ editingId === member.id ? 'Editing…' : 'Edit access' }}</button></td></tr>
                    <tr v-if="editingId === member.id"><td colspan="5"><form class="staff-edit-form" @submit.prevent="saveStaff">
                        <div class="form-field"><label :for="`staff-role-${member.id}`">Role</label><select :id="`staff-role-${member.id}`" v-model="staffForm.role" class="select-field" @change="staffForm.permissions = Object.keys(permissionOptions[staffForm.role] ?? {})"><option value="storekeeper">Storekeeper</option><option value="sales_crm">Sales / CRM</option><option value="finance">Finance</option><option value="production">Production</option><option v-if="actorRole === 'super_admin'" value="management">Management</option><option v-if="actorRole === 'super_admin'" value="super_admin">Super administrator</option></select><small v-if="staffForm.errors.role" class="form-error">{{ staffForm.errors.role }}</small></div>
                        <div class="form-field"><label :for="`staff-location-${member.id}`">Assigned branches</label><select :id="`staff-location-${member.id}`" v-model="staffForm.location_ids" class="select-field" multiple size="4"><option v-for="location in locations.filter((item) => item.is_active)" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select><small v-if="staffForm.errors.location_ids" class="form-error">{{ staffForm.errors.location_ids }}</small></div>
                        <div class="form-field"><label :for="`staff-primary-${member.id}`">Primary branch</label><select :id="`staff-primary-${member.id}`" v-model="staffForm.primary_location_id" class="select-field"><option value="">None</option><option v-for="id in staffForm.location_ids" :key="id" :value="id">{{ locations.find((location) => String(location.id) === id)?.name ?? id }}</option></select><small v-if="staffForm.errors.primary_location_id" class="form-error">{{ staffForm.errors.primary_location_id }}</small></div>
                        <label class="account-active"><input v-model="staffForm.is_active" type="checkbox"> Active account</label>
                        <fieldset class="permission-picker"><legend>Allowed actions for this role</legend><label v-for="(label, permission) in permissionOptions[staffForm.role] ?? {}" :key="permission"><input v-model="staffForm.permissions" type="checkbox" :value="permission">{{ label }}</label><small>Role and branch rules also apply. Administrators keep their default access.</small></fieldset>
                        <small v-for="(message, field) in staffForm.errors" :key="field" class="form-error">{{ message }}</small>
                        <div class="form-actions"><button type="button" class="secondary-link" @click="editingId = null">Cancel</button><button type="submit" class="primary-button" :disabled="staffForm.processing"><Save aria-hidden="true" />{{ staffForm.processing ? 'Saving…' : 'Save access' }}</button></div>
                    </form></td></tr>
                </template></tbody></table></div>
            <div v-else class="empty-state"><p>No staff accounts found.</p></div>
        </section>
    </AppShell>
</template>

<style scoped>
.roles-panel { margin-bottom: 14px; }
.section-title { display:flex; align-items:flex-start; gap:11px; margin-bottom:13px; }
.section-title > svg { width:20px; color:#d42127; }
.section-title p { margin:4px 0 0; }
.staff-create-form { display:grid; grid-template-columns:repeat(3,minmax(150px,1fr)); align-items:end; gap:12px; padding:12px; border:1px solid #eceef0; border-radius:8px; }
.staff-create-form select[multiple] { min-height:90px; }
.staff-create-form .form-actions { justify-content:flex-start; margin:0; }
.table-wrap { overflow-x:auto; }
.staff-edit-form { display:grid; grid-template-columns:repeat(3,minmax(150px,1fr)); align-items:end; gap:12px; padding:8px; }
.staff-edit-form select[multiple] { min-height:96px; }
.staff-edit-form .form-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; }
.permission-picker { grid-column:1/-1; display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:9px 16px; border:1px solid #e7e9ec; border-radius:8px; padding:14px; }
.permission-picker legend { padding:0 6px; font-weight:700; }
.permission-picker label { display:flex; align-items:flex-start; gap:8px; font-size:13px; }
.permission-picker small { grid-column:1/-1; color:#68707c; }
.account-active { display:flex; align-items:center; gap:7px; min-height:42px; font-size:11px; }
@media(max-width:700px) { .staff-create-form,.staff-edit-form { grid-template-columns:1fr; }.staff-create-form .form-actions { grid-column:auto; }.permission-picker { grid-template-columns:1fr; } }
</style>
