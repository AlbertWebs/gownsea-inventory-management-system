<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

type PaginationLink = { url: string | null; label: string; active: boolean };

defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();

function labelText(label: string): string {
    return label.replace(/&laquo;/g, '‹').replace(/&raquo;/g, '›').replace(/&amp;/g, '&').replace(/<[^>]*>/g, '').trim();
}
</script>

<template>
    <div class="pagination">
        <span>Showing {{ from ?? 0 }}–{{ to ?? 0 }} of {{ total.toLocaleString() }}</span>
        <div class="pagination-links">
            <Link v-for="link in links.filter((item) => item.url)" :key="link.label" :href="link.url as string" class="pagination-link" :class="{ active: link.active }" :aria-current="link.active ? 'page' : undefined">
                {{ labelText(link.label) }}
            </Link>
        </div>
    </div>
</template>
