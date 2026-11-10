<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, UsersRound, X } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type SmsPreferences = { transactional: boolean; reminders: boolean; payments: boolean; orders: boolean };
type Customer = { id: number; customer_number: string; type: string; name: string; phone: string | null; email: string | null; institution: { name: string } | null; hires_count: number; sms_consent: boolean; sms_preferences: SmsPreferences; created_at: string };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
const props = defineProps<{ customers: Page<Customer>; filters: { q: string }; canManageSmsConsent: boolean }>();
const query = ref(props.filters.q);
const pendingConsentUpdates = ref<number[]>([]);
const smsPreferenceCustomer = ref<Customer | null>(null);
const smsPreferenceDraft = ref<SmsPreferences>({ transactional: true, reminders: true, payments: true, orders: true });
const showingSearchResults = () => query.value.trim().length > 0;
let timer: ReturnType<typeof setTimeout> | undefined;
watch(query, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => router.get('/customers', { q: query.value || undefined }, { preserveState: true, preserveScroll: true, replace: true }), 240);
});
function updateSmsConsent(customer: Customer): void {
    if (pendingConsentUpdates.value.includes(customer.id)) return;
    pendingConsentUpdates.value.push(customer.id);
    router.patch(`/customers/${customer.id}/sms-consent`, { sms_consent: !customer.sms_consent }, {
        preserveScroll: true,
        onFinish: () => { pendingConsentUpdates.value = pendingConsentUpdates.value.filter((id) => id !== customer.id); },
    });
}
function clearSearch(): void { query.value = ''; }
function editSmsPreferences(customer: Customer): void { smsPreferenceCustomer.value = customer; smsPreferenceDraft.value = { ...customer.sms_preferences }; }
function saveSmsPreferences(): void {
    if (!smsPreferenceCustomer.value) return;
    router.patch(`/customers/${smsPreferenceCustomer.value.id}/sms-preferences`, { preferences: smsPreferenceDraft.value }, { preserveScroll: true, onSuccess: () => { smsPreferenceCustomer.value = null; } });
}
</script>

<template>
    <Head title="Customers" />
    <AppShell active="customers">
        <div class="page-heading"><div><h1>Customers</h1><p>Manage customer profiles, contact details, and gown hiring history.</p></div><div class="heading-actions"><Link href="/customers/create" class="primary-link"><Plus aria-hidden="true" /> New customer</Link><Link href="/hiring/issue" class="secondary-link">Issue gowns</Link></div></div>
        <div class="customer-toolbar"><label class="global-search customer-search"><Search aria-hidden="true" /><input v-model="query" type="search" placeholder="Search by name, number, phone, email, or institution" aria-label="Search customers"><button v-if="query" class="clear-customer-search" type="button" aria-label="Clear customer search" @click="clearSearch"><X aria-hidden="true" /></button></label><span class="customer-result-count" role="status">{{ showingSearchResults() ? `${customers.total} matching customers` : `${customers.total} customers` }}</span></div>
        <section class="panel table-panel customer-table-panel">
            <div class="customer-table-heading"><div><h2>Customer directory</h2><p v-if="customers.total">{{ customers.from }}–{{ customers.to }} of {{ customers.total }} {{ showingSearchResults() ? 'matches' : 'customers' }}</p><p v-else>Customer profiles appear here once they’ve been added.</p></div></div>
            <div v-if="customers.data.length" class="table-wrap">
                <table class="data-table customer-table"><thead><tr><th>Customer</th><th>Customer No.</th><th>Institution</th><th>Phone</th><th>Email</th><th>Hire Records</th><th>Type</th><th>SMS consent</th></tr></thead>
                    <tbody><tr v-for="customer in customers.data" :key="customer.id"><td data-label="Customer"><strong class="customer-name">{{ customer.name }}</strong></td><td data-label="Customer No."><span class="customer-number">{{ customer.customer_number }}</span></td><td data-label="Institution">{{ customer.institution?.name ?? '—' }}</td><td data-label="Phone">{{ customer.phone ?? '—' }}</td><td data-label="Email">{{ customer.email ?? '—' }}</td><td data-label="Hire records"><span class="hire-count">{{ customer.hires_count }} <span>{{ customer.hires_count === 1 ? 'hire' : 'hires' }}</span></span></td><td data-label="Type"><span class="customer-type" :class="customer.type">{{ customer.type }}</span></td><td data-label="SMS consent"><div class="sms-consent-cell"><span class="sms-status" :class="customer.sms_consent ? 'has-consent' : 'no-consent'"><span class="sms-status-dot" aria-hidden="true"></span>{{ customer.sms_consent ? 'Consent recorded' : 'No consent' }}</span><button v-if="canManageSmsConsent" type="button" class="sms-consent-button" :class="customer.sms_consent ? 'withdraw-consent' : 'record-consent'" :disabled="pendingConsentUpdates.includes(customer.id)" :aria-label="`${customer.sms_consent ? 'Withdraw' : 'Record'} SMS consent for ${customer.name}`" @click="updateSmsConsent(customer)">{{ pendingConsentUpdates.includes(customer.id) ? 'Saving…' : customer.sms_consent ? 'Withdraw' : 'Record consent' }}</button><button v-if="canManageSmsConsent" type="button" class="sms-consent-button preference-button" @click="editSmsPreferences(customer)">Preferences</button></div></td></tr></tbody>
                </table>
            </div>
            <div v-else class="empty-state"><div><UsersRound aria-hidden="true" /><strong>{{ showingSearchResults() ? 'No customers match this search' : 'No customer records yet' }}</strong><span>{{ showingSearchResults() ? 'Try a different name, number, phone, email, or institution.' : 'Create a customer profile to start tracking contact details and gown hire history.' }}</span><button v-if="showingSearchResults()" class="secondary-link empty-clear-search" type="button" @click="clearSearch">Clear search</button><Link v-else href="/customers/create" class="primary-link empty-create-customer"><Plus aria-hidden="true" /> New customer</Link></div></div>
        <div v-if="smsPreferenceCustomer" class="sms-preference-overlay" role="presentation" @click.self="smsPreferenceCustomer = null">
            <section class="sms-preference-dialog" role="dialog" aria-modal="true" aria-labelledby="sms-preferences-title">
                <button type="button" class="sms-preference-close" aria-label="Close" @click="smsPreferenceCustomer = null"><X /></button>
                <h2 id="sms-preferences-title">SMS preferences</h2>
                <p>Choose which updates {{ smsPreferenceCustomer.name }} can receive. SMS consent must still be recorded separately.</p>
                <label><input v-model="smsPreferenceDraft.transactional" type="checkbox"> Important account and service updates</label>
                <label><input v-model="smsPreferenceDraft.reminders" type="checkbox"> Return and appointment reminders</label>
                <label><input v-model="smsPreferenceDraft.payments" type="checkbox"> Payment and balance updates</label>
                <label><input v-model="smsPreferenceDraft.orders" type="checkbox"> Custom order and delivery updates</label>
                <div class="sms-preference-actions"><button type="button" class="secondary-link" @click="smsPreferenceCustomer = null">Cancel</button><button type="button" class="primary-link" @click="saveSmsPreferences">Save preferences</button></div>
            </section>
        </div>
            <Pagination v-if="customers.total > 0" :links="customers.links" :from="customers.from" :to="customers.to" :total="customers.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.heading-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.customer-toolbar { display: flex; align-items: center; gap: 12px; margin: 0 0 12px; }
.customer-search { width: min(520px, 100%); }
.clear-customer-search { display: grid; width: 28px; height: 28px; flex: 0 0 28px; place-items: center; border: 0; border-radius: 6px; background: transparent; color: #68717e; cursor: pointer; }.clear-customer-search:hover { background: #f1f3f5; color: #b31f2b; }.clear-customer-search svg { width: 16px; height: 16px; }
.customer-result-count { border-radius: 20px; background: #f0f2f5; padding: 6px 10px; color: #525d6b; font-size: 12px; font-weight: 650; white-space: nowrap; }
.customer-table-panel { overflow: hidden; }
.customer-table-heading { display: flex; align-items: center; justify-content: space-between; padding: 15px 18px 12px; }
.customer-table-heading h2 { margin: 0; color: #202632; font-size: 15px; font-weight: 750; }
.customer-table-heading p { margin: 4px 0 0; color: #68717e; font-size: 12px; }
.customer-table { min-width: 980px; }
.customer-name { color: #202632; font-size: 13px; font-weight: 700; }
.customer-number { color: #485363; font-size: 12px; font-variant-numeric: tabular-nums; }
.hire-count { display: inline-flex; min-width: 34px; align-items: center; gap: 5px; border-radius: 20px; background: #eff6ff; padding: 5px 9px; color: #1d5d9a; font-size: 12px; font-weight: 750; }.hire-count span { color: #506985; font-weight: 500; }
.customer-type { display: inline-flex; border-radius: 20px; background: #f0edff; padding: 5px 9px; color: #5b46a0; font-size: 12px; font-weight: 650; text-transform: capitalize; }.customer-type.institution { background: #e8f5f3; color: #14756a; }
.sms-consent-cell { display: grid; justify-items: start; gap: 7px; min-width: 126px; }
.sms-status { display: inline-flex; align-items: center; gap: 6px; color: #566170; font-size: 12px; font-weight: 650; white-space: nowrap; }
.sms-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #969eaa; }.sms-status.has-consent { color: #18734b; }.sms-status.has-consent .sms-status-dot { background: #1e9b60; }.sms-status.no-consent .sms-status-dot { background: #a7adb6; }
.sms-consent-button { min-height: 30px; border: 1px solid #d8dce2; border-radius: 7px; background: #fff; color: #485261; padding: 5px 9px; font: inherit; font-size: 12px; font-weight: 650; cursor: pointer; }
.sms-consent-button.record-consent { border-color: #b9e2cb; background: #f1faf5; color: #167448; }.sms-consent-button.record-consent:hover { border-color: #63b883; background: #e7f7ee; }
.sms-consent-button.withdraw-consent { border-color: #f0d0d2; background: #fff8f8; color: #a62932; }.sms-consent-button.withdraw-consent:hover { border-color: #d58a8e; background: #fff0f0; }
.sms-consent-button:disabled { cursor: wait; opacity: 0.65; }
.preference-button { color: #35435a; background: #f5f7fa; }
.sms-preference-overlay { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 18px; background: #10182888; }
.sms-preference-dialog { position: relative; width: min(480px, 100%); display: grid; gap: 13px; padding: 25px; border-radius: 15px; background: #fff; box-shadow: 0 20px 65px #11182740; }
.sms-preference-dialog h2 { margin: 0; font-size: 20px; color: #172033; }
.sms-preference-dialog p { margin: 0 0 5px; font-size: 13px; color: #627086; line-height: 1.5; }
.sms-preference-dialog>label { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid #e5e9ef; border-radius: 9px; font-size: 14px; }
.sms-preference-dialog input { width: 17px; height: 17px; accent-color: #ce202d; }
.sms-preference-close { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; display: grid; place-items: center; border: 1px solid #e2e6ec; border-radius: 8px; background: #fff; cursor: pointer; }
.sms-preference-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 5px; }
.empty-clear-search,.empty-create-customer { margin-top: 14px; }
@media(max-width:980px) { .customer-table { min-width: 860px; } }
@media(max-width:700px) {
    .page-heading .heading-actions { width: 100%; justify-content: flex-start; }
    .customer-toolbar { align-items: stretch; flex-direction: column; }
    .customer-search { width: 100%; }
    .customer-result-count { align-self: flex-start; }
    .customer-table-heading { padding: 12px 13px; }
    .customer-table { min-width: 0; white-space: normal; }
    .customer-table thead { display: none; }
    .customer-table,.customer-table tbody,.customer-table tbody tr,.customer-table tbody td { display: block; width: 100%; }
    .customer-table tbody tr { border-bottom: 1px solid #e5e8ec; padding: 8px 10px; }
    .customer-table tbody td { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; border: 0; padding: 7px 8px; text-align: right; white-space: normal; overflow-wrap: anywhere; }
    .customer-table tbody td::before { content: attr(data-label); flex: 0 0 105px; color: #657080; font-size: 12px; font-weight: 700; text-align: left; }
    .sms-consent-cell { justify-items: end; }
}
</style>
