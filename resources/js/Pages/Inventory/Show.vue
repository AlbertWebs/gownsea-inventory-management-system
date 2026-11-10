<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Box, Check, Clipboard, Clock3, History, MapPin, PackageCheck, Printer, RotateCcw } from '@lucide/vue';
import AppShell from '../../Components/AppShell.vue';

type TimelineEntry = { id: string; occurredAt: string | null; type: string; actor: string; fromLocation: string | null; toLocation: string | null; fromStatus: string | null; toStatus: string | null; notes: string | null; record: string };
const props = defineProps<{ asset: { id: number; code: string; product: string; sku: string; category: string; size: string | null; color: string | null; imageUrl: string | null; status: string; condition: string; custody: string; homeLocation: string | null; currentLocation: string | null; verifiedAt: string | null }; timeline: TimelineEntry[] }>();
const filter = ref<'all' | 'custody' | 'stock'>('all');
const codeCopied = ref(false);
let copyTimer: ReturnType<typeof setTimeout> | undefined;
const visibleTimeline = computed(() => filter.value === 'all' ? props.timeline : props.timeline.filter((entry) => filter.value === 'custody' ? entry.record === 'Custody event' : entry.record === 'Stock movement'));
const labelUrl = computed(() => `/inventory/labels?assets[]=${encodeURIComponent(props.asset.id)}`);

function label(value: string | null): string {
    return value?.replaceAll('_', ' ') ?? '—';
}

function eventTitle(value: string): string {
    const names: Record<string, string> = {
        hire_issued: 'Issued to customer', hire_returned: 'Returned to branch', hire_asset_missing: 'Reported missing',
        cycle_count_missing: 'Marked missing in count', asset_written_off: 'Written off', transfer_dispatched: 'Transfer dispatched',
        transfer_received: 'Transfer received', stock_received: 'Stock received', asset_inspected: 'Inspection completed',
    };
    return names[value] ?? label(value).replace(/^./, (letter) => letter.toUpperCase());
}

async function copyCode(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.asset.code);
        codeCopied.value = true;
        if (copyTimer) clearTimeout(copyTimer);
        copyTimer = setTimeout(() => { codeCopied.value = false; }, 1800);
    } catch {
        codeCopied.value = false;
    }
}
</script>

<template>
    <Head :title="`${asset.code} · Asset details`" />
    <AppShell active="inventory">
        <div class="asset-page">
            <div class="asset-page-top">
                <div>
                    <Link href="/inventory" class="back-link"><ArrowLeft :size="16" aria-hidden="true" /> Inventory</Link>
                    <p class="eyebrow">SERIALIZED INVENTORY</p>
                    <h1>Asset details</h1>
                    <p class="page-subtitle">Review this item's identity, condition, location, and full movement history.</p>
                </div>
                <div class="header-actions">
                    <a :href="labelUrl" class="secondary-link" target="_blank" rel="noopener"><Printer :size="16" aria-hidden="true" /> Print label</a>
                    <Link href="/inventory" class="primary-link"><PackageCheck :size="16" aria-hidden="true" /> Inventory list</Link>
                </div>
            </div>

            <section class="asset-hero panel">
                <div class="product-visual">
                    <img v-if="asset.imageUrl" :src="asset.imageUrl" :alt="asset.product">
                    <Box v-else :size="34" aria-hidden="true" />
                </div>
                <div class="asset-hero-copy">
                    <div class="asset-code-line">
                        <span class="asset-code-label">ASSET ID</span>
                        <button type="button" class="asset-code-button" :aria-label="codeCopied ? 'Asset code copied' : 'Copy asset code'" @click="copyCode"><span>{{ asset.code }}</span><Check v-if="codeCopied" :size="16"/><Clipboard v-else :size="16"/><small v-if="codeCopied">Copied</small></button>
                    </div>
                    <h2>{{ asset.product }}</h2>
                    <p class="asset-sku">SKU {{ asset.sku }}<span v-if="asset.category"> · {{ label(asset.category) }}</span></p>
                    <div class="asset-badges">
                        <span class="status-badge" :class="`status-${asset.status}`"><span class="badge-dot"/>{{ label(asset.status) }}</span>
                        <span class="condition-badge" :class="`condition-${asset.condition}`">{{ label(asset.condition) }} condition</span>
                    </div>
                </div>
                <div class="hero-divider" aria-hidden="true"/>
                <div class="hero-details">
                    <div><span>SIZE</span><strong>{{ asset.size || 'Not specified' }}</strong></div>
                    <div><span>COLOR</span><strong>{{ asset.color || 'Not specified' }}</strong></div>
                    <div><span>CUSTODY</span><strong>{{ label(asset.custody) }}</strong></div>
                </div>
            </section>

            <section class="location-cards" aria-label="Asset location summary">
                <article class="location-card"><span class="location-icon home-icon"><MapPin :size="17" aria-hidden="true"/></span><div><small>Home branch</small><strong>{{ asset.homeLocation || 'Unassigned' }}</strong><span>Registered stock location</span></div></article>
                <article class="location-card"><span class="location-icon current-icon"><RotateCcw :size="17" aria-hidden="true"/></span><div><small>Current location</small><strong>{{ asset.currentLocation || label(asset.custody) }}</strong><span>Where the asset is now</span></div></article>
                <article class="location-card"><span class="location-icon verified-icon"><Clock3 :size="17" aria-hidden="true"/></span><div><small>Last verified</small><strong>{{ asset.verifiedAt || 'Not verified yet' }}</strong><span>Most recent recorded check</span></div></article>
            </section>

            <section class="panel timeline-panel">
                <div class="timeline-heading">
                    <div class="timeline-title-icon"><History :size="19" aria-hidden="true"/></div>
                    <div class="timeline-title-copy"><h2>Movement history</h2><p>Every recorded custody change and stock movement for this asset.</p></div>
                    <span class="history-count">{{ timeline.length }} {{ timeline.length === 1 ? 'record' : 'records' }}</span>
                </div>
                <div class="timeline-toolbar">
                    <div class="timeline-filters" role="group" aria-label="Filter movement history">
                        <button v-for="option in [{ key: 'all', text: 'All activity' }, { key: 'custody', text: 'Custody' }, { key: 'stock', text: 'Stock movements' }]" :key="option.key" type="button" :class="{ selected: filter === option.key }" :aria-pressed="filter === option.key" @click="filter = option.key as 'all' | 'custody' | 'stock'">{{ option.text }}</button>
                    </div>
                    <span class="newest-note"><ArrowRight :size="14" aria-hidden="true"/> Newest first</span>
                </div>
                <div v-if="visibleTimeline.length" class="timeline-list">
                    <article v-for="entry in visibleTimeline" :key="entry.id" class="timeline-entry">
                        <div class="timeline-rail"><span class="timeline-marker" :class="entry.record === 'Custody event' ? 'marker-custody' : 'marker-stock'" aria-hidden="true"/></div>
                        <div class="timeline-card">
                            <div class="timeline-card-top"><div class="event-heading"><strong>{{ eventTitle(entry.type) }}</strong><span class="record-type" :class="entry.record === 'Custody event' ? 'record-custody' : 'record-stock'">{{ entry.record }}</span></div><time>{{ entry.occurredAt ?? 'Time not recorded' }}</time></div>
                            <div class="timeline-facts">
                                <span class="actor-fact"><span class="fact-label">Recorded by</span><strong>{{ entry.actor }}</strong></span>
                                <span v-if="entry.fromStatus || entry.toStatus" class="status-fact"><span class="fact-label">Status</span><strong>{{ label(entry.fromStatus) }}</strong><ArrowRight :size="13"/><strong>{{ label(entry.toStatus) }}</strong></span>
                                <span v-if="entry.fromLocation || entry.toLocation" class="location-fact"><MapPin :size="14"/><strong>{{ entry.fromLocation ?? 'Outside branch' }}</strong><ArrowRight :size="13"/><strong>{{ entry.toLocation ?? 'Outside branch' }}</strong></span>
                            </div>
                            <p v-if="entry.notes" class="timeline-notes">{{ entry.notes }}</p>
                        </div>
                    </article>
                </div>
                <div v-else class="timeline-empty"><div class="empty-icon"><History :size="23" aria-hidden="true"/></div><strong>{{ timeline.length ? 'No matching activity' : 'No movement history yet' }}</strong><span>{{ timeline.length ? 'Choose another filter to see the rest of this asset’s history.' : 'Custody changes and stock movements will appear here as they are recorded.' }}</span></div>
            </section>
        </div>
    </AppShell>
</template>

<style scoped>
.asset-page{display:grid;gap:17px;max-width:1440px;margin:0 auto}.asset-page-top{display:flex;align-items:flex-end;justify-content:space-between;gap:20px}.back-link{display:inline-flex;align-items:center;gap:7px;margin-bottom:12px;color:#596779;font-size:13px;font-weight:650;text-decoration:none}.back-link:hover{color:#bd1e2d}.eyebrow{margin:0 0 4px;color:#bd1e2d;font-size:11px;font-weight:800;letter-spacing:.11em}.asset-page-top h1{margin:0;color:#192337;font-size:26px;line-height:1.2}.page-subtitle{margin:6px 0 0;color:#68758a;font-size:14px}.header-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.header-actions :deep(.secondary-link),.header-actions :deep(.primary-link){display:inline-flex;align-items:center;gap:7px;min-height:40px;padding:0 13px;text-decoration:none}.asset-hero{display:grid;grid-template-columns:92px minmax(220px,1fr) 1px minmax(230px,.75fr);align-items:center;gap:22px;padding:22px 24px;border-color:#e7e9ee;box-shadow:0 5px 20px #1d29390a}.product-visual{width:92px;height:100px;display:grid;place-items:center;overflow:hidden;border-radius:13px;background:linear-gradient(145deg,#fff2f2,#f5f6f9);color:#c72635}.product-visual img{width:100%;height:100%;object-fit:cover}.asset-hero-copy{min-width:0}.asset-code-line{display:flex;align-items:center;gap:10px}.asset-code-label{color:#7d8798;font-size:10px;font-weight:800;letter-spacing:.1em}.asset-code-button{display:flex;align-items:center;gap:6px;border:0;border-radius:6px;background:transparent;padding:3px 5px;color:#26334a;font:inherit;font-size:13px;font-weight:750;cursor:pointer}.asset-code-button:hover{background:#f4f6f8;color:#b71f2c}.asset-code-button svg{color:#68768a}.asset-code-button small{color:#17814f;font-size:11px}.asset-hero h2{margin:8px 0 3px;color:#182236;font-size:21px;line-height:1.3}.asset-sku{margin:0;color:#6b778a;font-size:13px}.asset-badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:13px}.status-badge,.condition-badge{display:inline-flex;align-items:center;gap:7px;border-radius:20px;padding:6px 10px;font-size:12px;font-weight:700;text-transform:capitalize}.status-badge{background:#edf7f1;color:#207648}.badge-dot{width:7px;height:7px;border-radius:50%;background:currentColor}.status-hired,.status-reserved,.status-in_transit{background:#eef4ff;color:#2860a4}.status-returned_pending_inspection,.status-cleaning,.status-under_repair{background:#fff5e8;color:#a35c0c}.status-damaged,.status-missing,.status-written_off{background:#fff0f0;color:#b4232d}.status-sold{background:#f0edff;color:#6349a3}.condition-badge{background:#f2f4f7;color:#566276}.condition-good{background:#ecf7f0;color:#207648}.condition-damaged{background:#fff0f0;color:#b4232d}.hero-divider{height:80%;background:#e9ebf0}.hero-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}.hero-details div{display:grid;gap:7px;min-width:0}.hero-details span{color:#8992a1;font-size:10px;font-weight:800;letter-spacing:.08em}.hero-details strong{color:#2d394c;font-size:13px;text-transform:capitalize;overflow-wrap:anywhere}.location-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.location-card{display:flex;align-items:flex-start;gap:12px;min-height:90px;padding:15px;border:1px solid #e7eaf0;border-radius:12px;background:#fff}.location-icon{display:grid;width:36px;height:36px;flex:0 0 36px;place-items:center;border-radius:10px}.home-icon{background:#fff2e5;color:#b96b10}.current-icon{background:#edf4ff;color:#3466a4}.verified-icon{background:#f0edff;color:#6a53a2}.location-card div{display:grid;gap:3px;min-width:0}.location-card small{color:#738095;font-size:11px;font-weight:650}.location-card strong{color:#263348;font-size:14px;overflow-wrap:anywhere}.location-card div span{color:#99a1ad;font-size:11px}.timeline-panel{overflow:hidden;padding:0;border-color:#e5e8ee;box-shadow:0 5px 18px #1d293908}.timeline-heading{display:flex;align-items:center;gap:12px;padding:19px 21px 16px}.timeline-title-icon{display:grid;width:39px;height:39px;place-items:center;border-radius:11px;background:#fff0f1;color:#bd1e2d}.timeline-title-copy{min-width:0}.timeline-title-copy h2{margin:0;color:#1e293b;font-size:17px}.timeline-title-copy p{margin:4px 0 0;color:#778398;font-size:12px}.history-count{margin-left:auto;border:1px solid #e4e8ee;border-radius:18px;padding:5px 10px;color:#536176;font-size:11px;font-weight:700;white-space:nowrap}.timeline-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid #edf0f4;border-bottom:1px solid #edf0f4;background:#fafbfc;padding:10px 20px}.timeline-filters{display:flex;gap:4px;padding:3px;border:1px solid #e7eaf0;border-radius:9px;background:#fff}.timeline-filters button{border:0;border-radius:6px;background:transparent;padding:7px 11px;color:#667286;font:inherit;font-size:12px;font-weight:650;cursor:pointer}.timeline-filters button:hover{background:#f5f6f8;color:#344259}.timeline-filters button.selected{background:#202d42;color:#fff}.newest-note{display:inline-flex;align-items:center;gap:5px;color:#8993a2;font-size:11px}.timeline-list{padding:6px 20px 18px}.timeline-entry{display:grid;grid-template-columns:19px minmax(0,1fr);gap:11px;padding-top:13px}.timeline-rail{position:relative;display:flex;justify-content:center}.timeline-entry:not(:last-child) .timeline-rail:after{position:absolute;top:15px;bottom:-15px;width:2px;background:#e9ecf1;content:''}.timeline-marker{z-index:1;width:13px;height:13px;margin-top:4px;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 1px #e2e6ec}.marker-custody{background:#c52836;box-shadow:0 0 0 3px #fbe8e9}.marker-stock{background:#4381c1;box-shadow:0 0 0 3px #e9f1fa}.timeline-card{min-width:0;border:1px solid #e9ecf1;border-radius:10px;background:#fff;padding:13px 15px}.timeline-card:hover{border-color:#d7dce5;box-shadow:0 3px 12px #17233b08}.timeline-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.event-heading{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.event-heading>strong{color:#253148;font-size:14px}.record-type{border-radius:15px;padding:3px 8px;font-size:10px;font-weight:700}.record-custody{background:#fff1f2;color:#a72835}.record-stock{background:#edf5ff;color:#2864a4}.timeline-card-top time{color:#7f8999;font-size:11px;white-space:nowrap}.timeline-facts{display:flex;align-items:center;gap:9px 20px;flex-wrap:wrap;margin-top:10px}.timeline-facts>span{display:inline-flex;align-items:center;gap:6px;color:#8892a1;font-size:12px}.timeline-facts strong{color:#536176;font-size:12px;font-weight:650;text-transform:capitalize}.fact-label{color:#99a1ad;font-size:11px}.status-fact svg,.location-fact svg{color:#a2aab6}.timeline-notes{margin:10px 0 0;border-left:2px solid #ebc66b;padding:5px 0 5px 10px;color:#657186;font-size:12px;white-space:pre-wrap}.timeline-empty{display:grid;justify-items:center;gap:7px;padding:42px 20px 48px;text-align:center}.empty-icon{display:grid;width:43px;height:43px;place-items:center;border-radius:13px;background:#f3f5f8;color:#9aa4b3}.timeline-empty strong{color:#364257;font-size:14px}.timeline-empty>span{max-width:390px;color:#8490a2;font-size:12px;line-height:1.5}
@media(max-width:900px){.asset-hero{grid-template-columns:76px minmax(0,1fr);gap:16px}.product-visual{width:76px;height:82px}.hero-divider{display:none}.hero-details{grid-column:1/-1;border-top:1px solid #edf0f4;padding-top:15px}.location-cards{grid-template-columns:1fr}.location-card{min-height:0}}
@media(max-width:620px){.asset-page-top{align-items:flex-start;flex-direction:column}.asset-page-top h1{font-size:23px}.header-actions{width:100%}.header-actions :deep(a){flex:1;justify-content:center}.asset-hero{padding:16px}.product-visual{width:62px;height:70px}.asset-hero h2{font-size:18px}.asset-code-button{font-size:12px}.hero-details{gap:9px}.hero-details strong{font-size:12px}.timeline-heading{align-items:flex-start;padding:15px}.timeline-title-copy h2{font-size:15px}.timeline-title-copy p{max-width:240px;line-height:1.4}.history-count{font-size:10px}.timeline-toolbar{align-items:flex-start;flex-direction:column;padding:10px 14px}.timeline-filters{max-width:100%;overflow:auto}.timeline-filters button{white-space:nowrap;padding:7px 9px}.timeline-list{padding-right:12px;padding-left:12px}.timeline-card{padding:11px}.timeline-card-top{flex-direction:column;gap:5px}.timeline-facts{gap:8px 14px}.timeline-facts>span{align-items:flex-start}.location-fact{flex-wrap:wrap}}
</style>
