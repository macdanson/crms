<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ElButton,
    ElCard,
    ElDatePicker,
    ElInput,
    ElOption,
    ElSelect,
    ElTable,
    ElTableColumn,
    ElTag,
} from 'element-plus';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import TablePagination from '@/components/TablePagination.vue';
import { formatDateTime } from '@/lib/utils';
import { index } from '@/routes/admin/audit';
import type { Paginated } from '@/types/pagination';

type AuditRow = {
    id: string;
    occurred_at: string;
    event: string;
    outcome: 'success' | 'failure' | 'blocked';
    user: string | null;
    record_type: string | null;
    record_id: string | null;
    ip_address: string | null;
    context: Record<string, unknown> | null;
};

const props = defineProps<{
    events: Paginated<AuditRow>;
    filters: {
        event?: string;
        outcome?: string;
        record_id?: string;
        from?: string;
        to?: string;
    };
    outcomes: string[];
}>();

const filters = reactive({
    event: props.filters.event ?? '',
    outcome: props.filters.outcome ?? '',
    record_id: props.filters.record_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function applyFilters(): void {
    router.get(index.url(), filters, { preserveState: true, replace: true });
}

const outcomeTypes = {
    success: 'success',
    failure: 'warning',
    blocked: 'danger',
} as const;

function recordLabel(row: AuditRow): string {
    return row.record_type
        ? `${row.record_type.split('\\').pop()} ${row.record_id}`
        : '—';
}
</script>

<template>
    <Head title="Audit log" />

    <div class="space-y-6">
        <PageHeader
            title="Audit log"
            description="Who did what, when and to which record. Reading this log is itself recorded."
        />

        <ElCard shadow="never">
            <form
                class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_140px_1fr_150px_150px_auto]"
                @submit.prevent="applyFilters"
            >
                <ElInput
                    v-model="filters.event"
                    placeholder="Event starts with…"
                    aria-label="Event"
                    clearable
                />
                <ElSelect
                    v-model="filters.outcome"
                    placeholder="Any outcome"
                    aria-label="Outcome"
                    clearable
                >
                    <ElOption
                        v-for="outcome in outcomes"
                        :key="outcome"
                        :label="outcome"
                        :value="outcome"
                    />
                </ElSelect>
                <ElInput
                    v-model="filters.record_id"
                    placeholder="Record ID"
                    aria-label="Record ID"
                    clearable
                />
                <ElDatePicker
                    v-model="filters.from"
                    type="date"
                    value-format="YYYY-MM-DD"
                    placeholder="From"
                    class="w-full!"
                />
                <ElDatePicker
                    v-model="filters.to"
                    type="date"
                    value-format="YYYY-MM-DD"
                    placeholder="To"
                    class="w-full!"
                />
                <ElButton native-type="submit">Filter</ElButton>
            </form>

            <ElTable :data="events.data" empty-text="No events match">
                <ElTableColumn type="expand">
                    <template #default="{ row }">
                        <pre
                            class="crms-code overflow-x-auto p-3 text-xs whitespace-pre-wrap"
                            >{{ JSON.stringify(row.context, null, 2) }}</pre>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Time (your timezone)" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.occurred_at)
                    }}</template>
                </ElTableColumn>
                <ElTableColumn prop="event" label="Event" min-width="230" />
                <ElTableColumn label="Outcome" width="110">
                    <template #default="{ row }">
                        <ElTag
                            :type="
                                outcomeTypes[row.outcome as AuditRow['outcome']]
                            "
                            size="small"
                        >
                            {{ row.outcome }}
                        </ElTag>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="User" min-width="160">
                    <template #default="{ row }">{{
                        row.user ?? '—'
                    }}</template>
                </ElTableColumn>
                <ElTableColumn label="Record" min-width="200">
                    <template #default="{ row }">
                        <span class="crms-code text-xs">{{
                            recordLabel(row as AuditRow)
                        }}</span>
                    </template>
                </ElTableColumn>
                <ElTableColumn prop="ip_address" label="Address" width="130" />
            </ElTable>
            <TablePagination :paginator="events" />
        </ElCard>
    </div>
</template>
