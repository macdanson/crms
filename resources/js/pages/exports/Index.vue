<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCard,
    ElDatePicker,
    ElForm,
    ElFormItem,
    ElInput,
    ElOption,
    ElSelect,
    ElTable,
    ElTableColumn,
    ElTag,
} from 'element-plus';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { formatDateTime } from '@/lib/utils';
import { decide as decideRoute, download, store } from '@/routes/exports';

type Option = { value: string; label: string };

defineProps<{
    enabled: boolean;
    threshold: number;
    fields: string[];
    mine: {
        id: string;
        row_count: number;
        status: string;
        status_label: string;
        decision_note: string | null;
        created_at: string | null;
        expires_at: string | null;
    }[];
    toDecide: {
        id: string;
        requester: string;
        purpose: string;
        explanation: string | null;
        row_count: number;
    }[];
    purposes: Option[];
    statuses: Option[];
    categories: { id: number; name: string }[];
    can: { request: boolean };
}>();

const form = useForm({
    status: null as string | null,
    offence_category_id: null as number | null,
    from: null as string | null,
    to: null as string | null,
    purpose: '',
    explanation: '',
});

/** A refusal about the filters as a whole, rather than one field. */
const filtersError = computed(
    () => (form.errors as Record<string, string>).filters,
);

function submit(): void {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

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
    <Head title="Exports" />

    <div class="space-y-6">
        <PageHeader
            title="Exports"
            :description="`Case lists only, with no personal data. Fields: ${fields.join(', ')}.`"
        />

        <ElAlert
            v-if="!enabled"
            type="info"
            :closable="false"
            title="Exports are switched off. An administrator can enable them once the institution has approved their use."
        />

        <ElCard v-if="enabled && can.request" shadow="never" class="max-w-3xl">
            <template #header>
                <h2 class="font-bold text-brand-900">Request an export</h2>
            </template>
            <ElForm label-position="top" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <ElFormItem label="Status" :error="form.errors.status">
                        <ElSelect
                            v-model="form.status"
                            clearable
                            class="w-full"
                        >
                            <ElOption
                                v-for="option in statuses"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </ElSelect>
                    </ElFormItem>
                    <ElFormItem
                        label="Category"
                        :error="form.errors.offence_category_id"
                    >
                        <ElSelect
                            v-model="form.offence_category_id"
                            filterable
                            clearable
                            class="w-full"
                        >
                            <ElOption
                                v-for="category in categories"
                                :key="category.id"
                                :label="category.name"
                                :value="category.id"
                            />
                        </ElSelect>
                    </ElFormItem>
                    <ElFormItem label="Incident from" :error="form.errors.from">
                        <ElDatePicker
                            v-model="form.from"
                            type="date"
                            value-format="YYYY-MM-DD"
                            class="w-full!"
                        />
                    </ElFormItem>
                    <ElFormItem label="Incident to" :error="form.errors.to">
                        <ElDatePicker
                            v-model="form.to"
                            type="date"
                            value-format="YYYY-MM-DD"
                            class="w-full!"
                        />
                    </ElFormItem>
                    <ElFormItem
                        label="Purpose"
                        :error="form.errors.purpose"
                        required
                    >
                        <ElSelect v-model="form.purpose" class="w-full">
                            <ElOption
                                v-for="option in purposes"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </ElSelect>
                    </ElFormItem>
                </div>
                <ElFormItem
                    label="Why is it needed, and where will it go?"
                    :error="form.errors.explanation"
                    required
                >
                    <ElInput
                        v-model="form.explanation"
                        type="textarea"
                        :rows="2"
                        maxlength="1000"
                        show-word-limit
                    />
                </ElFormItem>
                <p v-if="filtersError" class="mb-3 text-sm text-signal-700">
                    {{ filtersError }}
                </p>
                <div class="flex items-center gap-3">
                    <ElButton
                        type="primary"
                        native-type="submit"
                        :loading="form.processing"
                        >Request export</ElButton
                    >
                    <span class="text-xs text-slate-500"
                        >More than {{ threshold }} rows needs another officer's
                        approval.</span
                    >
                </div>
            </ElForm>
        </ElCard>

        <ElCard v-if="toDecide.length > 0" shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">To decide</h2>
            </template>
            <ElTable :data="toDecide">
                <ElTableColumn
                    prop="requester"
                    label="Requested by"
                    min-width="180"
                />
                <ElTableColumn prop="row_count" label="Rows" width="90" />
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

        <ElCard shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">Your exports</h2>
            </template>
            <p class="mb-3 text-xs text-slate-500">
                Each file can be downloaded once, and is destroyed if not
                collected in time.
            </p>
            <ElTable
                :data="mine"
                empty-text="You have not requested any exports"
            >
                <ElTableColumn label="Requested" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.created_at)
                    }}</template>
                </ElTableColumn>
                <ElTableColumn prop="row_count" label="Rows" width="90" />
                <ElTableColumn label="Status" min-width="230">
                    <template #default="{ row }">
                        <ElTag size="small">{{ row.status_label }}</ElTag>
                        <div
                            v-if="row.decision_note"
                            class="mt-1 text-xs text-slate-500"
                        >
                            {{ row.decision_note }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Available until" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.expires_at)
                    }}</template>
                </ElTableColumn>
                <ElTableColumn width="120" align="right">
                    <template #default="{ row }">
                        <a
                            v-if="row.status === 'ready'"
                            :href="download.url(row.id)"
                            class="text-sm font-semibold text-brand-700 hover:underline"
                        >
                            Download
                        </a>
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ReasonDialog
            v-model="decideOpen"
            :title="deciding?.approve ? 'Approve export' : 'Reject export'"
            label="Your reason (kept on record)"
            :confirm-text="deciding?.approve ? 'Approve' : 'Reject'"
            :danger="!deciding?.approve"
            :error="decideForm.errors.note"
            :processing="decideForm.processing"
            @confirm="submitDecision"
        />
    </div>
</template>
