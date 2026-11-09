<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';

type Location = { id: number; name: string };
type Institution = { id: number; name: string; type: string };
const props = defineProps<{ locations: Location[]; institutions: Institution[]; defaultLocationId: number | null }>();
const form = useForm({ name: '', type: 'individual', phone: '', email: '', institution_id: '', location_id: props.defaultLocationId ? String(props.defaultLocationId) : String(props.locations[0]?.id ?? ''), source: 'direct', notes: '' });
function submit(): void { form.post('/customers'); }
</script>

<template>
    <Head title="New customer" />
    <AppShell active="customers">
        <div class="page-heading"><div><h1>New customer</h1><p>Create a customer profile and assign it to your location.</p></div><Link href="/customers" class="secondary-link"><ArrowLeft aria-hidden="true" /> Customers</Link></div>
        <div v-if="!locations.length" class="workflow-note">Your account has no active location assignment. Ask an administrator to assign a location before adding customer records.</div>
        <form v-else class="panel form-card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="form-field"><label for="name">Full name or organization</label><input id="name" v-model="form.name" class="field" required maxlength="255" autocomplete="name"><small v-if="form.errors.name" class="form-error">{{ form.errors.name }}</small></div>
                <div class="form-field"><label for="type">Customer type</label><select id="type" v-model="form.type" class="select-field"><option value="individual">Individual</option><option value="institution">Institution</option></select></div>
                <div class="form-field"><label for="phone">Kenyan phone number</label><input id="phone" v-model="form.phone" class="field" type="tel" placeholder="0712 345 678" autocomplete="tel"><small>Saved in international +254 format when provided.</small><small v-if="form.errors.phone" class="form-error">{{ form.errors.phone }}</small></div>
                <div class="form-field"><label for="email">Email</label><input id="email" v-model="form.email" class="field" type="email" autocomplete="email"><small v-if="form.errors.email" class="form-error">{{ form.errors.email }}</small></div>
                <div class="form-field"><label for="institution">Institution <span class="muted">(optional)</span></label><select id="institution" v-model="form.institution_id" class="select-field"><option value="">No institution linked</option><option v-for="institution in institutions" :key="institution.id" :value="institution.id">{{ institution.name }} · {{ institution.type }}</option></select><small v-if="form.errors.institution_id" class="form-error">{{ form.errors.institution_id }}</small></div>
                <div class="form-field"><label for="location">Assigned location</label><select id="location" v-model="form.location_id" class="select-field" required><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select><small v-if="form.errors.location_id" class="form-error">{{ form.errors.location_id }}</small></div>
                <div class="form-field"><label for="source">How did they find us?</label><select id="source" v-model="form.source" class="select-field"><option value="direct">Direct</option><option value="referral">Referral</option><option value="website">Website</option><option value="social">Social media</option><option value="tender">Tender</option><option value="other">Other</option></select></div>
                <div class="form-field full"><label for="notes">Notes <span class="muted">(optional)</span></label><textarea id="notes" v-model="form.notes" class="textarea-field" rows="3" maxlength="2000" /></div>
            </div>
            <div class="form-actions"><Link href="/customers" class="secondary-link">Cancel</Link><button class="primary-button" type="submit" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Create customer' }}</button></div>
        </form>
    </AppShell>
</template>
