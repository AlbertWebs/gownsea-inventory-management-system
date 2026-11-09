<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Landmark, Plus } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';

type Institution = { id: number; name: string; type: string; email: string | null; phone: string | null; address: string | null; county: string | null; is_active: boolean; customers_count: number; events_count: number };
defineProps<{ institutions: Institution[] }>();
const createForm = useForm({ name: '', type: 'university', email: '', phone: '', address: '', county: '', is_active: true });
const editingId = ref<number | null>(null);
const editForm = useForm({ name: '', type: 'university', email: '', phone: '', address: '', county: '', is_active: true });
function createInstitution(): void { createForm.post('/settings/institutions', { preserveScroll: true, onSuccess: () => createForm.reset() }); }
function beginEdit(institution: Institution): void {
    editingId.value = institution.id;
    editForm.name = institution.name;
    editForm.type = institution.type;
    editForm.email = institution.email ?? '';
    editForm.phone = institution.phone ?? '';
    editForm.address = institution.address ?? '';
    editForm.county = institution.county ?? '';
    editForm.is_active = institution.is_active;
}
function saveInstitution(): void {
    if (editingId.value !== null) editForm.patch(`/settings/institutions/${editingId.value}`, { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
}
</script>

<template>
    <Head title="Institutions" />
    <AppShell active="institutions">
        <div class="page-heading"><div><h1>Institutions</h1><p>Manage universities, colleges, TVETs and schools linked to customers and events.</p></div></div>
        <section class="panel institutions-panel">
            <div class="institutions-title"><Landmark aria-hidden="true" /><h2 class="panel-title">Add institution</h2></div>
            <form class="institution-form" @submit.prevent="createInstitution">
                <div class="form-field"><label for="institution-name">Institution name</label><input id="institution-name" v-model="createForm.name" class="field" maxlength="255" required></div>
                <div class="form-field"><label for="institution-type">Institution type</label><select id="institution-type" v-model="createForm.type" class="select-field"><option value="university">University</option><option value="college">College</option><option value="tvet">TVET</option><option value="school">School</option><option value="other">Other</option></select></div>
                <div class="form-field"><label for="institution-email">Email</label><input id="institution-email" v-model="createForm.email" class="field" type="email" maxlength="255"></div>
                <div class="form-field"><label for="institution-phone">Phone</label><input id="institution-phone" v-model="createForm.phone" class="field" type="tel" maxlength="32" placeholder="0712 345 678"></div>
                <div class="form-field"><label for="institution-county">County</label><input id="institution-county" v-model="createForm.county" class="field" maxlength="64"></div>
                <div class="form-field"><label for="institution-address">Address</label><input id="institution-address" v-model="createForm.address" class="field" maxlength="255"></div>
                <small v-for="(message, field) in createForm.errors" :key="field" class="form-error">{{ message }}</small>
                <div class="form-actions"><button class="primary-button" type="submit" :disabled="createForm.processing"><Plus aria-hidden="true" />{{ createForm.processing ? 'Saving…' : 'Add institution' }}</button></div>
            </form>
        </section>
        <section class="panel institutions-panel">
            <div class="institutions-title"><Landmark aria-hidden="true" /><div><h2 class="panel-title">Institution directory</h2><p class="panel-subtitle">{{ institutions.length }} institutions</p></div></div>
            <div v-if="institutions.length" class="table-wrap"><table class="data-table"><thead><tr><th>Institution</th><th>Type</th><th>County</th><th>Customers</th><th>Events</th><th>Status</th><th>Manage</th></tr></thead><tbody>
                <template v-for="institution in institutions" :key="institution.id"><tr><td><strong>{{ institution.name }}</strong><small class="table-subline">{{ institution.email ?? 'No email' }} · {{ institution.phone ?? 'No phone' }}</small></td><td>{{ institution.type }}</td><td>{{ institution.county ?? '—' }}</td><td>{{ institution.customers_count }}</td><td>{{ institution.events_count }}</td><td><span class="status-chip" :class="institution.is_active ? 'completed' : 'damaged'">{{ institution.is_active ? 'Active' : 'Inactive' }}</span></td><td><button class="secondary-link compact-action" type="button" @click="beginEdit(institution)">{{ editingId === institution.id ? 'Editing…' : 'Edit' }}</button></td></tr>
                    <tr v-if="editingId === institution.id"><td colspan="7"><form class="institution-edit-form" @submit.prevent="saveInstitution"><div class="form-field"><label :for="`edit-name-${institution.id}`">Name</label><input :id="`edit-name-${institution.id}`" v-model="editForm.name" class="field" maxlength="255" required></div><div class="form-field"><label :for="`edit-type-${institution.id}`">Type</label><select :id="`edit-type-${institution.id}`" v-model="editForm.type" class="select-field"><option value="university">University</option><option value="college">College</option><option value="tvet">TVET</option><option value="school">School</option><option value="other">Other</option></select></div><div class="form-field"><label :for="`edit-email-${institution.id}`">Email</label><input :id="`edit-email-${institution.id}`" v-model="editForm.email" class="field" type="email" maxlength="255"></div><div class="form-field"><label :for="`edit-phone-${institution.id}`">Phone</label><input :id="`edit-phone-${institution.id}`" v-model="editForm.phone" class="field" type="tel" maxlength="32"></div><div class="form-field"><label :for="`edit-county-${institution.id}`">County</label><input :id="`edit-county-${institution.id}`" v-model="editForm.county" class="field" maxlength="64"></div><div class="form-field"><label :for="`edit-address-${institution.id}`">Address</label><input :id="`edit-address-${institution.id}`" v-model="editForm.address" class="field" maxlength="255"></div><label class="location-active"><input v-model="editForm.is_active" type="checkbox"> Active for new records</label><small v-for="(message, field) in editForm.errors" :key="field" class="form-error">{{ message }}</small><div class="form-actions"><button class="secondary-link" type="button" @click="editingId = null">Cancel</button><button class="primary-button" type="submit" :disabled="editForm.processing">Save institution</button></div></form></td></tr>
                </template></tbody></table></div>
            <div v-else class="empty-state"><p>No institutions have been added yet.</p></div>
        </section>
    </AppShell>
</template>

<style scoped>
.institutions-panel { margin-bottom: 14px; }
.institutions-title { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.institutions-title > svg { width:20px; color:#d42127; }
.institutions-title .panel-subtitle { margin:3px 0 0; }
.institution-form,.institution-edit-form { display:grid; grid-template-columns:repeat(3,minmax(150px,1fr)); align-items:end; gap:12px; }
.institution-edit-form { padding:10px 0; }
.institution-form .form-actions,.institution-edit-form .form-actions { grid-column:1/-1; justify-content:flex-start; margin:0; }
.table-wrap { overflow-x:auto; }
@media(max-width:700px) { .institution-form,.institution-edit-form { grid-template-columns:1fr; }.institution-form .form-actions,.institution-edit-form .form-actions { grid-column:auto; } }
</style>
