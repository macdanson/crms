<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ElCard } from 'element-plus';
import CountTable from '@/components/CountTable.vue';
import PageHeader from '@/components/PageHeader.vue';

type Row = { label: string; count: number };

defineProps<{
    total: number;
    open: number;
    byStation: Row[];
    byCategory: Row[];
    byStatus: Row[];
    byMonth: Row[];
}>();
</script>

<template>
    <Head title="Reports" />

    <div class="space-y-6">
        <PageHeader
            title="Operational reports"
            description="Live counts of the cases you can see. These are for your own duties: they are not de-identified, so do not share them further."
        />

        <div class="grid gap-6 sm:grid-cols-2">
            <ElCard shadow="never">
                <span class="block text-3xl font-bold text-brand-900">{{
                    total
                }}</span>
                <span class="text-sm text-slate-600">Cases you can see</span>
            </ElCard>
            <ElCard shadow="never">
                <span class="block text-3xl font-bold text-brand-900">{{
                    open
                }}</span>
                <span class="text-sm text-slate-600"
                    >Not yet closed administratively</span
                >
            </ElCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <CountTable title="By station" :rows="byStation" />
            <CountTable title="By offence category" :rows="byCategory" />
            <CountTable title="By police status" :rows="byStatus" />
            <CountTable
                title="By month of incident (last 12 months)"
                :rows="byMonth"
            />
        </div>
    </div>
</template>
