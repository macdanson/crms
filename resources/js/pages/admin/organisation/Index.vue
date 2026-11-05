<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ElButton,
    ElCard,
    ElDialog,
    ElForm,
    ElFormItem,
    ElInput,
    ElOption,
    ElSelect,
    ElSwitch,
    ElTable,
    ElTableColumn,
    ElTabPane,
    ElTabs,
    ElTag,
} from 'element-plus';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    store as storeDepartment,
    update as updateDepartment,
} from '@/routes/admin/departments';
import {
    store as storeDistrict,
    update as updateDistrict,
} from '@/routes/admin/districts';
import {
    store as storeStation,
    update as updateStation,
} from '@/routes/admin/stations';

type District = {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    stations_count: number;
};
type Station = {
    id: number;
    district_id: number;
    name: string;
    code: string;
    is_active: boolean;
    departments_count: number;
    district: { name: string };
};
type Department = {
    id: number;
    station_id: number;
    name: string;
    is_active: boolean;
    station: { name: string };
};
type Kind = 'district' | 'station' | 'department';

const props = defineProps<{
    districts: District[];
    stations: Station[];
    departments: Department[];
}>();

const dialogOpen = ref(false);
const kind = ref<Kind>('district');
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    code: '',
    is_active: true,
    district_id: null as number | null,
    station_id: null as number | null,
});

const dialogTitle = computed(
    () => `${editingId.value === null ? 'Add' : 'Edit'} ${kind.value}`,
);

function add(newKind: Kind): void {
    kind.value = newKind;
    editingId.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function edit(
    newKind: Kind,
    record: { id: number; name: string; is_active: boolean },
): void {
    kind.value = newKind;
    editingId.value = record.id;
    form.clearErrors();
    form.name = record.name;
    form.is_active = record.is_active;
    dialogOpen.value = true;
}

const routes = {
    district: { store: storeDistrict, update: updateDistrict },
    station: { store: storeStation, update: updateStation },
    department: { store: storeDepartment, update: updateDepartment },
};

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editingId.value === null) {
        form.post(routes[kind.value].store.url(), options);
    } else {
        form.put(routes[kind.value].update.url(editingId.value), options);
    }
}
</script>

<template>
    <Head title="Organisation" />

    <div class="space-y-6">
        <PageHeader
            title="Organisation"
            description="Districts, police stations and their departments. A station's code and district cannot change once it exists, because case references and access scopes depend on them."
        />

        <ElCard shadow="never">
            <ElTabs>
                <ElTabPane :label="`Districts (${districts.length})`">
                    <div class="mb-3 flex justify-end">
                        <ElButton type="primary" @click="add('district')"
                            >Add district</ElButton
                        >
                    </div>
                    <ElTable
                        :data="props.districts"
                        empty-text="No districts yet"
                    >
                        <ElTableColumn
                            prop="name"
                            label="Name"
                            min-width="180"
                        />
                        <ElTableColumn prop="code" label="Code" width="120" />
                        <ElTableColumn
                            prop="stations_count"
                            label="Stations"
                            width="100"
                        />
                        <ElTableColumn label="Status" width="110">
                            <template #default="{ row }">
                                <ElTag
                                    :type="row.is_active ? 'success' : 'info'"
                                    size="small"
                                >
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </ElTag>
                            </template>
                        </ElTableColumn>
                        <ElTableColumn width="90" align="right">
                            <template #default="{ row }">
                                <ElButton
                                    link
                                    type="primary"
                                    @click="edit('district', row as District)"
                                    >Edit</ElButton
                                >
                            </template>
                        </ElTableColumn>
                    </ElTable>
                </ElTabPane>

                <ElTabPane :label="`Stations (${stations.length})`">
                    <div class="mb-3 flex justify-end">
                        <ElButton type="primary" @click="add('station')"
                            >Add station</ElButton
                        >
                    </div>
                    <ElTable
                        :data="props.stations"
                        empty-text="No stations yet"
                    >
                        <ElTableColumn
                            prop="name"
                            label="Name"
                            min-width="180"
                        />
                        <ElTableColumn prop="code" label="Code" width="120" />
                        <ElTableColumn
                            prop="district.name"
                            label="District"
                            min-width="160"
                        />
                        <ElTableColumn
                            prop="departments_count"
                            label="Departments"
                            width="120"
                        />
                        <ElTableColumn label="Status" width="110">
                            <template #default="{ row }">
                                <ElTag
                                    :type="row.is_active ? 'success' : 'info'"
                                    size="small"
                                >
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </ElTag>
                            </template>
                        </ElTableColumn>
                        <ElTableColumn width="90" align="right">
                            <template #default="{ row }">
                                <ElButton
                                    link
                                    type="primary"
                                    @click="edit('station', row as Station)"
                                    >Edit</ElButton
                                >
                            </template>
                        </ElTableColumn>
                    </ElTable>
                </ElTabPane>

                <ElTabPane :label="`Departments (${departments.length})`">
                    <div class="mb-3 flex justify-end">
                        <ElButton type="primary" @click="add('department')"
                            >Add department</ElButton
                        >
                    </div>
                    <ElTable
                        :data="props.departments"
                        empty-text="No departments yet"
                    >
                        <ElTableColumn
                            prop="name"
                            label="Name"
                            min-width="180"
                        />
                        <ElTableColumn
                            prop="station.name"
                            label="Station"
                            min-width="180"
                        />
                        <ElTableColumn label="Status" width="110">
                            <template #default="{ row }">
                                <ElTag
                                    :type="row.is_active ? 'success' : 'info'"
                                    size="small"
                                >
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </ElTag>
                            </template>
                        </ElTableColumn>
                        <ElTableColumn width="90" align="right">
                            <template #default="{ row }">
                                <ElButton
                                    link
                                    type="primary"
                                    @click="
                                        edit('department', row as Department)
                                    "
                                    >Edit</ElButton
                                >
                            </template>
                        </ElTableColumn>
                    </ElTable>
                </ElTabPane>
            </ElTabs>
        </ElCard>

        <ElDialog
            v-model="dialogOpen"
            :title="dialogTitle"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submit">
                <ElFormItem
                    v-if="kind === 'station' && editingId === null"
                    label="District"
                    :error="form.errors.district_id"
                    required
                >
                    <ElSelect
                        v-model="form.district_id"
                        filterable
                        class="w-full"
                    >
                        <ElOption
                            v-for="district in districts"
                            :key="district.id"
                            :label="district.name"
                            :value="district.id"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    v-if="kind === 'department' && editingId === null"
                    label="Station"
                    :error="form.errors.station_id"
                    required
                >
                    <ElSelect
                        v-model="form.station_id"
                        filterable
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
                <ElFormItem label="Name" :error="form.errors.name" required>
                    <ElInput v-model="form.name" maxlength="120" />
                </ElFormItem>
                <ElFormItem
                    v-if="kind !== 'department' && editingId === null"
                    label="Code"
                    :error="form.errors.code"
                    required
                >
                    <ElInput v-model="form.code" maxlength="12" />
                </ElFormItem>
                <ElFormItem v-if="editingId !== null" label="Active">
                    <ElSwitch v-model="form.is_active" />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="dialogOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="form.processing"
                    @click="submit"
                    >Save</ElButton
                >
            </template>
        </ElDialog>
    </div>
</template>
