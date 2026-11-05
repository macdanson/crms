<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ElButton, ElCard, ElTable, ElTableColumn } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import TablePagination from '@/components/TablePagination.vue';
import { formatDateTime } from '@/lib/utils';
import { create, show } from '@/routes/persons';
import { show as searchShow } from '@/routes/search';
import type { Paginated } from '@/types/pagination';

defineProps<{
    people: Paginated<{
        id: string;
        full_name: string;
        birth_year: number | null;
        station: string;
        created_at: string | null;
    }>;
    canCreate: boolean;
}>();
</script>

<template>
    <Head title="People" />

    <div class="space-y-6">
        <PageHeader
            title="People"
            description="People you can see. A record here says nothing about a person: their role belongs to each case. To find someone, use Search."
        >
            <Link :href="searchShow.url()"><ElButton>Search</ElButton></Link>
            <Link v-if="canCreate" :href="create.url()">
                <ElButton type="primary">Register person</ElButton>
            </Link>
        </PageHeader>

        <ElCard shadow="never">
            <ElTable :data="people.data" empty-text="No people to show">
                <ElTableColumn label="Name" min-width="240">
                    <template #default="{ row }">
                        <Link
                            :href="show.url(row.id)"
                            class="font-semibold text-brand-700 hover:underline"
                        >
                            {{ row.full_name }}
                        </Link>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Birth year" width="110">
                    <template #default="{ row }">
                        {{ row.birth_year ?? '—' }}
                    </template>
                </ElTableColumn>
                <ElTableColumn
                    prop="station"
                    label="Registered at"
                    min-width="180"
                />
                <ElTableColumn label="Registered" width="190">
                    <template #default="{ row }">
                        {{ formatDateTime(row.created_at) }}
                    </template>
                </ElTableColumn>
            </ElTable>
            <TablePagination :paginator="people" />
        </ElCard>
    </div>
</template>
