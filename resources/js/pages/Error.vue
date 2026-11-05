<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ElButton } from 'element-plus';
import { computed } from 'vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import { home } from '@/routes';

/**
 * Rendered by the exception handler. Shared props are absent when no route
 * matched (e.g. a 404), so everything the page needs arrives as page props.
 */
const props = defineProps<{
    status: number;
    productName: string;
}>();

const content = computed(
    () =>
        ({
            403: {
                title: 'Access not permitted',
                text: 'Your account is not authorised to view this page. If you need access for your duties, ask your supervisor.',
            },
            404: {
                title: 'Page not found',
                text: 'The page you requested does not exist or has moved.',
            },
            409: {
                title: 'Not available yet',
                text: 'This file cannot be opened until it has passed its malware scan. Try again shortly, or contact your administrator if this continues.',
            },
            410: {
                title: 'No longer available',
                text: 'This has already been collected or has expired. Request it again if you still need it.',
            },
            429: {
                title: 'Too many requests',
                text: 'Please wait a minute before trying again.',
            },
            500: {
                title: 'Something went wrong',
                text: 'The error has been logged. Please try again shortly.',
            },
            503: {
                title: 'Down for maintenance',
                text: 'CRMS is temporarily unavailable while maintenance is carried out.',
            },
        })[props.status] ?? {
            title: 'Unexpected error',
            text: 'Please try again.',
        },
);
</script>

<template>
    <Head :title="content.title" />

    <div class="flex min-h-svh items-center justify-center bg-surface px-4">
        <div class="max-w-md text-center">
            <AppLogoMark class="mx-auto size-16" />
            <p class="crms-code mt-6 text-sm font-semibold text-accent-700">
                Error {{ status }}
            </p>
            <h1 class="mt-2 text-2xl font-bold text-brand-900">
                {{ content.title }}
            </h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">
                {{ content.text }}
            </p>
            <Link :href="home.url()">
                <ElButton type="primary" class="mt-8">
                    Return to {{ productName }}
                </ElButton>
            </Link>
        </div>
    </div>
</template>
