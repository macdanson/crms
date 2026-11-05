<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ElButton, ElCard, ElTable, ElTableColumn, ElTag } from 'element-plus';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { formatDateTime } from '@/lib/utils';
import { decide as decideRoute } from '@/routes/access-requests';
import { show as caseShow } from '@/routes/cases';

defineProps<{
    mine: {
        id: string;
        case_id: string | null;
        reference: string | null;
        purpose: string;
        status: string;
        decision_note: string | null;
        created_at: string | null;
        expires_at: string | null;
    }[];
    toDecide: {
        id: string;
        requester: string;
        requester_station: string | null;
        reference: string | null;
        purpose: string;
        explanation: string | null;
        created_at: string | null;
    }[];
    can: { request: boolean; decide: boolean };
}>();

const deciding = ref<{ id: string; approve: boolean } | null>(null);
const decideOpen = ref(false);
const decideForm = useForm({ approve: false, note: '' });

function startDecision(id: string, approve: boolean): void {
    deciding.value = { id, approve };
    decideForm.clearErrors();
    decideOpen.value = true;
}

function submitDecision(note: string): void {
    if (!deciding.value) {
        return;
    }

    decideForm.approve = deciding.value.approve;
    decideForm.note = note;
    decideForm.post(decideRoute.url(deciding.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            decideOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Access requests" />

    <div class="space-y-6">
        <PageHeader
            title="Access requests"
            description="Exceptional, time-limited access to a case outside someone's normal access. It must never become routine."
        />

        <ElCard v-if="can.decide" shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">To decide</h2>
            </template>
            <ElTable :data="toDecide" empty-text="Nothing waiting for you">
                <ElTableColumn label="Requested by" min-width="190">
                    <template #default="{ row }">
                        {{ row.requester }}
                        <div class="text-xs text-slate-500">
                            {{ row.requester_station }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Case" min-width="210">
                    <template #default="{ row }">
                        <span class="crms-code">{{ row.reference }}</span>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Reason" min-width="280">
                    <template #default="{ row }">
                        <span class="font-semibold">{{ row.purpose }}</span>
                        <div class="text-sm text-slate-600">
                            {{ row.explanation }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn width="170" align="right">
                    <template #default="{ row }">
                        <ElButton
                            link
                            type="primary"
                            @click="startDecision(row.id, true)"
                            >Approve</ElButton
                        >
                        <ElButton
                            link
                            type="danger"
                            @click="startDecision(row.id, false)"
                            >Reject</ElButton
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElCard v-if="can.request" shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">Your requests</h2>
            </template>
            <p class="mb-3 text-xs text-slate-500">Ask from the Search page.</p>
            <ElTable :data="mine" empty-text="You have not made any requests">
                <ElTableColumn label="Case" min-width="220">
                    <template #default="{ row }">
                        <Link
                            v-if="row.case_id"
                            :href="caseShow.url(row.case_id)"
                            class="crms-code font-semibold text-brand-700 hover:underline"
                        >
                            {{ row.reference }}
                        </Link>
                        <span v-else class="crms-code">{{
                            row.reference
                        }}</span>
                    </template>
                </ElTableColumn>
                <ElTableColumn prop="purpose" label="Purpose" min-width="200" />
                <ElTableColumn label="Status" min-width="220">
                    <template #default="{ row }">
                        <ElTag size="small">{{ row.status }}</ElTag>
                        <div
                            v-if="row.decision_note"
                            class="mt-1 text-xs text-slate-500"
                        >
                            {{ row.decision_note }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Ends" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.expires_at)
                    }}</template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ReasonDialog
            v-model="decideOpen"
            :title="deciding?.approve ? 'Approve access' : 'Reject access'"
            label="Your reason (kept on record)"
            :confirm-text="deciding?.approve ? 'Approve' : 'Reject'"
            :danger="!deciding?.approve"
            :warning="
                deciding?.approve
                    ? 'This gives the officer time-limited access to the whole case.'
                    : undefined
            "
            :error="decideForm.errors.note"
            :processing="decideForm.processing"
            @confirm="submitDecision"
        />
    </div>
</template>
