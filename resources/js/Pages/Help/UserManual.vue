<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownToLine, BookOpen } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppShell from '../../Components/AppShell.vue';

defineProps<{ manualHtml: string }>();
type ContentsItem = { id: string; title: string; level: 2 | 3 };
const contents = ref<ContentsItem[]>([]);
const activeContentId = ref('');
let headingAnimationTimer: number | undefined;
let animatedHeading: HTMLElement | undefined;

function updateActiveContent(): void {
    const marker = window.innerHeight * 0.22;
    let activeId = contents.value[0]?.id ?? '';

    for (const item of contents.value) {
        const heading = document.getElementById(item.id);
        if (heading && heading.getBoundingClientRect().top <= marker) activeId = item.id;
        else break;
    }

    activeContentId.value = activeId;
}

function navigateToSection(id: string): void {
    const heading = document.getElementById(id);
    if (!heading) return;

    if (animatedHeading) animatedHeading.classList.remove('manual-heading-focus');
    if (headingAnimationTimer) window.clearTimeout(headingAnimationTimer);
    animatedHeading = heading;
    heading.classList.remove('manual-heading-focus');
    void heading.offsetWidth;
    heading.classList.add('manual-heading-focus');
    activeContentId.value = id;
    heading.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
    });
    headingAnimationTimer = window.setTimeout(() => {
        heading.classList.remove('manual-heading-focus');
        animatedHeading = undefined;
        headingAnimationTimer = undefined;
    }, 1000);
}

onMounted(() => {
    const headings = Array.from(document.querySelectorAll<HTMLElement>('.manual-content h2, .manual-content h3'));
    contents.value = headings.flatMap((heading) => {
        const title = heading.textContent?.trim() ?? '';
        if (!title || title.toLowerCase() === 'contents') return [];

        const id = title.toLowerCase().replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-');
        heading.id = id;

        return [{ id, title, level: heading.tagName === 'H3' ? 3 as const : 2 as const }];
    });
    updateActiveContent();
    window.addEventListener('scroll', updateActiveContent, { passive: true });
    window.addEventListener('resize', updateActiveContent);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', updateActiveContent);
    window.removeEventListener('resize', updateActiveContent);
    if (headingAnimationTimer) window.clearTimeout(headingAnimationTimer);
});
</script>

<template>
    <Head title="User Manual" />
    <AppShell active="manual">
        <div class="page-heading manual-heading">
            <div>
                <span class="manual-eyebrow"><BookOpen aria-hidden="true" /> Help &amp; guidance</span>
                <h1>GOWNSEA User Manual</h1>
                <p>Step-by-step help for the everyday tasks in GOWNSEA Operations.</p>
            </div>
            <a class="secondary-link manual-download" href="/user-manual/download"><ArrowDownToLine aria-hidden="true" /> Download manual</a>
        </div>
        <div class="manual-layout">
            <aside class="panel manual-toc" aria-label="User manual table of contents">
                <h2>Contents</h2>
                <nav>
                    <a v-for="item in contents" :key="item.id" :href="`#${item.id}`" :class="{ subsection: item.level === 3, active: activeContentId === item.id }" :aria-current="activeContentId === item.id ? 'location' : undefined" @click.prevent="navigateToSection(item.id)">{{ item.title }}</a>
                </nav>
            </aside>
            <article class="panel manual-panel">
                <div class="manual-content" v-html="manualHtml" />
            </article>
        </div>
    </AppShell>
</template>

<style scoped>
.manual-heading { align-items:flex-end; gap:16px; }
.manual-heading h1 { margin-top:5px; }
.manual-eyebrow { display:inline-flex; align-items:center; gap:6px; color:#9d2630; font-size:12px; font-weight:700; }
.manual-eyebrow svg { width:15px; height:15px; }
.manual-download { display:inline-flex; align-items:center; gap:7px; }
.manual-download svg { width:16px; height:16px; }
.manual-layout { display:grid; grid-template-columns:230px minmax(0,1fr); align-items:start; gap:16px; }
.manual-toc { position:sticky; top:16px; max-height:calc(100vh - 32px); overflow-y:auto; padding:16px 12px; }
.manual-toc h2 { margin:0 8px 10px; color:#172033; font-size:14px; }
.manual-toc nav { display:grid; gap:3px; }
.manual-toc a { display:block; border-radius:6px; padding:7px 8px; color:#465164; font-size:12px; line-height:1.35; text-decoration:none; }
.manual-toc a:hover,.manual-toc a:focus-visible { outline:none; background:#fff2f3; color:#a51d2a; }
.manual-toc a.active { background:#fff0f1; box-shadow:inset 3px 0 #c92332; color:#a51d2a; font-weight:700; }
.manual-toc a.subsection { margin-left:9px; color:#697384; font-size:11px; }
.manual-toc a.subsection.active { color:#a51d2a; }
.manual-panel { min-width:0; padding:clamp(20px,4vw,44px); }
.manual-content { color:#3e4858; font-size:14px; line-height:1.7; }
.manual-content :deep(h2),.manual-content :deep(h3) { scroll-margin-top:94px; }
.manual-content :deep(h2:first-of-type),.manual-content :deep(h2:first-of-type + ul) { display:none; }
.manual-content :deep(h1) { margin:0 0 14px; color:#172033; font-size:28px; letter-spacing:-.025em; line-height:1.2; }
.manual-content :deep(h2) { margin:36px 0 14px; border-left:4px solid #c92332; border-radius:0 9px 9px 0; padding:10px 14px; background:linear-gradient(100deg,#fff1f2,#fff 78%); color:#18253b; font-size:23px; font-weight:760; letter-spacing:-.02em; line-height:1.35; }
.manual-content :deep(h3) { margin:24px 0 9px; border-bottom:1px solid #f0e1e3; padding:0 0 8px 10px; color:#8f2631; font-size:17px; font-weight:720; }
.manual-content :deep(.manual-heading-focus) { animation:manual-heading-focus 1s ease-out both; }
@keyframes manual-heading-focus { 0% { background-color:#ffe39a; box-shadow:0 0 0 5px #f2c65c55; } 55% { background-color:#fff1c9; box-shadow:0 0 0 3px #f2c65c25; } 100% { background-color:transparent; box-shadow:0 0 0 0 transparent; } }
.manual-content :deep(p) { margin:8px 0 13px; }
.manual-content :deep(ul),.manual-content :deep(ol) { margin:8px 0 16px; padding-left:25px; }
.manual-content :deep(ol) { list-style:decimal; }
.manual-content :deep(ol > li::marker) { color:#a51d2a; font-weight:700; }
.manual-content :deep(li) { margin:5px 0; padding-left:3px; }
.manual-content :deep(a) { color:#b42331; font-weight:650; text-decoration:underline; text-decoration-color:#e8b8bd; text-underline-offset:3px; }
.manual-content :deep(a:hover) { color:#821a24; text-decoration-color:#b42331; }
.manual-content :deep(strong) { color:#253249; }
.manual-content :deep(blockquote) { margin:14px 0; border-left:4px solid #d7a33c; border-radius:0 8px 8px 0; padding:10px 15px; background:#fff9eb; color:#66552f; }
.manual-content :deep(hr) { margin:28px 0; border:0; border-top:1px solid #e9edf2; }
@media(max-width:800px) { .manual-layout { grid-template-columns:1fr; }.manual-toc { position:static; max-height:280px; }.manual-toc nav { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:700px) { .manual-heading { align-items:flex-start; flex-direction:column; }.manual-panel { padding:20px 16px; }.manual-content { font-size:13px; }.manual-content :deep(h1) { font-size:23px; }.manual-content :deep(h2) { font-size:19px; } }
@media(prefers-reduced-motion:reduce) { .manual-content :deep(.manual-heading-focus) { animation:none; outline:3px solid #d7a33c; outline-offset:3px; } }
</style>
