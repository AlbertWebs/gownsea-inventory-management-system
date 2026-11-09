<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import JsBarcode from 'jsbarcode';
import AppShell from '../../Components/AppShell.vue';

type LabelAsset = { id: number; code: string; product: string; sku: string; size: string | null };
const props = defineProps<{ assets: LabelAsset[] }>();

function printLabels(): void {
    window.print();
}

onMounted(() => {
    for (const asset of props.assets) {
        const element = document.querySelector<SVGSVGElement>(`[data-barcode-id="${asset.id}"]`);
        if (!element || !/^[\x20-\x7E]+$/.test(asset.code)) continue;
        try {
            JsBarcode(element, asset.code, { format: 'CODE128', displayValue: false, height: 38, width: 1.4, margin: 0 });
        } catch {
            element.setAttribute('aria-label', 'Barcode could not be rendered; use the printed asset code.');
        }
    }
});
</script>

<template>
    <Head title="Print asset labels" />
    <AppShell active="inventory">
        <div class="page-heading no-print"><div><h1>Asset labels</h1><p>{{ assets.length }} barcode label(s) · use adhesive labels or plain paper and trim.</p></div><div class="heading-actions"><Link href="/inventory" class="secondary-link">Back to inventory</Link><button class="primary-link" type="button" @click="printLabels">Print labels</button></div></div>
        <section class="label-sheet" aria-label="Printable asset barcode labels">
            <article v-for="asset in assets" :key="asset.id" class="asset-label">
                <img :src="'/images/brand/gownsea-logo.png'" alt="GOWNSEA" class="label-mark">
                <div class="label-product"><strong>{{ asset.product }}</strong><span>{{ asset.sku }}<template v-if="asset.size"> · {{ asset.size }}</template></span></div>
                <svg :data-barcode-id="asset.id" class="barcode" role="img" :aria-label="`Barcode for ${asset.code}`"></svg>
                <strong class="asset-code">{{ asset.code }}</strong>
            </article>
        </section>
    </AppShell>
</template>

<style scoped>
.label-sheet{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:20px;background:#fff}.asset-label{position:relative;display:grid;grid-template-columns:30px 1fr;grid-template-rows:auto 45px auto;align-items:center;gap:2px 8px;min-height:112px;padding:8px;border:1px dashed #aeb2bb;border-radius:5px;overflow:hidden}.label-mark{width:28px;height:28px;object-fit:contain}.label-product{display:flex;min-width:0;flex-direction:column;line-height:1.2}.label-product strong,.label-product span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.label-product strong{font-size:11px}.label-product span{font-size:9px;color:#5f6672}.barcode{grid-column:1/-1;width:100%;height:42px}.asset-code{grid-column:1/-1;text-align:center;font-size:11px;letter-spacing:.08em}.label-actions{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin:12px 0;color:#555}
@media(max-width:760px){.label-sheet{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media print{@page{size:A4;margin:8mm}.no-print,:global(header),:global(aside),:global(nav){display:none!important}.label-sheet{grid-template-columns:repeat(3,63mm);gap:3mm;padding:0}.asset-label{width:63mm;height:35mm;min-height:35mm;break-inside:avoid;border:1px solid #bbb;border-radius:0;padding:2mm}.barcode{height:12mm}.label-product strong{font-size:8pt}.label-product span,.asset-code{font-size:7pt}}
</style>
