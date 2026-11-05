<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ElButton, ElCard, ElTable, ElTableColumn, ElTag } from 'element-plus';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import TablePagination from '@/components/TablePagination.vue';
import { formatDateTime } from '@/lib/utils';
import { review as reviewRoute } from '@/routes/admin/alerts';
import { show as userShow } from '@/routes/admin/users';
import type { Paginated } from '@/types/pagination';

defineProps<{
    alerts: Paginated<{
        id: number;
        label: string;
        severity: string;
        user: string | null;
        user_id: number | null;
        event_count: number;
        window_started_at: string;
        status: string;
        reviewed_by: string | null;
        review_note: string | null;
        created_at: string | null;
    }>;
}>();

const severityTypes: Record<string, 'info' | 'warning' | 'danger'> = {
    low: 'info',
    medium: 'warning',
    high: 'danger',
    critical: 'danger',
};

const reviewing = ref<number | null>(null);
const reviewOpen = ref(false);
const reviewForm = useForm({ note: '' });

function startReview(alertId: number): void {
    reviewing.value = alertId;
    reviewForm.clearErrors();
    reviewOpen.value = true;
}

function submitReview(note: string): void {
    if (reviewing.value === null) {
        return;
    }

    reviewForm.note = note;
    reviewForm.put(reviewRoute.url(reviewing.value), {
        preserveScroll: true,
        onSuccess: () => {
            reviewOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Security alerts" />

    <div class="space-y-6">
        <PageHeader
            title="Security alerts"
            description="Suspicious patterns found in the audit trail. Thresholds are provisional and should be tuned against a baseline with the security team."
        />

        <ElCard shadow="never">
            <ElTable :data="alerts.data" empty-text="No alerts">
                <ElTableColumn label="Alert" min-width="280">
                    <template #default="{ row }">
                        <ElTag
                            :type="severityTypes[row.severity] ?? 'info'"
                            size="small"
                            class="mr-2"
                            >{{ row.severity }}</ElTag
                        >
                        {{ row.label }}
                    </template>
                </ElTableColumn>
                <ElTableColumn label="User" min-width="170">
                    <template #default="{ row }">
                        <Link
                            v-if="row.user_id"
                            :href="userShow.url(row.user_id)"
                            class="text-brand-700 hover:underline"
                        >
                            {{ row.user }}
                        </Link>
                        <span v-else>System</span>
                    </template>
                </ElTableColumn>
                <ElTableColumn prop="event_count" label="Events" width="90" />
                <ElTableColumn label="Raised" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.created_at)
                    }}</template>
                </ElTableColumn>
                <ElTableColumn label="Review" min-width="240">
                    <template #default="{ row }">
                        <template v-if="row.status === 'reviewed'">
                            <span class="font-semibold"
                                >{{ row.reviewed_by }}:</span
                            >
                            {{ row.review_note }}
                        </template>
                        <ElButton
                            v-else
                            link
                            type="primary"
                            @click="startReview(row.id)"
                            >Mark as reviewed</ElButton
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
            <TablePagination :paginator="alerts" />
        </ElCard>

        <ReasonDialog
            v-model="reviewOpen"
            title="Review alert"
            label="What did you find? (kept on record)"
            confirm-text="Save"
            :error="reviewForm.errors.note"
            :processing="reviewForm.processing"
            @confirm="submitReview"
        />
    </div>
</template>
