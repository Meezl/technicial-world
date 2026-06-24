import '../css/app.css';
// Import globally so it is bundled once and available on every Inertia
// page visit, not only when a layout component that @imports it happens
// to render — this prevents text-overlap FOUC on SPA navigation (#5).
import '../css/dashboard-app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Fragment, createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import WhatsAppFloat from './Components/WhatsAppFloat.vue';
import FormErrorToast from './Components/FormErrorToast.vue';
import { useGlobalFormErrors } from './composables/useFormErrors.js';

// Wire global form-error UX: scroll-to-first-error, shake highlight, toast (#2)
useGlobalFormErrors();

const appName = import.meta.env.VITE_APP_NAME || 'Technician World';

createInertiaApp({
    // If a page didn't set its own title via <Head title="...">, fall back
    // to just the brand name — avoids the awkward " - Technician World"
    // (leading dash + space) when title is empty.
    title: (title) => title ? `${title} - ${appName}` : appName,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({
            render: () =>
                h(Fragment, [
                    h(App, props),
                    h(WhatsAppFloat),
                    h(FormErrorToast),
                ]),
        })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
