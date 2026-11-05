<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCard,
    ElDialog,
    ElForm,
    ElFormItem,
    ElInput,
    ElOption,
    ElSelect,
    ElTable,
    ElTableColumn,
    ElTag,
} from 'element-plus';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { formatDateTime } from '@/lib/utils';
import { show as caseShow } from '@/routes/cases';
import {
    reveal as revealRoute,
    show as personShow,
    update,
} from '@/routes/persons';
import { store as storeAddress } from '@/routes/persons/addresses';
import { store as storeContact } from '@/routes/persons/contacts';
import {
    store as storeIdentity,
    verification as verificationRoute,
} from '@/routes/persons/identities';

type Option = { value: string; label: string };
type Identity = {
    id: number;
    type: string;
    masked: string;
    issuing_country: string | null;
    issuing_authority: string | null;
    verification_status: string;
    verification_label: string;
    verification_source: string | null;
    verified_by: string | null;
    verified_at: string | null;
};
type HeldDetail = {
    id: number;
    type: string;
    masked: string;
    valid_from: string | null;
    valid_to: string | null;
};
type RevealKind = 'identity' | 'contact' | 'address' | 'date_of_birth';

const props = defineProps<{
    person: {
        id: string;
        full_name: string;
        given_names: string;
        family_name: string;
        other_names: string | null;
        sex: string | null;
        sex_label: string | null;
        birth_year: number | null;
        has_date_of_birth: boolean;
        nationality: string | null;
        occupation: string | null;
        station: string;
        district: string;
        merged_into_id: string | null;
    };
    identities: Identity[];
    contacts: HeldDetail[];
    addresses: HeldDetail[];
    caseLinks: {
        id: number;
        case_id: string;
        reference: string;
        case_status: string;
        role: string;
        role_status: string | null;
        linked_at: string;
        unlinked_at: string | null;
    }[];
    corrections: {
        id: number;
        field: string;
        reason: string;
        corrected_by: string;
        corrected_at: string;
    }[];
    options: {
        sexes: Option[];
        identity_types: Option[];
        contact_types: Option[];
        address_types: Option[];
        verification_statuses: Option[];
        purposes: Option[];
    };
    can: {
        correct: boolean;
        add_details: boolean;
        verify_identity: boolean;
        reveal: boolean;
    };
}>();

/* Controlled reveal: the value is fetched only on request and dropped on close. */

const revealOpen = ref(false);
const revealedValue = ref<string | null>(null);
const revealTarget = ref<{
    kind: RevealKind;
    record_id: number | null;
    label: string;
} | null>(null);
const revealHttp = useHttp<
    {
        kind: RevealKind;
        record_id: number | null;
        purpose: string;
        explanation: string;
        password: string;
    },
    { value: string }
>({
    kind: 'identity',
    record_id: null,
    purpose: '',
    explanation: '',
    password: '',
});

function startReveal(
    kind: RevealKind,
    recordId: number | null,
    label: string,
): void {
    revealTarget.value = { kind, record_id: recordId, label };
    revealedValue.value = null;
    revealHttp.reset();
    revealHttp.clearErrors();
    revealHttp.kind = kind;
    revealHttp.record_id = recordId;
    revealOpen.value = true;
}

function submitReveal(): void {
    void revealHttp.post(revealRoute.url(props.person.id), {
        onSuccess: (response) => {
            revealedValue.value = response.value;
            revealHttp.reset('password');
        },
        onError: () => revealHttp.reset('password'),
    });
}

function closeReveal(): void {
    revealedValue.value = null;
    revealHttp.reset();
}

/* Correction */

const correctOpen = ref(false);
const correctForm = useForm({
    given_names: props.person.given_names,
    family_name: props.person.family_name,
    other_names: props.person.other_names ?? '',
    sex: props.person.sex,
    nationality: props.person.nationality ?? '',
    occupation: props.person.occupation ?? '',
    reason: '',
});

function submitCorrection(): void {
    correctForm.put(update.url(props.person.id), {
        preserveScroll: true,
        onSuccess: () => {
            correctOpen.value = false;
            correctForm.reset('reason');
        },
    });
}

/* Adding details */

const identityOpen = ref(false);
const identityForm = useForm({
    type: '',
    identity_number: '',
    issuing_country: '',
    issuing_authority: '',
});

function submitIdentity(): void {
    identityForm.post(storeIdentity.url(props.person.id), {
        preserveScroll: true,
        onSuccess: () => {
            identityOpen.value = false;
            identityForm.reset();
        },
        onError: () => identityForm.reset('identity_number'),
    });
}

const contactOpen = ref(false);
const contactForm = useForm({ type: '', value: '' });

function submitContact(): void {
    contactForm.post(storeContact.url(props.person.id), {
        preserveScroll: true,
        onSuccess: () => {
            contactOpen.value = false;
            contactForm.reset();
        },
    });
}

const addressOpen = ref(false);
const addressForm = useForm({ type: '', address: '' });

function submitAddress(): void {
    addressForm.post(storeAddress.url(props.person.id), {
        preserveScroll: true,
        onSuccess: () => {
            addressOpen.value = false;
            addressForm.reset();
        },
    });
}

/* Verification */

const verifying = ref<Identity | null>(null);
const verifyOpen = ref(false);
const verifyForm = useForm({ status: '', source: '' });

function startVerify(identity: Identity): void {
    verifying.value = identity;
    verifyForm.status = identity.verification_status;
    verifyForm.source = identity.verification_source ?? '';
    verifyForm.clearErrors();
    verifyOpen.value = true;
}

function submitVerify(): void {
    if (!verifying.value) {
        return;
    }

    verifyForm.put(
        verificationRoute.url({
            person: props.person.id,
            identity: verifying.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                verifyOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Person record" />

    <div class="space-y-6">
        <PageHeader
            :title="person.full_name"
            :description="`Registered at ${person.station}, ${person.district}`"
        >
            <ElButton v-if="can.correct" @click="correctOpen = true"
                >Correct details</ElButton
            >
        </PageHeader>

        <ElAlert
            v-if="person.merged_into_id"
            type="warning"
            :closable="false"
            title="This record was merged into another. Its history is kept here."
        >
            <Link
                :href="personShow.url(person.merged_into_id)"
                class="font-semibold underline"
            >
                Go to the current record
            </Link>
        </ElAlert>

        <div class="grid gap-6 lg:grid-cols-3">
            <ElCard shadow="never">
                <template #header>
                    <h2 class="font-bold text-brand-900">Details</h2>
                </template>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="font-semibold text-slate-900">
                            Date of birth
                        </dt>
                        <dd class="flex items-center gap-2 text-slate-600">
                            {{
                                person.birth_year
                                    ? `Born ${person.birth_year}`
                                    : 'Not recorded'
                            }}
                            <ElButton
                                v-if="can.reveal && person.has_date_of_birth"
                                link
                                type="primary"
                                size="small"
                                @click="
                                    startReveal(
                                        'date_of_birth',
                                        null,
                                        'Date of birth',
                                    )
                                "
                            >
                                Reveal
                            </ElButton>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-900">Sex</dt>
                        <dd class="text-slate-600">
                            {{ person.sex_label ?? 'Not recorded' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-900">
                            Nationality
                        </dt>
                        <dd class="text-slate-600">
                            {{ person.nationality ?? 'Not recorded' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-900">Occupation</dt>
                        <dd class="text-slate-600">
                            {{ person.occupation ?? 'Not recorded' }}
                        </dd>
                    </div>
                </dl>
            </ElCard>

            <ElCard shadow="never" class="lg:col-span-2">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-brand-900">
                            Identity documents
                        </h2>
                        <ElButton
                            v-if="can.add_details"
                            size="small"
                            @click="identityOpen = true"
                        >
                            Add
                        </ElButton>
                    </div>
                </template>
                <ElTable :data="identities" empty-text="None on record">
                    <ElTableColumn prop="type" label="Type" min-width="150" />
                    <ElTableColumn label="Number" min-width="190">
                        <template #default="{ row }">
                            <span class="crms-code">{{ row.masked }}</span>
                            <ElButton
                                v-if="can.reveal"
                                link
                                type="primary"
                                size="small"
                                class="ml-2"
                                @click="
                                    startReveal('identity', row.id, row.type)
                                "
                            >
                                Reveal
                            </ElButton>
                        </template>
                    </ElTableColumn>
                    <ElTableColumn label="Verification" min-width="210">
                        <template #default="{ row }">
                            <ElTag
                                size="small"
                                :type="
                                    row.verification_status === 'verified'
                                        ? 'success'
                                        : row.verification_status === 'failed'
                                          ? 'danger'
                                          : 'info'
                                "
                            >
                                {{ row.verification_label }}
                            </ElTag>
                            <div
                                v-if="row.verified_by"
                                class="mt-1 text-xs text-slate-500"
                            >
                                {{ row.verification_source }}, by
                                {{ row.verified_by }},
                                {{ formatDateTime(row.verified_at) }}
                            </div>
                            <ElButton
                                v-if="can.verify_identity"
                                link
                                type="primary"
                                size="small"
                                @click="startVerify(row as Identity)"
                            >
                                Update
                            </ElButton>
                        </template>
                    </ElTableColumn>
                </ElTable>
            </ElCard>

            <ElCard shadow="never">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-brand-900">
                            Contact details
                        </h2>
                        <ElButton
                            v-if="can.add_details"
                            size="small"
                            @click="contactOpen = true"
                        >
                            Add
                        </ElButton>
                    </div>
                </template>
                <ul v-if="contacts.length > 0" class="space-y-2 text-sm">
                    <li v-for="contact in contacts" :key="contact.id">
                        <span class="font-semibold text-slate-900"
                            >{{ contact.type }}:</span
                        >
                        <span class="crms-code ml-1">{{ contact.masked }}</span>
                        <ElButton
                            v-if="can.reveal"
                            link
                            type="primary"
                            size="small"
                            class="ml-2"
                            @click="
                                startReveal('contact', contact.id, contact.type)
                            "
                        >
                            Reveal
                        </ElButton>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-500">None on record.</p>
            </ElCard>

            <ElCard shadow="never" class="lg:col-span-2">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-brand-900">
                            Address history
                        </h2>
                        <ElButton
                            v-if="can.add_details"
                            size="small"
                            @click="addressOpen = true"
                        >
                            Add
                        </ElButton>
                    </div>
                </template>
                <ul v-if="addresses.length > 0" class="space-y-2 text-sm">
                    <li v-for="address in addresses" :key="address.id">
                        <span class="font-semibold text-slate-900"
                            >{{ address.type }}:</span
                        >
                        <span class="crms-code ml-1">{{ address.masked }}</span>
                        <ElButton
                            v-if="can.reveal"
                            link
                            type="primary"
                            size="small"
                            class="ml-2"
                            @click="
                                startReveal('address', address.id, address.type)
                            "
                        >
                            Reveal
                        </ElButton>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-500">None on record.</p>
            </ElCard>
        </div>

        <ElCard shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">
                    Roles in cases you can see
                </h2>
            </template>
            <p class="mb-3 text-xs text-slate-500">
                A role is not a finding. Only a verified court outcome records a
                conviction.
            </p>
            <ElTable
                :data="caseLinks"
                empty-text="Not linked to any case you can see"
            >
                <ElTableColumn label="Case" min-width="220">
                    <template #default="{ row }">
                        <Link
                            :href="caseShow.url(row.case_id)"
                            class="crms-code font-semibold text-brand-700 hover:underline"
                        >
                            {{ row.reference }}
                        </Link>
                    </template>
                </ElTableColumn>
                <ElTableColumn prop="role" label="Role" min-width="150" />
                <ElTableColumn
                    prop="case_status"
                    label="Case status"
                    min-width="170"
                />
                <ElTableColumn label="Link" width="170">
                    <template #default="{ row }">
                        <ElTag v-if="row.unlinked_at" size="small" type="info"
                            >Ended</ElTag
                        >
                        <ElTag v-else size="small" type="success"
                            >Current</ElTag
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElCard v-if="corrections.length > 0" shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">Corrections</h2>
            </template>
            <ElTable :data="corrections">
                <ElTableColumn prop="field" label="Field" min-width="150" />
                <ElTableColumn prop="reason" label="Reason" min-width="280" />
                <ElTableColumn prop="corrected_by" label="By" min-width="170" />
                <ElTableColumn label="When" width="190">
                    <template #default="{ row }">{{
                        formatDateTime(row.corrected_at)
                    }}</template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElDialog
            v-model="revealOpen"
            :title="`Reveal: ${revealTarget?.label ?? ''}`"
            width="min(480px, 92vw)"
            @closed="closeReveal"
        >
            <template v-if="revealedValue">
                <p
                    class="crms-code rounded-lg bg-slate-50 p-4 text-lg break-all"
                >
                    {{ revealedValue }}
                </p>
                <p class="mt-3 text-xs text-slate-500">
                    This has been recorded. Close this window when you have
                    finished.
                </p>
            </template>
            <ElForm v-else label-position="top" @submit.prevent="submitReveal">
                <ElAlert
                    type="warning"
                    :closable="false"
                    class="mb-4"
                    title="Each reveal is recorded with your name, the time and your purpose."
                />
                <ElFormItem
                    label="Purpose"
                    :error="revealHttp.errors.purpose"
                    required
                >
                    <ElSelect v-model="revealHttp.purpose" class="w-full">
                        <ElOption
                            v-for="option in options.purposes"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Explanation (if needed)"
                    :error="revealHttp.errors.explanation"
                >
                    <ElInput
                        v-model="revealHttp.explanation"
                        type="textarea"
                        :rows="2"
                    />
                </ElFormItem>
                <ElFormItem
                    label="Your password"
                    :error="revealHttp.errors.password"
                    required
                >
                    <ElInput
                        v-model="revealHttp.password"
                        type="password"
                        show-password
                        autocomplete="current-password"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="revealOpen = false">Close</ElButton>
                <ElButton
                    v-if="!revealedValue"
                    type="primary"
                    :loading="revealHttp.processing"
                    @click="submitReveal"
                >
                    Reveal
                </ElButton>
            </template>
        </ElDialog>

        <ElDialog
            v-model="correctOpen"
            title="Correct details"
            width="min(560px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitCorrection">
                <div class="grid gap-4 sm:grid-cols-2">
                    <ElFormItem
                        label="Given names"
                        :error="correctForm.errors.given_names"
                        required
                    >
                        <ElInput v-model="correctForm.given_names" />
                    </ElFormItem>
                    <ElFormItem
                        label="Family name"
                        :error="correctForm.errors.family_name"
                        required
                    >
                        <ElInput v-model="correctForm.family_name" />
                    </ElFormItem>
                    <ElFormItem
                        label="Other names"
                        :error="correctForm.errors.other_names"
                    >
                        <ElInput v-model="correctForm.other_names" />
                    </ElFormItem>
                    <ElFormItem label="Sex" :error="correctForm.errors.sex">
                        <ElSelect
                            v-model="correctForm.sex"
                            clearable
                            class="w-full"
                        >
                            <ElOption
                                v-for="option in options.sexes"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </ElSelect>
                    </ElFormItem>
                    <ElFormItem
                        label="Nationality"
                        :error="correctForm.errors.nationality"
                    >
                        <ElInput v-model="correctForm.nationality" />
                    </ElFormItem>
                    <ElFormItem
                        label="Occupation"
                        :error="correctForm.errors.occupation"
                    >
                        <ElInput v-model="correctForm.occupation" />
                    </ElFormItem>
                </div>
                <ElFormItem
                    label="Reason (kept on record)"
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
                >
                    Save correction
                </ElButton>
            </template>
        </ElDialog>

        <ElDialog
            v-model="identityOpen"
            title="Add identity document"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitIdentity">
                <ElFormItem
                    label="Type"
                    :error="identityForm.errors.type"
                    required
                >
                    <ElSelect v-model="identityForm.type" class="w-full">
                        <ElOption
                            v-for="option in options.identity_types"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Number"
                    :error="identityForm.errors.identity_number"
                    required
                >
                    <ElInput
                        v-model="identityForm.identity_number"
                        autocomplete="off"
                    />
                </ElFormItem>
                <ElFormItem
                    label="Issuing country"
                    :error="identityForm.errors.issuing_country"
                >
                    <ElInput v-model="identityForm.issuing_country" />
                </ElFormItem>
                <ElFormItem
                    label="Issuing authority"
                    :error="identityForm.errors.issuing_authority"
                >
                    <ElInput v-model="identityForm.issuing_authority" />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="identityOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="identityForm.processing"
                    @click="submitIdentity"
                >
                    Add as unverified
                </ElButton>
            </template>
        </ElDialog>

        <ElDialog
            v-model="contactOpen"
            title="Add contact detail"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitContact">
                <ElFormItem
                    label="Type"
                    :error="contactForm.errors.type"
                    required
                >
                    <ElSelect v-model="contactForm.type" class="w-full">
                        <ElOption
                            v-for="option in options.contact_types"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Value"
                    :error="contactForm.errors.value"
                    required
                >
                    <ElInput v-model="contactForm.value" autocomplete="off" />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="contactOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="contactForm.processing"
                    @click="submitContact"
                >
                    Add
                </ElButton>
            </template>
        </ElDialog>

        <ElDialog
            v-model="addressOpen"
            title="Add address"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submitAddress">
                <ElFormItem
                    label="Type"
                    :error="addressForm.errors.type"
                    required
                >
                    <ElSelect v-model="addressForm.type" class="w-full">
                        <ElOption
                            v-for="option in options.address_types"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Address"
                    :error="addressForm.errors.address"
                    required
                >
                    <ElInput
                        v-model="addressForm.address"
                        type="textarea"
                        :rows="3"
                        autocomplete="off"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="addressOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="addressForm.processing"
                    @click="submitAddress"
                >
                    Add
                </ElButton>
            </template>
        </ElDialog>

        <ElDialog
            v-model="verifyOpen"
            title="Update verification"
            width="min(480px, 92vw)"
        >
            <ElAlert
                type="info"
                :closable="false"
                class="mb-4"
                title="Mark as verified only when an authorised process has actually confirmed this identity."
            />
            <ElForm label-position="top" @submit.prevent="submitVerify">
                <ElFormItem
                    label="Status"
                    :error="verifyForm.errors.status"
                    required
                >
                    <ElSelect v-model="verifyForm.status" class="w-full">
                        <ElOption
                            v-for="option in options.verification_statuses"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem label="Source" :error="verifyForm.errors.source">
                    <ElInput v-model="verifyForm.source" />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="verifyOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="verifyForm.processing"
                    @click="submitVerify"
                >
                    Save
                </ElButton>
            </template>
        </ElDialog>
    </div>
</template>
