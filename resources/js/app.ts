import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';

const pages = import.meta.glob<{ default: DefineComponent }>('./Pages/**/*.vue');

createInertiaApp({
    title: (title) => (title ? `${title} · GOWNSEA` : 'GOWNSEA Operations'),
    resolve: (name) => {
        const loadPage = pages[`./Pages/${name}.vue`];
        if (!loadPage) throw new Error('The Inertia page "' + name + '" was not found.');
        return loadPage().then((page) => page.default);
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#D42127' },
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/service-worker.js').catch((error: unknown) => {
            console.warn('GOWNSEA static asset service worker is unavailable.', error);
        });
    });
}
