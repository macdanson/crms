<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ElAlert } from 'element-plus';
import CountTable from '@/components/CountTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import { formatDateTime } from '@/lib/utils';

type Row = { label: string; count: number | null };

defineProps<{
    generated_at: string | null;
    threshold: number;
    by_district: Row[];
    by_category: Row[];
    by_status: Row[];
    by_month: Row[];
    by_outcome: Row[];
}>();
</script>

<template>
    <Head title="Statistics" />

    <div class="space-y-6">
        <PageHeader
            title="De-identified statistics"
            :description="`Aggregate counts with no way back to a person. Figures as of ${formatDateTime(generated_at)}.`"
        />

        <ElAlert
            type="info"
            :closable="false"
            :title="`Counts below ${threshold}, and sometimes one more, are hidden so that nobody can be identified. Review the risk of re-identification before publishing or sharing these figures.`"
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <CountTable title="By district" :rows="by_district" />
            <CountTable title="By offence category" :rows="by_category" />
            <CountTable title="By police status" :rows="by_status" />
            <CountTable title="By month of incident" :rows="by_month" />
            <CountTable title="Verified court outcomes" :rows="by_outcome" />
        </div>
    </div>
</template>
