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
    ElTabPane,
    ElTabs,
    ElTag,
} from 'element-plus';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { store as storeAccessRequest } from '@/routes/access-requests';
import { show as caseShow } from '@/routes/cases';
import { show as personShow } from '@/routes/persons';
import { cases as searchCases } from '@/routes/search';
import {
    identity as searchIdentity,
    name as searchName,
} from '@/routes/search/people';

type Option = { value: string; label: string };
type CaseResult = {
    id: string;
    reference: string;
    station: string;
    category: string;
    status: string;
};
type PersonResult = {
    id: string;
    full_name: string;
    birth_year: number | null;
    station: string;
    match_reason: string;
    verification: string | null;
};

defineProps<{
    identityTypes: Option[];
    purposes: Option[];
    stations: { id: number; name: string }[];
    can: {
        search_identifiers: boolean;
        search_people: boolean;
        request_access: boolean;
    };
}>();

/*
 * Searches are POSTed, and results stay in this page's memory, so terms never
 * reach a URL, the browser history or a server log.
 */
const caseHttp = useHttp<{ reference: string }, { cases: CaseResult[] }>({
    reference: '',
});
const caseResults = ref<CaseResult[] | null>(null);

function runCaseSearch(): void {
    void caseHttp.post(searchCases.url(), {
        onSuccess: (response) => {
            caseResults.value = response.cases;
        },
    });
}

const identityHttp = useHttp<
    {
        type: string;
        identity_number: string;
        purpose: string;
        case_reference: string;
    },
    { people: PersonResult[] }
>({ type: '', identity_number: '', purpose: '', case_reference: '' });

const nameHttp = useHttp<
    {
        name: string;
        birth_year: string;
        station_id: number | null;
        purpose: string;
        case_reference: string;
    },
    { people: PersonResult[] }
>({
    name: '',
    birth_year: '',
    station_id: null,
    purpose: '',
    case_reference: '',
});

const peopleResults = ref<PersonResult[] | null>(null);

function runIdentitySearch(): void {
    void identityHttp.post(searchIdentity.url(), {
        onSuccess: (response) => {
            peopleResults.value = response.people;
            identityHttp.reset('identity_number');
        },
    });
}

function runNameSearch(): void {
    void nameHttp.post(searchName.url(), {
        onSuccess: (response) => {
            peopleResults.value = response.people;
        },
    });
}

const requestOpen = ref(false);
const requestForm = useForm({ reference: '', purpose: '', explanation: '' });

function submitRequest(): void {
    requestForm.post(storeAccessRequest.url(), {
        preserveScroll: true,
        onSuccess: () => {
            requestOpen.value = false;
            requestForm.reset();
        },
    });
}
</script>

<template>
    <Head title="Search" />

    <div class="space-y-6">
        <PageHeader
            title="Search"
            description="Results only include records you are authorised to see. Every search is recorded."
        >
            <ElButton v-if="can.request_access" @click="requestOpen = true">
                Request access to a case
            </ElButton>
        </PageHeader>

        <ElCard shadow="never">
            <ElTabs>
                <ElTabPane label="Case reference">
                    <form
                        class="flex max-w-xl gap-2"
                        @submit.prevent="runCaseSearch"
                    >
                        <ElFormItem
                            :error="caseHttp.errors.reference"
                            class="mb-0 flex-1"
                        >
                            <ElInput
                                v-model="caseHttp.reference"
                                placeholder="CRMS/STATION/2026/000123"
                                aria-label="Case reference"
                            />
                        </ElFormItem>
                        <ElButton
                            type="primary"
                            native-type="submit"
                            :loading="caseHttp.processing"
                            >Search</ElButton
                        >
                    </form>

                    <ElTable
                        v-if="caseResults"
                        :data="caseResults"
                        empty-text="No cases you can see match"
                        class="mt-4"
                    >
                        <ElTableColumn label="Reference" min-width="240">
                            <template #default="{ row }">
                                <Link
                                    :href="caseShow.url(row.id)"
                                    class="crms-code font-semibold text-brand-700 hover:underline"
                                >
                                    {{ row.reference }}
                                </Link>
                            </template>
                        </ElTableColumn>
                        <ElTableColumn
                            prop="station"
                            label="Station"
                            min-width="160"
                        />
                        <ElTableColumn
                            prop="category"
                            label="Category"
                            min-width="180"
                        />
                        <ElTableColumn
                            prop="status"
                            label="Status"
                            min-width="170"
                        />
                    </ElTable>
                </ElTabPane>

                <ElTabPane
                    v-if="can.search_identifiers"
                    label="Identity number"
                >
                    <ElForm
                        label-position="top"
                        class="max-w-xl"
                        @submit.prevent="runIdentitySearch"
                    >
                        <div class="grid gap-3 sm:grid-cols-2">
                            <ElFormItem
                                label="Document type"
                                :error="identityHttp.errors.type"
                                required
                            >
                                <ElSelect
                                    v-model="identityHttp.type"
                                    class="w-full"
                                >
                                    <ElOption
                                        v-for="option in identityTypes"
                                        :key="option.value"
                                        :label="option.label"
                                        :value="option.value"
                                    />
                                </ElSelect>
                            </ElFormItem>
                            <ElFormItem
                                label="Number"
                                :error="identityHttp.errors.identity_number"
                                required
                            >
                                <ElInput
                                    v-model="identityHttp.identity_number"
                                    autocomplete="off"
                                />
                            </ElFormItem>
                            <ElFormItem
                                label="Purpose"
                                :error="identityHttp.errors.purpose"
                                required
                            >
                                <ElSelect
                                    v-model="identityHttp.purpose"
                                    class="w-full"
                                >
                                    <ElOption
                                        v-for="option in purposes"
                                        :key="option.value"
                                        :label="option.label"
                                        :value="option.value"
                                    />
                                </ElSelect>
                            </ElFormItem>
                            <ElFormItem
                                label="Related case reference"
                                :error="identityHttp.errors.case_reference"
                            >
                                <ElInput
                                    v-model="identityHttp.case_reference"
                                />
                            </ElFormItem>
                        </div>
                        <ElButton
                            type="primary"
                            native-type="submit"
                            :loading="identityHttp.processing"
                            >Search</ElButton
                        >
                    </ElForm>
                </ElTabPane>

                <ElTabPane v-if="can.search_people" label="Name">
                    <ElForm
                        label-position="top"
                        class="max-w-xl"
                        @submit.prevent="runNameSearch"
                    >
                        <div class="grid gap-3 sm:grid-cols-2">
                            <ElFormItem
                                label="Name"
                                :error="nameHttp.errors.name"
                                required
                            >
                                <ElInput
                                    v-model="nameHttp.name"
                                    autocomplete="off"
                                />
                            </ElFormItem>
                            <ElFormItem
                                label="Birth year"
                                :error="nameHttp.errors.birth_year"
                            >
                                <ElInput
                                    v-model="nameHttp.birth_year"
                                    inputmode="numeric"
                                />
                            </ElFormItem>
                            <ElFormItem
                                label="Station"
                                :error="nameHttp.errors.station_id"
                            >
                                <ElSelect
                                    v-model="nameHttp.station_id"
                                    filterable
                                    clearable
                                    class="w-full"
                                >
                                    <ElOption
                                        v-for="station in stations"
                                        :key="station.id"
                                        :label="station.name"
                                        :value="station.id"
                                    />
                                </ElSelect>
                            </ElFormItem>
                            <ElFormItem
                                label="Purpose"
                                :error="nameHttp.errors.purpose"
                                required
                            >
                                <ElSelect
                                    v-model="nameHttp.purpose"
                                    class="w-full"
                                >
                                    <ElOption
                                        v-for="option in purposes"
                                        :key="option.value"
                                        :label="option.label"
                                        :value="option.value"
                                    />
                                </ElSelect>
                            </ElFormItem>
                        </div>
                        <ElButton
                            type="primary"
                            native-type="submit"
                            :loading="nameHttp.processing"
                            >Search</ElButton
                        >
                    </ElForm>
                </ElTabPane>
            </ElTabs>
        </ElCard>

        <ElCard v-if="peopleResults" shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">People</h2>
            </template>
            <ElTable
                :data="peopleResults"
                empty-text="No one you can see matches"
            >
                <ElTableColumn label="Name" min-width="220">
                    <template #default="{ row }">
                        <Link
                            :href="personShow.url(row.id)"
                            class="font-semibold text-brand-700 hover:underline"
                        >
                            {{ row.full_name }}
                        </Link>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Birth year" width="100">
                    <template #default="{ row }">{{
                        row.birth_year ?? '—'
                    }}</template>
                </ElTableColumn>
                <ElTableColumn
                    prop="station"
                    label="Registered at"
                    min-width="160"
                />
                <ElTableColumn label="Why it matched" min-width="280">
                    <template #default="{ row }">
                        {{ row.match_reason }}
                        <ElTag
                            v-if="row.verification"
                            size="small"
                            type="info"
                            class="ml-1"
                            >{{ row.verification }}</ElTag
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElDialog
            v-model="requestOpen"
            title="Request access to a case"
            width="min(520px, 92vw)"
        >
            <ElAlert
                type="info"
                :closable="false"
                class="mb-4"
                title="For a case outside your normal access. An authorised officer decides. Any access is time-limited, recorded and reviewed."
            />
            <ElForm label-position="top" @submit.prevent="submitRequest">
                <ElFormItem
                    label="Case reference"
                    :error="requestForm.errors.reference"
                    required
                >
                    <ElInput v-model="requestForm.reference" />
                </ElFormItem>
                <ElFormItem
                    label="Purpose"
                    :error="requestForm.errors.purpose"
                    required
                >
                    <ElSelect v-model="requestForm.purpose" class="w-full">
                        <ElOption
                            v-for="option in purposes"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Why do you need it?"
                    :error="requestForm.errors.explanation"
                    required
                >
                    <ElInput
                        v-model="requestForm.explanation"
                        type="textarea"
                        :rows="3"
                        maxlength="1000"
                        show-word-limit
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="requestOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="requestForm.processing"
                    @click="submitRequest"
                    >Send request</ElButton
                >
            </template>
        </ElDialog>
    </div>
</template>
