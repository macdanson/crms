import { createInertiaApp } from '@inertiajs/vue3';
import { ID_INJECTION_KEY, ZINDEX_INJECTION_KEY } from 'element-plus';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'CRMS';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        if (name.startsWith('auth/')) {
            return AuthLayout;
        }

        if (name === 'Error' || name === 'cases/Print') {
            return null;
        }

        return AppLayout;
    },
    withApp: (app) => {
        // Element Plus components are imported per page so only what is used
        // is shipped. Stable IDs and z-indexes keep server-rendered markup
        // and the hydrated client in sync.
        app.provide(ID_INJECTION_KEY, { prefix: 1024, current: 0 });
        app.provide(ZINDEX_INJECTION_KEY, { current: 0 });

        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    (el.querySelector('input') ?? el).focus();
                }
            },
        });
    },
    progress: {
        color: '#D4A017',
    },
});
