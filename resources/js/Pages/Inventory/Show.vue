<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, History, MapPin } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';

type TimelineEntry = { id: string; occurredAt: string | null; type: string; actor: string; fromLocation: string | null; toLocation: string | null; fromStatus: string | null; toStatus: string | null; notes: string | null; record: string };
const props = defineProps<{ asset: { id: number; code: string; product: string; sku: string; category: string; size: string | null; color: string | null; status: string; condition: string; custody: string; homeLocation: string | null; currentLocation: string | null; verifiedAt: string | null }; timeline: TimelineEntry[] }>();

function label(value: string | null): string {
    return value?.replaceAll('_', ' ') ?? '—';
}
</script>

<template>
    <Head :title="`Asset ${asset.code}`" />
    <AppShell active="inventory">
        <div class="page-heading">
            <div><h1>Asset details</h1><p>Custody and stock movement history for this serialized item.</p></div>
            <Link href="/inventory" class="secondary-link"><ArrowLeft aria-hidden="true" /> Back to inventory</Link>
        </div>
        <section class="panel asset-summary">
            <div class="asset-identity"><span class="asset-code">{{ asset.code }}</span><span class="status-chip" :class="asset.status">{{ label(asset.status) }}</span></div>
            <h2>{{ asset.product }}</h2>
            <div class="asset-meta"><span>SKU <strong>{{ asset.sku }}</strong></span><span>Category <strong>{{ label(asset.category) }}</strong></span><span>Size / color <strong>{{ asset.size ?? '—' }} · {{ asset.color ?? '—' }}</strong></span><span>Condition <strong>{{ label(asset.condition) }}</strong></span></div>
            <div class="asset-locations"><span><MapPin aria-hidden="true" /> Home: {{ asset.homeLocation ?? 'Unassigned' }}</span><span><MapPin aria-hidden="true" /> Current: {{ asset.currentLocation ?? label(asset.custody) }}</span><span>Last verified: {{ asset.verifiedAt ?? 'Never' }}</span></div>
        </section>
        <section class="panel timeline-panel">
            <div class="timeline-heading"><History aria-hidden="true" /><div><h2>Movement history</h2><p>{{ timeline.length }} custody and stock ledger entries</p></div></div>
            <div v-if="timeline.length" class="timeline-list">
                <article v-for="entry in timeline" :key="entry.id" class="timeline-entry">
                    <span class="timeline-marker" aria-hidden="true"></span>
                    <div class="timeline-content">
                        <div class="timeline-top"><strong>{{ label(entry.type) }}</strong><time>{{ entry.occurredAt ?? 'Time not recorded' }}</time></div>
                        <div class="timeline-details"><span>{{ entry.record }}</span><span>By {{ entry.actor }}</span><span v-if="entry.fromStatus || entry.toStatus">Status: {{ label(entry.fromStatus) }} → {{ label(entry.toStatus) }}</span></div>
                        <div v-if="entry.fromLocation || entry.toLocation" class="timeline-details"><span>{{ entry.fromLocation ?? 'External' }} → {{ entry.toLocation ?? 'External' }}</span></div>
                        <p v-if="entry.notes">{{ entry.notes }}</p>
                    </div>
                </article>
            </div>
            <div v-else class="empty-state"><div><History aria-hidden="true" /><strong>No movement history yet</strong><span>Receipts, custody changes and stock movements will appear here.</span></div></div>
        </section>
    </AppShell>
</template>

<style scoped>
.asset-summary { margin-bottom: 12px; padding: 18px; }
.asset-identity { display: flex; align-items: center; gap: 9px; }
.asset-code { color: #151a24; font-size: 18px; font-weight: 700; letter-spacing: .02em; }
.asset-summary h2 { margin: 8px 0 12px; color: #303743; font-size: 14px; }
.asset-meta, .asset-locations { display: flex; flex-wrap: wrap; gap: 9px 20px; color: #79818e; font-size: 10px; }
.asset-meta strong { color: #333a46; font-weight: 600; }
.asset-locations { margin-top: 14px; border-top: 1px solid #eff0f2; padding-top: 12px; }
.asset-locations span { display: inline-flex; align-items: center; gap: 4px; }
.asset-locations :deep(svg) { width: 12px; height: 12px; color: #bd1e2d; }
.timeline-panel { padding: 18px; }
.timeline-heading { display: flex; align-items: center; gap: 9px; border-bottom: 1px solid #eff0f2; padding-bottom: 12px; }
.timeline-heading > :deep(svg) { width: 18px; height: 18px; color: #bd1e2d; }
.timeline-heading h2 { margin: 0; color: #202632; font-size: 14px; }
.timeline-heading p { margin: 3px 0 0; color: #606874; font-size: 10px; }
.timeline-list { padding-top: 8px; }
.timeline-entry { position: relative; display: grid; grid-template-columns: 14px 1fr; gap: 10px; padding: 12px 0 4px; }
.timeline-entry:not(:last-child)::before { position: absolute; top: 22px; bottom: -10px; left: 6px; width: 1px; background: #e7e9ec; content: ''; }
.timeline-marker { z-index: 1; width: 13px; height: 13px; margin-top: 2px; border: 3px solid #f8e3e5; border-radius: 50%; background: #bd1e2d; }
.timeline-top { display: flex; justify-content: space-between; gap: 12px; color: #303743; font-size: 11px; }
.timeline-top time { color: #8a9099; font-size: 9px; }
.timeline-details { display: flex; flex-wrap: wrap; gap: 5px 14px; margin-top: 6px; color: #606874; font-size: 9px; text-transform: capitalize; }
.timeline-content p { margin: 8px 0 2px; color: #626a77; font-size: 10px; white-space: pre-wrap; }
@media(max-width:640px) { .timeline-top { flex-direction: column; gap: 3px; } .asset-meta, .asset-locations { gap: 8px 12px; } }
</style>
