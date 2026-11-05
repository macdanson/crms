<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCard,
    ElCheckbox,
    ElDatePicker,
    ElDialog,
    ElForm,
    ElFormItem,
    ElInput,
    ElMessageBox,
    ElOption,
    ElSelect,
    ElTable,
    ElTableColumn,
    ElTag,
    ElTimeline,
    ElTimelineItem,
} from 'element-plus';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { formatDateTime } from '@/lib/utils';
import { print as printRoute, update as updateCase } from '@/routes/cases';
import {
    destroy as endAssignment,
    store as storeAssignment,
} from '@/routes/cases/assignments';
import { store as storeProceeding } from '@/routes/cases/court-proceedings';
import { store as storeDocument } from '@/routes/cases/documents';
import { update as updateLifecycle } from '@/routes/cases/lifecycle';
import { store as storeNote } from '@/routes/cases/notes';
import {
    destroy as unlinkPerson,
    store as linkPerson,
} from '@/routes/cases/people';
import { update as updateStatus } from '@/routes/cases/status';
import { verification as verifyOutcome } from '@/routes/court-outcomes';
import { store as storeOutcome } from '@/routes/court-proceedings/outcomes';
import { legalHold, show as documentShow } from '@/routes/documents';
import { show as personShow } from '@/routes/persons';
import { name as searchByName } from '@/routes/search/people';

type Option = { value: string; label: string };
type Link_ = {
    id: number;
    person_id: string;
    full_name: string;
    birth_year: number | null;
    role: string;
    role_status: string | null;
    linked_at: string;
    unlinked_at: string | null;
    unlink_reason: string | null;
};
type Document_ = {
    id: string;
    name: string;
    category: string;
    size_bytes: number;
    scan_status: string;
    downloadable: boolean;
    previewable: boolean;
    legal_hold: boolean;
    uploaded_by: string;
    created_at: string | null;
};
type Outcome = {
    id: number;
    person: string;
    outcome: string;
    outcome_date: string;
    source: string;
    source_reference: string | null;
    verification_status: string;
    verification_label: string;
    recorded_by: string;
    recorded_by_me: boolean;
    verified_by: string | null;
    verification_note: string | null;
};
type Proceeding = {
    id: number;
    court_name: string;
    court_reference: string;
    status: string;
    filed_on: string | null;
    outcomes: Outcome[];
};
type PersonResult = {
    id: string;
    full_name: string;
    birth_year: number | null;
    station: string;
    match_reason: string;
};

const props = defineProps<{
    record: {
        id: string;
        reference: string;
        station: string;
        district: string;
        category_id: number;
        category: string;
        incident_date: string;
        incident_location: string;
        narrative: string;
        status: string;
        status_label: string;
        lifecycle: string;
        lifecycle_label: string;
        closed_at: string | null;
        created_at: string | null;
    };
    exceptionalAccess: { expires_at: string | null } | null;
    history: {
        id: number;
        type: string;
        summary: string;
        user: string;
        occurred_at: string;
    }[];
    people: Link_[];
    assignments: {
        id: number;
        name: string;
        job_title: string | null;
        is_lead: boolean;
        assigned_at: string;
        ended_at: string | null;
    }[];
    documents: Document_[];
    court: Proceeding[];
    options: {
        categories: { id: number; name: string }[];
        next_statuses: Option[];
        lifecycles: Option[];
        person_roles: Option[];
        officers: { id: number; name: string }[];
        document_categories: Option[];
        court_statuses: Option[];
        court_outcomes: Option[];
        purposes: Option[];
    };
    can: {
        correct: boolean;
        progress: boolean;
        assign: boolean;
        link_person: boolean;
        manage_lifecycle: boolean;
        print: boolean;
        upload_document: boolean;
        hold_documents: boolean;
        record_court: boolean;
        verify_court: boolean;
    };
}>();

const keepOpen = { preserveScroll: true };

/* Progress note */

const noteForm = useForm({ note: '' });

function submitNote(): void {
    noteForm.post(storeNote.url(props.record.id), {
        ...keepOpen,
        onSuccess: () => noteForm.reset(),
    });
}

/* Status and lifecycle */

const statusOpen = ref(false);
const statusForm = useForm({ status: '', reason: '' });

function submitStatus(): void {
    statusForm.put(updateStatus.url(props.record.id), {
        ...keepOpen,
        onSuccess: () => {
            statusOpen.value = false;
            statusForm.reset();
        },
    });
}

const lifecycleOpen = ref(false);
const lifecycleForm = useForm({
    lifecycle: props.record.lifecycle,
    reason: '',
});

function submitLifecycle(): void {
    lifecycleForm.put(updateLifecycle.url(props.record.id), {
        ...keepOpen,
        onSuccess: () => {
            lifecycleOpen.value = false;
            lifecycleForm.reset('reason');
        },
    });
}

/* Correction */

const correctOpen = ref(false);
const correctForm = useForm({
    offence_category_id: props.record.category_id,
    incident_date: props.record.incident_date,
    incident_location: props.record.incident_location,
    narrative: props.record.narrative,
    reason: '',
});

function submitCorrection(): void {
    correctForm.put(updateCase.url(props.record.id), {
        ...keepOpen,
        onSuccess: () => {
            correctOpen.value = false;
            correctForm.reset('reason');
        },
    });
}

/* Assignments */

const assignOpen = ref(false);
const assignForm = useForm({ user_id: null as number | null, is_lead: false });

function submitAssign(): void {
    assignForm.post(storeAssignment.url(props.record.id), {
        ...keepOpen,
        onSuccess: () => {
            assignOpen.value = false;
            assignForm.reset();
        },
    });
}

function confirmEndAssignment(assignmentId: number): void {
    ElMessageBox.confirm(
        'End this assignment? The officer loses access unless they have another route to this case.',
        'End assignment',
        {
            confirmButtonText: 'End assignment',
            cancelButtonText: 'Cancel',
            type: 'warning',
        },
    )
        .then(() =>
            router.delete(
                endAssignment.url({
                    case: props.record.id,
                    assignment: assignmentId,
                }),
                keepOpen,
            ),
        )
        .catch(() => undefined);
}

/* People */

const linkOpen = ref(false);
const lookup = useHttp<
    { name: string; purpose: string },
    { people: PersonResult[] }
>({
    name: '',
    purpose: '',
});
const lookupResults = ref<PersonResult[] | null>(null);
const linkForm = useForm({ person_id: '', role: '', role_status: '' });

function searchPeople(): void {
    void lookup.post(searchByName.url(), {
        onSuccess: (response) => {
            lookupResults.value = response.people;
        },
    });
}

function submitLink(): void {
    linkForm.post(linkPerson.url(props.record.id), {
        ...keepOpen,
        onSuccess: () => {
            linkOpen.value = false;
            linkForm.reset();
            lookup.reset();
            lookupResults.value = null;
        },
    });
}

const unlinking = ref<number | null>(null);
const unlinkOpen = ref(false);
const unlinkForm = useForm({ reason: '' });

function startUnlink(linkId: number): void {
    unlinking.value = linkId;
    unlinkForm.clearErrors();
    unlinkOpen.value = true;
}

function submitUnlink(reason: string): void {
    if (unlinking.value === null) {
        return;
    }

    unlinkForm.reason = reason;
    unlinkForm.delete(
        unlinkPerson.url({ case: props.record.id, link: unlinking.value }),
        {
            ...keepOpen,
            onSuccess: () => {
                unlinkOpen.value = false;
            },
        },
    );
}

/* Documents */

const uploadOpen = ref(false);
const uploadForm = useForm({ category: '', file: null as File | null });

function fileChosen(event: Event): void {
    uploadForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitUpload(): void {
    uploadForm.post(storeDocument.url(props.record.id), {
        ...keepOpen,
        forceFormData: true,
        onSuccess: () => {
            uploadOpen.value = false;
            uploadForm.reset();
        },
    });
}

const holding = ref<Document_ | null>(null);
const holdOpen = ref(false);
const holdForm = useForm({ legal_hold: false, reason: '' });

function startHold(document: Document_): void {
    holding.value = document;
    holdForm.clearErrors();
    holdOpen.value = true;
}

function submitHold(reason: string): void {
    if (!holding.value) {
        return;
    }

    holdForm.legal_hold = !holding.value.legal_hold;
    holdForm.reason = reason;
    holdForm.put(legalHold.url(holding.value.id), {
        ...keepOpen,
        onSuccess: () => {
            holdOpen.value = false;
        },
    });
}

function formatSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.ceil(bytes / 1024)} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/* Court */

const proceedingOpen = ref(false);
const proceedingForm = useForm({
    court_name: '',
    court_reference: '',
    status: '',
    filed_on: null as string | null,
});

function submitProceeding(): void {
    proceedingForm.post(storeProceeding.url(props.record.id), {
        ...keepOpen,
        onSuccess: () => {
            proceedingOpen.value = false;
            proceedingForm.reset();
        },
    });
}

const outcomeProceeding = ref<number | null>(null);
const outcomeOpen = ref(false);
const outcomeForm = useForm({
    case_person_id: null as number | null,
    outcome: '',
    outcome_date: null as string | null,
    source: '',
    source_reference: '',
});

function startOutcome(proceedingId: number): void {
    outcomeProceeding.value = proceedingId;
    outcomeForm.clearErrors();
    outcomeOpen.value = true;
}

function submitOutcome(): void {
    if (outcomeProceeding.value === null) {
        return;
    }

    outcomeForm.post(storeOutcome.url(outcomeProceeding.value), {
        ...keepOpen,
        onSuccess: () => {
            outcomeOpen.value = false;
            outcomeForm.reset();
        },
    });
}

const reviewing = ref<{ id: number; confirmed: boolean } | null>(null);
const reviewOpen = ref(false);
const reviewForm = useForm({ confirmed: false, note: '' });

function startReview(outcomeId: number, confirmed: boolean): void {
    reviewing.value = { id: outcomeId, confirmed };
    reviewForm.clearErrors();
    reviewOpen.value = true;
}

function submitReview(note: string): void {
    if (!reviewing.value) {
        return;
    }

    reviewForm.confirmed = reviewing.value.confirmed;
    reviewForm.note = note;
    reviewForm.put(verifyOutcome.url(reviewing.value.id), {
        ...keepOpen,
        onSuccess: () => {
            reviewOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="record.reference" />

    <div class="space-y-6">
        <PageHeader
            :title="record.reference"
            :description="`${record.station}, ${record.district}`"
        >
            <ElTag>{{ record.status_label }}</ElTag>
            <ElTag type="info">{{ record.lifecycle_label }}</ElTag>
            <a
                v-if="can.print"
                :href="printRoute.url(record.id)"
                target="_blank"
                rel="noopener"
            >
                <ElButton size="small">Print</ElButton>
            </a>
        </PageHeader>

        <ElAlert
            v-if="exceptionalAccess"
            type="warning"
            :closable="false"
            :title="`You have exceptional access to this case until ${formatDateTime(exceptionalAccess.expires_at)}. Your use of it is recorded and reviewed.`"
        />

        <div class="grid gap-6 lg:grid-cols-3">
            <ElCard shadow="never" class="lg:col-span-2">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-brand-900">Case details</h2>
                        <ElButton
                            v-if="can.correct"
                            size="small"
                            @click="correctOpen = true"
                            >Correct</ElButton
                        >
                    </div>
                </template>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="font-semibold text-slate-900">Category</dt>
                        <dd class="text-slate-600">{{ record.category }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-900">
                            Incident date
                        </dt>
                        <dd class="text-slate-600">
                            {{ record.incident_date }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-900">Location</dt>
                        <dd class="text-slate-600">
                            {{ record.incident_location }}
                        </dd>
                    </div>
                </dl>
                <h3 class="mt-5 text-sm font-semibold text-slate-900">
                    Narrative
                </h3>
                <p class="mt-1 text-sm whitespace-pre-wrap text-slate-700">
                    {{ record.narrative }}
                </p>
            </ElCard>

            <ElCard shadow="never">
                <template #header>
                    <h2 class="font-bold text-brand-900">Workflow</h2>
                </template>
                <div class="flex flex-col gap-2 [&>button]:ml-0!">
                    <ElButton
                        v-if="can.progress && options.next_statuses.length > 0"
                        @click="statusOpen = true"
                    >
                        Change status
                    </ElButton>
                    <ElButton
                        v-if="can.manage_lifecycle"
                        @click="lifecycleOpen = true"
                    >
                        Change record lifecycle
                    </ElButton>
                </div>
                <p class="mt-4 text-xs leading-5 text-slate-500">
                    Status is the police workflow only. Closing a case
                    administratively says nothing about conviction or acquittal.
                </p>
                <form
                    v-if="can.progress"
                    class="mt-4"
                    @submit.prevent="submitNote"
                >
                    <ElFormItem
                        label="Add progress note"
                        :error="noteForm.errors.note"
                        label-position="top"
                    >
                        <ElInput
                            v-model="noteForm.note"
                            type="textarea"
                            :rows="3"
                        />
                    </ElFormItem>
                    <ElButton
                        native-type="submit"
                        size="small"
                        type="primary"
                        :loading="noteForm.processing"
                    >
                        Add note
                    </ElButton>
                </form>
            </ElCard>
        </div>

        <ElCard shadow="never">
            <template #header>
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-brand-900">
                        People linked to this case
                    </h2>
                    <ElButton
                        v-if="can.link_person"
                        size="small"
                        @click="linkOpen = true"
                        >Link person</ElButton
                    >
                </div>
            </template>
            <ElTable :data="people" empty-text="No one is linked">
                <ElTableColumn label="Name" min-width="220">
                    <template #default="{ row }">
                        <Link
                            :href="personShow.url(row.person_id)"
                            class="font-semibold text-brand-700 hover:underline"
                        >
                            {{ row.full_name }}
                        </Link>
                    </template>
                </ElTableColumn>
                <ElTableColumn
                    prop="role"
                    label="Role in this case"
                    min-width="170"
                />
                <ElTableColumn label="Link" min-width="220">
                    <template #default="{ row }">
                        <template v-if="row.unlinked_at">
                            <ElTag size="small" type="info">Ended</ElTag>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ row.unlink_reason }}
                            </div>
                        </template>
                        <ElTag v-else size="small" type="success"
                            >Current</ElTag
                        >
                    </template>
                </ElTableColumn>
                <ElTableColumn v-if="can.link_person" width="90" align="right">
                    <template #default="{ row }">
                        <ElButton
                            v-if="!row.unlinked_at"
                            link
                            type="danger"
                            @click="startUnlink(row.id)"
                            >Unlink</ElButton
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElCard shadow="never">
            <template #header>
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-brand-900">Assigned officers</h2>
                    <ElButton
                        v-if="can.assign"
                        size="small"
                        @click="assignOpen = true"
                        >Assign officer</ElButton
                    >
                </div>
            </template>
            <ElTable :data="assignments" empty-text="No one has been assigned">
                <ElTableColumn label="Officer" min-width="220">
                    <template #default="{ row }">
                        {{ row.name }}
                        <ElTag v-if="row.is_lead" size="small" class="ml-1"
                            >Lead</ElTag
                        >
                        <div class="text-xs text-slate-500">
                            {{ row.job_title }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Assigned" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.assigned_at)
                    }}</template>
                </ElTableColumn>
                <ElTableColumn label="Ended" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.ended_at)
                    }}</template>
                </ElTableColumn>
                <ElTableColumn v-if="can.assign" width="90" align="right">
                    <template #default="{ row }">
                        <ElButton
                            v-if="!row.ended_at"
                            link
                            type="danger"
                            @click="confirmEndAssignment(row.id)"
                            >End</ElButton
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElCard shadow="never">
            <template #header>
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-brand-900">Documents</h2>
                    <ElButton
                        v-if="can.upload_document"
                        size="small"
                        @click="uploadOpen = true"
                        >Upload</ElButton
                    >
                </div>
            </template>
            <ElTable :data="documents" empty-text="No documents">
                <ElTableColumn label="Document" min-width="240">
                    <template #default="{ row }">
                        {{ row.name }}
                        <ElTag
                            v-if="row.legal_hold"
                            size="small"
                            type="warning"
                            class="ml-1"
                            >Legal hold</ElTag
                        >
                        <div class="text-xs text-slate-500">
                            {{ row.category }} ·
                            {{ formatSize(row.size_bytes) }} ·
                            {{ row.uploaded_by }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn
                    prop="scan_status"
                    label="Scan"
                    min-width="200"
                />
                <ElTableColumn width="230" align="right">
                    <template #default="{ row }">
                        <template v-if="row.downloadable">
                            <a
                                v-if="row.previewable"
                                :href="
                                    documentShow.url(row.id, {
                                        query: { preview: 1 },
                                    })
                                "
                                target="_blank"
                                rel="noopener"
                                class="mr-3 text-sm font-semibold text-brand-700 hover:underline"
                            >
                                Preview
                            </a>
                            <a
                                :href="documentShow.url(row.id)"
                                class="mr-3 text-sm font-semibold text-brand-700 hover:underline"
                            >
                                Download
                            </a>
                        </template>
                        <ElButton
                            v-if="can.hold_documents"
                            link
                            type="primary"
                            @click="startHold(row as Document_)"
                        >
                            {{ row.legal_hold ? 'Lift hold' : 'Hold' }}
                        </ElButton>
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElCard shadow="never">
            <template #header>
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-brand-900">Court record</h2>
                    <ElButton
                        v-if="can.record_court"
                        size="small"
                        @click="proceedingOpen = true"
                        >Add proceeding</ElButton
                    >
                </div>
            </template>
            <p class="mb-3 text-xs text-slate-500">
                Outcomes come from a named source and have no effect until a
                different officer verifies them.
            </p>
            <p v-if="court.length === 0" class="text-sm text-slate-500">
                No court record.
            </p>
            <div
                v-for="proceeding in court"
                :key="proceeding.id"
                class="mb-5 rounded-lg border border-slate-200 p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-semibold text-slate-900">{{
                            proceeding.court_name
                        }}</span>
                        <span class="crms-code ml-2 text-xs text-slate-500">{{
                            proceeding.court_reference
                        }}</span>
                        <ElTag size="small" type="info" class="ml-2">{{
                            proceeding.status
                        }}</ElTag>
                    </div>
                    <ElButton
                        v-if="can.record_court"
                        size="small"
                        @click="startOutcome(proceeding.id)"
                        >Record outcome</ElButton
                    >
                </div>
                <ElTable
                    :data="proceeding.outcomes"
                    empty-text="No outcomes"
                    class="mt-3"
                >
                    <ElTableColumn
                        prop="person"
                        label="Person"
                        min-width="180"
                    />
                    <ElTableColumn
                        prop="outcome"
                        label="Outcome"
                        min-width="150"
                    />
                    <ElTableColumn
                        prop="outcome_date"
                        label="Date"
                        width="120"
                    />
                    <ElTableColumn label="Source" min-width="180">
                        <template #default="{ row }">
                            {{ row.source }}
                            <div
                                v-if="row.source_reference"
                                class="text-xs text-slate-500"
                            >
                                {{ row.source_reference }}
                            </div>
                        </template>
                    </ElTableColumn>
                    <ElTableColumn label="Verification" min-width="200">
                        <template #default="{ row }">
                            <ElTag
                                size="small"
                                :type="
                                    row.verification_status === 'verified'
                                        ? 'success'
                                        : row.verification_status === 'rejected'
                                          ? 'danger'
                                          : 'warning'
                                "
                            >
                                {{ row.verification_label }}
                            </ElTag>
                            <div
                                v-if="row.verified_by"
                                class="mt-1 text-xs text-slate-500"
                            >
                                {{ row.verified_by }}:
                                {{ row.verification_note }}
                            </div>
                            <div
                                v-if="
                                    can.verify_court &&
                                    row.verification_status === 'pending' &&
                                    !row.recorded_by_me
                                "
                                class="mt-1"
                            >
                                <ElButton
                                    link
                                    type="primary"
                                    size="small"
                                    @click="startReview(row.id, true)"
                                    >Verify</ElButton
                                >
                                <ElButton
                                    link
                                    type="danger"
                                    size="small"
                                    @click="startReview(row.id, false)"
                                    >Reject</ElButton
                                >
                            </div>
                        </template>
                    </ElTableColumn>
                </ElTable>
            </div>
        </ElCard>

        <ElCard shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">History</h2>
            </template>
            <ElTimeline v-if="history.length > 0">
                <ElTimelineItem
                    v-for="event in history"
                    :key="event.id"
                    :timestamp="`${formatDateTime(event.occurred_at)} · ${event.user}`"
                >
                    <span class="font-semibold text-slate-900">{{
                        event.type
                    }}</span>
                    <p class="text-sm whitespace-pre-wrap text-slate-600">
                        {{ event.summary }}
                    </p>
                </ElTimelineItem>
            </ElTimeline>
            <p v-else class="text-sm text-slate-500">No history yet.</p>
        </ElCard>

        <ElDialog
            v-model="statusOpen"
            title="Change status"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitStatus">
                <ElFormItem
                    label="New status"
                    :error="statusForm.errors.status"
                    required
                >
                    <ElSelect v-model="statusForm.status" class="w-full">
                        <ElOption
                            v-for="option in options.next_statuses"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Reason (kept on record)"
                    :error="statusForm.errors.reason"
                    required
                >
                    <ElInput
                        v-model="statusForm.reason"
                        type="textarea"
                        :rows="2"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="statusOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="statusForm.processing"
                    @click="submitStatus"
                    >Save</ElButton
                >
            </template>
        </ElDialog>

        <ElDialog
            v-model="lifecycleOpen"
            title="Change record lifecycle"
            width="min(480px, 92vw)"
        >
            <ElAlert
                type="info"
                :closable="false"
                class="mb-4"
                title="An archived record is read-only. Restricted records are hidden from most of the station. Nothing is ever deleted from here."
            />
            <ElForm label-position="top" @submit.prevent="submitLifecycle">
                <ElFormItem
                    label="Lifecycle"
                    :error="lifecycleForm.errors.lifecycle"
                    required
                >
                    <ElSelect v-model="lifecycleForm.lifecycle" class="w-full">
                        <ElOption
                            v-for="option in options.lifecycles"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Reason (kept on record)"
                    :error="lifecycleForm.errors.reason"
                    required
                >
                    <ElInput
                        v-model="lifecycleForm.reason"
                        type="textarea"
                        :rows="2"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="lifecycleOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="lifecycleForm.processing"
                    @click="submitLifecycle"
                    >Save</ElButton
                >
            </template>
        </ElDialog>

        <ElDialog
            v-model="correctOpen"
            title="Correct case details"
            width="min(640px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitCorrection">
                <ElFormItem
                    label="Category"
                    :error="correctForm.errors.offence_category_id"
                    required
                >
                    <ElSelect
                        v-model="correctForm.offence_category_id"
                        filterable
                        class="w-full"
                    >
                        <ElOption
                            v-for="category in options.categories"
                            :key="category.id"
                            :label="category.name"
                            :value="category.id"
                        />
                    </ElSelect>
                </ElFormItem>
                <div class="grid gap-4 sm:grid-cols-2">
                    <ElFormItem
                        label="Incident date"
                        :error="correctForm.errors.incident_date"
                        required
                    >
                        <ElDatePicker
                            v-model="correctForm.incident_date"
                            type="date"
                            value-format="YYYY-MM-DD"
                            class="w-full!"
                        />
                    </ElFormItem>
                    <ElFormItem
                        label="Location"
                        :error="correctForm.errors.incident_location"
                        required
                    >
                        <ElInput v-model="correctForm.incident_location" />
                    </ElFormItem>
                </div>
                <ElFormItem
                    label="Narrative"
                    :error="correctForm.errors.narrative"
                    required
                >
                    <ElInput
                        v-model="correctForm.narrative"
                        type="textarea"
                        :rows="6"
                    />
                </ElFormItem>
                <ElFormItem
                    label="Reason (kept on record; the earlier text is kept too)"
                    :error="correctForm.errors.reason"
                    required
                >
                    <ElInput
                        v-model="correctForm.reason"
                        type="textarea"
                        :rows="2"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="correctOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="correctForm.processing"
                    @click="submitCorrection"
                    >Save correction</ElButton
                >
            </template>
        </ElDialog>

        <ElDialog
            v-model="assignOpen"
            title="Assign officer"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitAssign">
                <ElFormItem
                    label="Officer"
                    :error="assignForm.errors.user_id"
                    required
                >
                    <ElSelect
                        v-model="assignForm.user_id"
                        filterable
                        class="w-full"
                    >
                        <ElOption
                            v-for="officer in options.officers"
                            :key="officer.id"
                            :label="officer.name"
                            :value="officer.id"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElCheckbox v-model="assignForm.is_lead"
                    >Lead investigator</ElCheckbox
                >
            </ElForm>
            <template #footer>
                <ElButton @click="assignOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="assignForm.processing"
                    @click="submitAssign"
                    >Assign</ElButton
                >
            </template>
        </ElDialog>

        <ElDialog
            v-model="linkOpen"
            title="Link person"
            width="min(560px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="searchPeople">
                <div class="grid gap-3 sm:grid-cols-2">
                    <ElFormItem
                        label="Name"
                        :error="lookup.errors.name"
                        required
                    >
                        <ElInput v-model="lookup.name" />
                    </ElFormItem>
                    <ElFormItem
                        label="Purpose of this search"
                        :error="lookup.errors.purpose"
                        required
                    >
                        <ElSelect v-model="lookup.purpose" class="w-full">
                            <ElOption
                                v-for="option in options.purposes"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </ElSelect>
                    </ElFormItem>
                </div>
                <ElButton native-type="submit" :loading="lookup.processing"
                    >Search</ElButton
                >
            </ElForm>
            <template v-if="lookupResults">
                <p
                    v-if="lookupResults.length === 0"
                    class="mt-4 text-sm text-slate-500"
                >
                    No one you can see matches. Register them first if they are
                    new.
                </p>
                <ul v-else class="mt-4 max-h-56 space-y-2 overflow-y-auto">
                    <li v-for="person in lookupResults" :key="person.id">
                        <button
                            type="button"
                            class="w-full rounded-lg border p-3 text-left text-sm hover:bg-brand-50"
                            :class="
                                linkForm.person_id === person.id
                                    ? 'border-brand-600 bg-brand-50'
                                    : 'border-slate-200'
                            "
                            @click="linkForm.person_id = person.id"
                        >
                            <span class="font-semibold">{{
                                person.full_name
                            }}</span>
                            <span class="block text-xs text-slate-500">
                                Born {{ person.birth_year ?? 'unknown' }} ·
                                {{ person.station }}. {{ person.match_reason }}
                            </span>
                        </button>
                    </li>
                </ul>
                <ElForm
                    v-if="lookupResults.length > 0"
                    label-position="top"
                    class="mt-4"
                    @submit.prevent="submitLink"
                >
                    <ElFormItem
                        label="Role in this case"
                        :error="
                            linkForm.errors.role || linkForm.errors.person_id
                        "
                        required
                    >
                        <ElSelect v-model="linkForm.role" class="w-full">
                            <ElOption
                                v-for="option in options.person_roles"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </ElSelect>
                    </ElFormItem>
                </ElForm>
            </template>
            <template #footer>
                <ElButton @click="linkOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :disabled="!linkForm.person_id || !linkForm.role"
                    :loading="linkForm.processing"
                    @click="submitLink"
                >
                    Link
                </ElButton>
            </template>
        </ElDialog>

        <ElDialog
            v-model="uploadOpen"
            title="Upload document"
            width="min(480px, 92vw)"
        >
            <ElAlert
                type="info"
                :closable="false"
                class="mb-4"
                title="PDF, JPEG, PNG or plain text. Files are encrypted and can be opened once the malware scan has finished."
            />
            <ElForm label-position="top" @submit.prevent="submitUpload">
                <ElFormItem
                    label="Category"
                    :error="uploadForm.errors.category"
                    required
                >
                    <ElSelect v-model="uploadForm.category" class="w-full">
                        <ElOption
                            v-for="option in options.document_categories"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="File"
                    :error="uploadForm.errors.file"
                    required
                >
                    <input
                        type="file"
                        aria-label="File"
                        accept=".pdf,.jpg,.jpeg,.png,.txt"
                        @change="fileChosen"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="uploadOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="uploadForm.processing"
                    @click="submitUpload"
                    >Upload</ElButton
                >
            </template>
        </ElDialog>

        <ElDialog
            v-model="proceedingOpen"
            title="Add court proceeding"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitProceeding">
                <ElFormItem
                    label="Court"
                    :error="proceedingForm.errors.court_name"
                    required
                >
                    <ElInput v-model="proceedingForm.court_name" />
                </ElFormItem>
                <ElFormItem
                    label="Court reference"
                    :error="proceedingForm.errors.court_reference"
                    required
                >
                    <ElInput v-model="proceedingForm.court_reference" />
                </ElFormItem>
                <ElFormItem
                    label="Status"
                    :error="proceedingForm.errors.status"
                    required
                >
                    <ElSelect v-model="proceedingForm.status" class="w-full">
                        <ElOption
                            v-for="option in options.court_statuses"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Filed on"
                    :error="proceedingForm.errors.filed_on"
                >
                    <ElDatePicker
                        v-model="proceedingForm.filed_on"
                        type="date"
                        value-format="YYYY-MM-DD"
                        class="w-full!"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="proceedingOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="proceedingForm.processing"
                    @click="submitProceeding"
                    >Add</ElButton
                >
            </template>
        </ElDialog>

        <ElDialog
            v-model="outcomeOpen"
            title="Record court outcome"
            width="min(520px, 92vw)"
        >
            <ElAlert
                type="warning"
                :closable="false"
                class="mb-4"
                title="Record only what an authorised source says. Nothing changes until another officer verifies it."
            />
            <ElForm label-position="top" @submit.prevent="submitOutcome">
                <ElFormItem
                    label="Person"
                    :error="outcomeForm.errors.case_person_id"
                    required
                >
                    <ElSelect
                        v-model="outcomeForm.case_person_id"
                        class="w-full"
                    >
                        <ElOption
                            v-for="link in people.filter(
                                (candidate) => !candidate.unlinked_at,
                            )"
                            :key="link.id"
                            :label="`${link.full_name} (${link.role})`"
                            :value="link.id"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Outcome"
                    :error="outcomeForm.errors.outcome"
                    required
                >
                    <ElSelect v-model="outcomeForm.outcome" class="w-full">
                        <ElOption
                            v-for="option in options.court_outcomes"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Date of outcome"
                    :error="outcomeForm.errors.outcome_date"
                    required
                >
                    <ElDatePicker
                        v-model="outcomeForm.outcome_date"
                        type="date"
                        value-format="YYYY-MM-DD"
                        class="w-full!"
                    />
                </ElFormItem>
                <ElFormItem
                    label="Source"
                    :error="outcomeForm.errors.source"
                    required
                >
                    <ElInput
                        v-model="outcomeForm.source"
                        placeholder="For example: certified court record"
                    />
                </ElFormItem>
                <ElFormItem
                    label="Source reference"
                    :error="outcomeForm.errors.source_reference"
                >
                    <ElInput v-model="outcomeForm.source_reference" />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="outcomeOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="outcomeForm.processing"
                    @click="submitOutcome"
                    >Record</ElButton
                >
            </template>
        </ElDialog>

        <ReasonDialog
            v-model="unlinkOpen"
            title="Unlink person"
            confirm-text="Unlink"
            danger
            warning="The link stays in the case history."
            :error="unlinkForm.errors.reason"
            :processing="unlinkForm.processing"
            @confirm="submitUnlink"
        />
        <ReasonDialog
            v-model="holdOpen"
            :title="
                holding?.legal_hold ? 'Lift legal hold' : 'Apply legal hold'
            "
            :error="holdForm.errors.reason"
            :processing="holdForm.processing"
            @confirm="submitHold"
        />
        <ReasonDialog
            v-model="reviewOpen"
            :title="reviewing?.confirmed ? 'Verify outcome' : 'Reject outcome'"
            label="What did you check? (kept on record)"
            :confirm-text="reviewing?.confirmed ? 'Verify' : 'Reject'"
            :danger="!reviewing?.confirmed"
            :warning="
                reviewing?.confirmed
                    ? 'Verifying takes effect immediately. A verified conviction outcome creates a conviction record.'
                    : undefined
            "
            :error="reviewForm.errors.note"
            :processing="reviewForm.processing"
            @confirm="submitReview"
        />
    </div>
</template>
