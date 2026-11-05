<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCard,
    ElDialog,
    ElEmpty,
    ElForm,
    ElFormItem,
    ElInput,
    ElRadio,
    ElRadioGroup,
    ElTag,
} from 'element-plus';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import TablePagination from '@/components/TablePagination.vue';
import { show as personShow } from '@/routes/persons';
import {
    dismiss as dismissRoute,
    merge as mergeRoute,
} from '@/routes/persons/duplicates';
import type { Paginated } from '@/types/pagination';

type Summary = {
    id: string;
    full_name: string;
    birth_year: number | null;
    station: string;
};
type Candidate = {
    id: number;
    reason: string;
    confidence: number;
    first: Summary;
    second: Summary;
};

defineProps<{ candidates: Paginated<Candidate> }>();

const current = ref<Candidate | null>(null);

const mergeOpen = ref(false);
const mergeForm = useForm({ target_person_id: '', reason: '' });

function startMerge(candidate: Candidate): void {
    current.value = candidate;
    mergeForm.reset();
    mergeForm.clearErrors();
    mergeOpen.value = true;
}

function submitMerge(): void {
    if (!current.value) {
        return;
    }

    mergeForm.post(mergeRoute.url(current.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            mergeOpen.value = false;
        },
    });
}

const dismissOpen = ref(false);
const dismissForm = useForm({ reason: '' });

function startDismiss(candidate: Candidate): void {
    current.value = candidate;
    dismissForm.clearErrors();
    dismissOpen.value = true;
}

function submitDismiss(reason: string): void {
    if (!current.value) {
        return;
    }

    dismissForm.reason = reason;
    dismissForm.post(dismissRoute.url(current.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            dismissOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Duplicate review" />

    <div class="space-y-6">
        <PageHeader
            title="Duplicate review"
            description="Possible matches, for you to decide. Nothing is ever merged automatically, and a name match is not proof that two records are the same person."
        />

        <ElEmpty
            v-if="candidates.data.length === 0"
            description="Nothing to review"
        />

        <ElCard
            v-for="candidate in candidates.data"
            :key="candidate.id"
            shadow="never"
        >
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <ElTag
                    :type="candidate.confidence >= 90 ? 'warning' : 'info'"
                    size="small"
                >
                    Confidence {{ candidate.confidence }}%
                </ElTag>
                <span class="text-sm text-slate-600">{{
                    candidate.reason
                }}</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="person in [candidate.first, candidate.second]"
                    :key="person.id"
                    class="rounded-lg bg-slate-50 p-3 text-sm"
                >
                    <Link
                        :href="personShow.url(person.id)"
                        class="font-semibold text-brand-700 hover:underline"
                    >
                        {{ person.full_name }}
                    </Link>
                    <div class="text-slate-600">
                        Born {{ person.birth_year ?? 'unknown' }} ·
                        {{ person.station }}
                    </div>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <ElButton
                    type="primary"
                    size="small"
                    @click="startMerge(candidate)"
                >
                    Same person: merge
                </ElButton>
                <ElButton size="small" @click="startDismiss(candidate)"
                    >Different people</ElButton
                >
            </div>
        </ElCard>

        <TablePagination :paginator="candidates" />

        <ElDialog
            v-model="mergeOpen"
            title="Merge records"
            width="min(520px, 92vw)"
        >
            <ElAlert
                type="warning"
                :closable="false"
                class="mb-4"
                title="Identity documents, contacts, addresses and case links move to the record you keep. The other record stays, marked as merged, and the merge is kept on record."
            />
            <ElForm label-position="top" @submit.prevent="submitMerge">
                <ElFormItem
                    label="Record to keep"
                    :error="mergeForm.errors.target_person_id"
                    required
                >
                    <ElRadioGroup
                        v-if="current"
                        v-model="mergeForm.target_person_id"
                        class="flex-col items-start!"
                    >
                        <ElRadio :value="current.first.id"
                            >{{ current.first.full_name }} ({{
                                current.first.station
                            }})</ElRadio
                        >
                        <ElRadio :value="current.second.id"
                            >{{ current.second.full_name }} ({{
                                current.second.station
                            }})</ElRadio
                        >
                    </ElRadioGroup>
                </ElFormItem>
                <ElFormItem
                    label="How did you confirm this? (kept on record)"
                    :error="mergeForm.errors.reason"
                    required
                >
                    <ElInput
                        v-model="mergeForm.reason"
                        type="textarea"
                        :rows="3"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="mergeOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="mergeForm.processing"
                    @click="submitMerge"
                >
                    Merge
                </ElButton>
            </template>
        </ElDialog>

        <ReasonDialog
            v-model="dismissOpen"
            title="These are different people"
            confirm-text="Confirm"
            :error="dismissForm.errors.reason"
            :processing="dismissForm.processing"
            @confirm="submitDismiss"
        />
    </div>
</template>
