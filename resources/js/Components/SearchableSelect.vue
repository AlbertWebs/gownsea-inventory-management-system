<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Check, ChevronDown, Search } from '@lucide/vue';

type Option = { value: string | number; label: string };
const props = withDefaults(defineProps<{
    modelValue: string | number;
    options: Option[];
    id: string;
    label: string;
    placeholder?: string;
    searchPlaceholder?: string;
    disabled?: boolean;
}>(), { placeholder: 'Select an option', searchPlaceholder: 'Search options…', disabled: false });
const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>();
const isOpen = ref(false);
const query = ref('');
const activeIndex = ref(0);
const searchInput = ref<HTMLInputElement | null>(null);
const selected = computed(() => props.options.find((option) => String(option.value) === String(props.modelValue)));
const filteredOptions = computed(() => {
    const term = query.value.trim().toLocaleLowerCase();
    return term ? props.options.filter((option) => option.label.toLocaleLowerCase().includes(term)) : props.options;
});

async function open(): Promise<void> {
    if (props.disabled) return;
    isOpen.value = true;
    query.value = '';
    activeIndex.value = 0;
    await nextTick();
    searchInput.value?.focus();
}

function close(): void {
    isOpen.value = false;
    query.value = '';
}

function choose(option: Option): void {
    emit('update:modelValue', option.value);
    close();
}

function handleSearchKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, filteredOptions.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && filteredOptions.value[activeIndex.value]) {
        event.preventDefault();
        choose(filteredOptions.value[activeIndex.value]);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        close();
    }
}
</script>

<template>
    <div class="searchable-select" :class="{ 'is-open': isOpen, 'is-disabled': disabled }">
        <button :id="id" class="select-trigger" type="button" role="combobox" :aria-label="label" :aria-expanded="isOpen" aria-haspopup="listbox" :aria-controls="`${id}-options`" :disabled="disabled" @click="isOpen ? close() : open()" @keydown.down.prevent="open">
            <span :class="{ 'placeholder-text': !selected }">{{ selected?.label ?? placeholder }}</span>
            <ChevronDown aria-hidden="true" />
        </button>
        <div v-if="isOpen" class="select-popover">
            <label class="select-search"><Search aria-hidden="true" /><input ref="searchInput" v-model="query" type="search" :placeholder="searchPlaceholder" autocomplete="off" :aria-label="searchPlaceholder" @keydown="handleSearchKeydown"></label>
            <ul :id="`${id}-options`" class="select-options" role="listbox" :aria-label="label">
                <li v-for="(option, index) in filteredOptions" :key="String(option.value)" role="presentation">
                    <button type="button" role="option" class="select-option" :class="{ highlighted: index === activeIndex, selected: String(option.value) === String(modelValue) }" :aria-selected="String(option.value) === String(modelValue)" @mouseenter="activeIndex = index" @mousedown.prevent="choose(option)">
                        <span>{{ option.label }}</span><Check v-if="String(option.value) === String(modelValue)" aria-hidden="true" />
                    </button>
                </li>
                <li v-if="filteredOptions.length === 0" class="no-options">No matching options found.</li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.searchable-select { position:relative; min-width:0; }
.select-trigger { width:100%; min-height:40px; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 11px; border:1px solid #dfe2e7; border-radius:7px; background:#fff; color:#171b24; font:inherit; font-size:13px; text-align:left; cursor:pointer; }
.select-trigger:hover { border-color:#b8bec8; }
.select-trigger:focus-visible { outline:3px solid #d72b3830; border-color:#c91f2e; }
.select-trigger > span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.select-trigger > svg { width:16px; height:16px; flex:none; color:#596371; }
.placeholder-text { color:#747d89; }
.is-disabled .select-trigger { background:#f1f2f4; color:#737b86; cursor:not-allowed; }
.select-popover { position:absolute; z-index:30; top:calc(100% + 5px); left:0; right:auto; width:min(460px,calc(100vw - 40px)); padding:9px; border:1px solid #dfe2e7; border-radius:9px; background:#fff; box-shadow:0 12px 30px #17203324; }
.select-search { display:flex; align-items:center; gap:9px; padding:0 10px; border:1px solid #dfe2e7; border-radius:7px; background:#fff; color:#68707e; transition:border-color .15s ease,box-shadow .15s ease; }
.select-search:focus-within { border-color:#b7c0cc; box-shadow:0 0 0 3px #26364d12; }
.select-search svg { width:15px; height:15px; flex:none; color:#7a8492; stroke-width:2; }
.select-search :deep(input),.select-search input { width:100%; height:38px; min-width:0; padding:0; border:0 !important; outline:0 !important; box-shadow:none !important; background:transparent; color:#171b24; font:inherit; font-size:13px; }
.select-search :deep(input:focus),.select-search :deep(input:focus-visible),.select-search input:focus,.select-search input:focus-visible { border:0 !important; outline:0 !important; box-shadow:none !important; }
.select-options { max-height:250px; overflow:auto; margin:7px 0 0; padding:0; list-style:none; }
.select-option { width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px; border:0; border-radius:5px; background:transparent; color:#252b36; font:inherit; font-size:13px; line-height:1.45; text-align:left; white-space:normal; cursor:pointer; }
.select-option:hover,.select-option.highlighted { background:#f5f6f8; }
.select-option.selected { color:#a51d2a; font-weight:650; }
.select-option svg { width:15px; height:15px; flex:none; }
.no-options { padding:12px 9px; color:#68707e; font-size:12px; }
</style>
