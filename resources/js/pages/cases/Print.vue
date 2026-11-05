<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    record: {
        reference: string;
        station: string;
        district: string;
        category: string;
        incident_date: string;
        incident_location: string;
        narrative: string;
        status_label: string;
        lifecycle_label: string;
    };
    watermark: string;
}>();

function printPage(): void {
    window.print();
}
</script>

<template>
    <Head :title="`Print ${record.reference}`" />

    <div class="mx-auto max-w-3xl bg-white p-8 text-slate-900">
        <div class="mb-4 flex justify-end print:hidden">
            <button
                type="button"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
                @click="printPage"
            >
                Print
            </button>
        </div>

        <p
            class="mb-6 border-y border-slate-300 py-2 text-center text-xs font-semibold tracking-wide uppercase"
        >
            Restricted · {{ watermark }}
        </p>

        <h1 class="text-xl font-bold">{{ record.reference }}</h1>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div>
                <dt class="font-semibold">Station</dt>
                <dd>{{ record.station }}, {{ record.district }}</dd>
            </div>
            <div>
                <dt class="font-semibold">Category</dt>
                <dd>{{ record.category }}</dd>
            </div>
            <div>
                <dt class="font-semibold">Incident</dt>
                <dd>
                    {{ record.incident_date }}, {{ record.incident_location }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold">Police status</dt>
                <dd>
                    {{ record.status_label }} ({{ record.lifecycle_label }})
                </dd>
            </div>
        </dl>
        <h2 class="mt-6 font-semibold">Narrative</h2>
        <p class="mt-1 text-sm whitespace-pre-wrap">{{ record.narrative }}</p>

        <p class="mt-8 border-t border-slate-300 pt-2 text-center text-xs">
            Police status is not a judicial finding. {{ watermark }}
        </p>
    </div>
</template>
