<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useOnlineStatus } from '../composables/useOnlineStatus';
import {
    Bell, Boxes, CalendarClock, ChartNoAxesCombined, ChevronDown, ChevronRight, ClipboardList,
    KeyRound, Landmark, LayoutDashboard, LogOut, MapPinned, Menu, Search, Settings, ShieldCheck, UserRoundCog, UsersRound, WalletCards, X,
} from '@lucide/vue';

type User = { id: number; name: string; email: string; role: string };
type SearchResult = { type: string; id: number; label: string; detail: string; location: string; url: string };

const props = withDefaults(defineProps<{ active: string; title?: string; actionCount?: number }>(), { title: '', actionCount: 0 });
const page = usePage();
const online = useOnlineStatus();
const collapsed = ref(false);
const mobileOpen = ref(false);
const profileMenuOpen = ref(false);
const profileMenuRoot = ref<HTMLElement | null>(null);
const query = ref('');
const results = ref<SearchResult[]>([]);
const searching = ref(false);
const installPrompt = ref<InstallPromptEvent | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

type InstallPromptEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

const user = computed(() => (page.props.auth as { user: User | null }).user);
const flash = computed(() => page.props.flash as { success?: string; error?: string } | undefined);
const initials = computed(() => (user.value?.name ?? 'GS').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase());
const canManageWorkspace = computed(() => !!user.value && ['super_admin', 'management'].includes(user.value.role));

const navigation = computed(() => [
    { label: 'Dashboard', href: '/', key: 'dashboard', icon: LayoutDashboard },
    { label: 'Inventory', href: '/inventory', key: 'inventory', icon: Boxes },
    { label: 'Hiring & Returns', href: '/hiring', key: 'hiring', icon: CalendarClock },
    { label: 'Customers', href: '/customers', key: 'customers', icon: UsersRound },
    { label: 'CRM', href: '/crm', key: 'crm', icon: UserRoundCog },
    { label: 'Custom Orders', href: '/custom-orders', key: 'custom-orders', icon: ClipboardList },
    { label: 'Locations & Transfers', href: '/transfers', key: 'transfers', icon: MapPinned },
    { label: 'Sales & Payments', href: '/payments', key: 'payments', icon: WalletCards },
    { label: 'Finance', href: '/finance', key: 'finance', icon: ChartNoAxesCombined },
    { label: 'Reports', href: '/reports', key: 'reports', icon: ChartNoAxesCombined },
    { label: 'Graduation Events', href: '/events', key: 'events', icon: CalendarClock },
    ...(user.value && ['super_admin', 'management'].includes(user.value.role) ? [
        { label: 'Institutions', href: '/institutions', key: 'institutions', icon: Landmark },
        { label: 'Roles & Permissions', href: '/roles-permissions', key: 'roles-permissions', icon: KeyRound },
        { label: 'Settings', href: '/settings', key: 'settings', icon: Settings },
    ] : []),
]);

watch(query, (value) => {
    if (searchTimer) clearTimeout(searchTimer);
    const term = value.trim();
    if (term.length < 2) {
        results.value = [];
        searching.value = false;
        return;
    }

    searching.value = true;
    searchTimer = setTimeout(async () => {
        try {
            const response = await fetch(`/search?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Search is unavailable.');
            const data = (await response.json()) as { results: SearchResult[] };
            results.value = data.results;
        } catch {
            results.value = [];
        } finally {
            searching.value = false;
        }
    }, 220);
});

function signOut(): void {
    profileMenuOpen.value = false;
    router.delete('/logout');
}

function closeProfileMenu(): void {
    profileMenuOpen.value = false;
}

function closeProfileMenuOnOutsideClick(event: PointerEvent): void {
    if (!profileMenuRoot.value?.contains(event.target as Node)) closeProfileMenu();
}

function closeProfileMenuOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') closeProfileMenu();
}

function toggleNavigation(): void {
    if (window.innerWidth <= 700) {
        mobileOpen.value = !mobileOpen.value;
        return;
    }

    collapsed.value = !collapsed.value;
}

function closeSearch(): void {
    window.setTimeout(() => {
        results.value = [];
    }, 160);
}

function rememberInstallPrompt(event: Event): void {
    event.preventDefault();
    installPrompt.value = event as InstallPromptEvent;
}

async function installApp(): Promise<void> {
    if (!installPrompt.value) return;
    await installPrompt.value.prompt();
    await installPrompt.value.userChoice;
    installPrompt.value = null;
}

onMounted(() => {
    window.addEventListener('beforeinstallprompt', rememberInstallPrompt);
    document.addEventListener('pointerdown', closeProfileMenuOnOutsideClick);
    document.addEventListener('keydown', closeProfileMenuOnEscape);
});

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
    window.removeEventListener('beforeinstallprompt', rememberInstallPrompt);
    document.removeEventListener('pointerdown', closeProfileMenuOnOutsideClick);
    document.removeEventListener('keydown', closeProfileMenuOnEscape);
});
</script>

<template>
    <div class="app-shell" :class="{ 'sidebar-collapsed': collapsed }">
        <aside class="sidebar" :class="{ open: mobileOpen }" aria-label="Main navigation">
            <Link class="brand" href="/" aria-label="GOWNSEA dashboard" @click="mobileOpen = false">
                <img class="brand-logo-full" :src="'/images/brand/gownsea-logo-on-dark.png'" alt="GOWNSEA — Celebrating Every Success">
                <img class="brand-logo-mark" :src="'/images/brand/gownsea-mark-on-dark.png'" alt="" aria-hidden="true">
            </Link>

            <nav class="sidebar-nav">
                <Link
                    v-for="item in navigation"
                    :key="item.key"
                    :href="item.href"
                    class="nav-link"
                    :class="{ active: active === item.key }"
                    :aria-current="active === item.key ? 'page' : undefined"
                    @click="mobileOpen = false"
                >
                    <component :is="item.icon" aria-hidden="true" />
                    <span>{{ item.label }}</span>
                    <ChevronRight v-if="active !== item.key" class="nav-chevron" aria-hidden="true" />
                </Link>
            </nav>

            <div class="sidebar-quote" aria-label="Celebrating every milestone in style">
                <p>Celebrating<br>Every Milestone<br>in Style<small>GOWNSEA Graduation Wear</small></p>
            </div>
        </aside>

        <button class="mobile-overlay" :class="{ visible: mobileOpen }" aria-label="Close navigation" @click="mobileOpen = false" />

        <div class="shell-main">
            <header class="topbar">
                <button class="icon-button" aria-label="Toggle navigation" @click="toggleNavigation">
                    <Menu v-if="!mobileOpen" aria-hidden="true" /><X v-else aria-hidden="true" />
                </button>
                <Link class="mobile-brand" href="/" aria-label="GOWNSEA home"><img :src="'/images/brand/gownsea-logo.png'" alt="GOWNSEA"></Link>
                <div class="search-wrap">
                    <label class="global-search">
                        <Search aria-hidden="true" />
                        <input v-model="query" type="search" placeholder="Search gowns, customers, orders, or scan barcode..." autocomplete="off" @blur="closeSearch" @keydown.esc="results = []">
                    </label>
                    <div v-if="query.trim().length >= 2" class="search-results" role="listbox" aria-label="Search results">
                        <div v-if="searching" class="search-empty">Searching authorized records…</div>
                        <div v-else-if="results.length === 0" class="search-empty">No matching records found.</div>
                        <Link v-for="result in results" :key="`${result.type}-${result.id}`" :href="result.url" class="search-result" @mousedown.prevent>
                            <span><strong>{{ result.label }}</strong><small>{{ result.detail }}<template v-if="result.location"> · {{ result.location }}</template></small></span>
                            <ChevronRight :size="15" aria-hidden="true" />
                        </Link>
                    </div>
                </div>
                <div class="topbar-spacer" />
                <span v-if="!online" class="offline-indicator" role="status">Offline · changes need a connection</span>
                <button v-if="installPrompt" class="install-app-button" type="button" @click="installApp">Install app</button>
                <Link class="notification-link" href="/hiring" :aria-label="props.actionCount > 0 ? `Open ${props.actionCount} dashboard actions` : 'Open hiring and returns queue'">
                    <Bell aria-hidden="true" />
                    <span v-if="props.actionCount > 0" class="notification-badge">{{ props.actionCount > 99 ? '99+' : props.actionCount }}</span>
                </Link>
                <span class="topbar-divider" />
                <div ref="profileMenuRoot" class="profile-menu-root">
                    <button
                        id="profile-menu-trigger"
                        class="profile-trigger"
                        type="button"
                        aria-label="Open account menu"
                        aria-controls="profile-menu-panel"
                        :aria-expanded="profileMenuOpen"
                        @click="profileMenuOpen = !profileMenuOpen"
                    >
                        <span class="avatar" aria-hidden="true">{{ initials }}</span>
                        <span class="profile-copy"><span class="profile-name">{{ user?.name ?? 'GOWNSEA Staff' }}</span><span class="profile-role">{{ (user?.role ?? 'Staff').replaceAll('_', ' ') }}</span></span>
                        <ChevronDown class="profile-chevron" aria-hidden="true" />
                    </button>
                    <div v-if="profileMenuOpen" id="profile-menu-panel" class="profile-dropdown" aria-labelledby="profile-menu-trigger">
                        <div class="profile-identity">
                            <span class="avatar profile-dropdown-avatar" aria-hidden="true">{{ initials }}</span>
                            <span class="profile-identity-copy">
                                <strong>{{ user?.name ?? 'GOWNSEA Staff' }}</strong>
                                <span>{{ user?.email ?? 'Signed-in account' }}</span>
                                <small>{{ (user?.role ?? 'Staff').replaceAll('_', ' ') }}</small>
                            </span>
                        </div>
                        <div class="profile-dropdown-divider" />
                        <Link class="profile-dropdown-link" href="/account/security" @click="closeProfileMenu">
                            <ShieldCheck aria-hidden="true" /><span>Account security</span><ChevronRight aria-hidden="true" />
                        </Link>
                        <template v-if="canManageWorkspace">
                            <Link class="profile-dropdown-link" href="/roles-permissions" @click="closeProfileMenu">
                                <KeyRound aria-hidden="true" /><span>Roles &amp; permissions</span><ChevronRight aria-hidden="true" />
                            </Link>
                            <Link class="profile-dropdown-link" href="/settings" @click="closeProfileMenu">
                                <Settings aria-hidden="true" /><span>Workspace settings</span><ChevronRight aria-hidden="true" />
                            </Link>
                        </template>
                        <div class="profile-dropdown-divider" />
                        <button class="profile-dropdown-signout" type="button" @click="signOut">
                            <LogOut aria-hidden="true" /><span>Sign out</span>
                        </button>
                    </div>
                </div>
            </header>

            <main class="page-content" :class="{ 'dashboard-page-content': props.active === 'dashboard' }">
                <div v-if="flash?.success" class="flash-message" role="status">{{ flash.success }}</div>
                <div v-if="flash?.error" class="flash-message flash-error" role="alert">{{ flash.error }}</div>
                <slot />
            </main>
        </div>
    </div>
</template>
