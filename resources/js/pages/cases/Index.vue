<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ElButton,
    ElCard,
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
import { create, index, show } from '@/routes/cases';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    cases: Paginated<{
        id: string;
        reference: string;
        station: string;
        category: string;
        incident_date: string;
        status: string;
        status_label: string;
        lifecycle_label: string;
    }>;
    filters: {
        reference?: string;
        status?: string;
        offence_category_id?: number;
    };
    statuses: { value: string; label: string }[];
    categories: { id: number; name: string }[];
    canCreate: boolean;
}>();

const filters = reactive({
    reference: props.filters.reference ?? '',
    status: props.filters.status ?? '',
    offence_category_id: props.filters.offence_category_id ?? '',
});

function applyFilters(): void {
    router.get(index.url(), filters, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Cases" />

    <div class="space-y-6">
        <PageHeader
            title="Cases"
            description="The cases you are authorised to see."
        >
            <Link v-if="canCreate" :href="create.url()">
                <ElButton type="primary">Open case</ElButton>
            </Link>
        </PageHeader>

        <ElCard shadow="never">
            <form
                class="mb-4 grid gap-3 sm:grid-cols-[1fr_200px_240px_auto]"
                @submit.prevent="applyFilters"
            >
                <ElInput
                    v-model="filters.reference"
                    placeholder="Case reference"
                    aria-label="Case reference"
                    clearable
                />
                <ElSelect
                    v-model="filters.status"
                    placeholder="Any status"
                    aria-label="Status"
                    clearable
                >
                    <ElOption
                        v-for="status in statuses"
                        :key="status.value"
                        :label="status.label"
                        :value="status.value"
                    />
                </ElSelect>
                <ElSelect
                    v-model="filters.offence_category_id"
                    placeholder="Any category"
                    aria-label="Category"
                    filterable
                    clearable
                >
                    <ElOption
                        v-for="category in categories"
                        :key="category.id"
                        :label="category.name"
                        :value="category.id"
                    />
                </ElSelect>
                <ElButton native-type="submit">Filter</ElButton>
            </form>

            <ElTable :data="cases.data" empty-text="No cases to show">
                <ElTableColumn label="Reference" min-width="230">
                    <template #default="{ row }">
                        <Link
                            :href="show.url(row.id)"
                            class="crms-code font-semibold text-brand-700 hover:underline"
                        >
                            {{ row.reference }}
                        </Link>
                    </template>
                </ElTableColumn>
                <ElTableColumn prop="station" label="Station" min-width="160" />
                <ElTableColumn
                    prop="category"
                    label="Category"
                    min-width="180"
                />
                <ElTableColumn
                    prop="incident_date"
                    label="Incident date"
                    width="130"
                />
                <ElTableColumn label="Status" width="190">
                    <template #default="{ row }">
                        <ElTag
                            size="small"
                            :type="
                                row.status === 'closed_administratively'
                                    ? 'info'
                                    : 'success'
                            "
                        >
                            {{ row.status_label }}
                        </ElTag>
                    </template>
                </ElTableColumn>
                <ElTableColumn
                    prop="lifecycle_label"
                    label="Record"
                    width="170"
                />
            </ElTable>
            <TablePagination :paginator="cases" />
        </ElCard>
    </div>
</template>
