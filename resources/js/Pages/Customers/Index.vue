<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, UsersRound } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';
import Pagination from '../../Components/Pagination.vue';

type Customer = { id: number; customer_number: string; type: string; name: string; phone: string | null; email: string | null; institution: { name: string } | null; hires_count: number; sms_consent: boolean; created_at: string };
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };
const props = defineProps<{ customers: Page<Customer>; filters: { q: string }; canManageSmsConsent: boolean }>();
const query = ref(props.filters.q);
const pendingConsentUpdates = ref<number[]>([]);
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
</script>

<template>
    <Head title="Customers" />
    <AppShell active="customers">
        <div class="page-heading"><div><h1>Customers & CRM</h1><p>Customer records and linked gown hiring history.</p></div><div class="heading-actions"><Link href="/customers/create" class="primary-link"><Plus aria-hidden="true" /> New customer</Link><Link href="/hiring/issue" class="secondary-link">Issue gowns</Link></div></div>
        <div class="toolbar"><label class="global-search customer-search"><Search aria-hidden="true" /><input v-model="query" type="search" placeholder="Search name, customer number or phone" aria-label="Search customers"></label></div>
        <section class="panel table-panel">
            <div v-if="customers.data.length" class="table-wrap">
                <table class="data-table"><thead><tr><th>Customer</th><th>Customer No.</th><th>Institution</th><th>Phone</th><th>Email</th><th>Hire Records</th><th>Type</th><th>SMS consent</th></tr></thead>
                    <tbody><tr v-for="customer in customers.data" :key="customer.id"><td><strong>{{ customer.name }}</strong></td><td>{{ customer.customer_number }}</td><td>{{ customer.institution?.name ?? '—' }}</td><td>{{ customer.phone ?? '—' }}</td><td>{{ customer.email ?? '—' }}</td><td>{{ customer.hires_count }}</td><td>{{ customer.type }}</td><td><button v-if="canManageSmsConsent" type="button" class="sms-consent-button" :disabled="pendingConsentUpdates.includes(customer.id)" :aria-label="`${customer.sms_consent ? 'Withdraw' : 'Record'} SMS consent for ${customer.name}`" @click="updateSmsConsent(customer)">{{ pendingConsentUpdates.includes(customer.id) ? 'Saving…' : customer.sms_consent ? 'Enabled' : 'Disabled · no consent' }}</button><span v-else>{{ customer.sms_consent ? 'Enabled' : 'Disabled' }}</span></td></tr></tbody>
                </table>
            </div>
            <div v-else class="empty-state"><div><UsersRound aria-hidden="true" /><strong>No customer records found</strong><span>Customer profiles are shown after they have been added to the system.</span></div></div>
            <Pagination v-if="customers.total > 0" :links="customers.links" :from="customers.from" :to="customers.to" :total="customers.total" />
        </section>
    </AppShell>
</template>

<style scoped>
.customer-search { width: min(400px, 100%); }
.heading-actions { display: flex; align-items: center; gap: 8px; }
.sms-consent-button { border: 1px solid #d8dce2; border-radius: 8px; background: #fff; color: #485261; padding: 6px 9px; font: inherit; font-size: 12px; cursor: pointer; }
.sms-consent-button:disabled { cursor: wait; opacity: 0.65; }
</style>
