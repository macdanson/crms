<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
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
import { reactive, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import TablePagination from '@/components/TablePagination.vue';
import { formatRelative } from '@/lib/utils';
import { index, show, store } from '@/routes/admin/users';
import type { Paginated } from '@/types/pagination';

type UserRow = {
    id: number;
    name: string;
    email: string;
    staff_number: string | null;
    status: string;
    status_label: string;
    station: string | null;
    district: string | null;
    roles: string[];
    last_login_at: string | null;
};

const props = defineProps<{
    users: Paginated<UserRow>;
    filters: { search?: string; status?: string; station_id?: number };
    statuses: { value: string; label: string }[];
    stations: { id: number; name: string }[];
    canInvite: boolean;
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    station_id: props.filters.station_id ?? '',
});

function applyFilters(): void {
    router.get(index.url(), filters, { preserveState: true, replace: true });
}

const statusTypes: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
    active: 'success',
    pending: 'warning',
    suspended: 'danger',
    deactivated: 'info',
};

const inviteOpen = ref(false);
const inviteForm = useForm({
    name: '',
    email: '',
    staff_number: '',
    job_title: '',
    station_id: null as number | null,
});

function invite(): void {
    inviteForm.post(store.url(), {
        onSuccess: () => {
            inviteOpen.value = false;
            inviteForm.reset();
        },
    });
}
</script>

<template>
    <Head title="Users" />

    <div class="space-y-6">
        <PageHeader
            title="Users"
            description="Every user has an individual account. Accounts are never deleted: suspend or deactivate them instead, so the audit trail stays attributable."
        >
            <ElButton
                v-if="canInvite"
                type="primary"
                @click="inviteOpen = true"
            >
                Invite user
            </ElButton>
        </PageHeader>

        <ElCard shadow="never">
            <form
                class="mb-4 grid gap-3 sm:grid-cols-[1fr_180px_220px_auto]"
                @submit.prevent="applyFilters"
            >
                <ElInput
                    v-model="filters.search"
                    placeholder="Name, email or staff number"
                    aria-label="Search users"
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
                    v-model="filters.station_id"
                    placeholder="Any station"
                    aria-label="Station"
                    filterable
                    clearable
                >
                    <ElOption
                        v-for="station in stations"
                        :key="station.id"
                        :label="station.name"
                        :value="station.id"
                    />
                </ElSelect>
                <ElButton native-type="submit">Filter</ElButton>
            </form>

            <ElTable :data="users.data" empty-text="No users match">
                <ElTableColumn label="Name" min-width="220">
                    <template #default="{ row }">
                        <Link
                            :href="show.url(row.id)"
                            class="font-semibold text-brand-700 hover:underline"
                        >
                            {{ row.name }}
                        </Link>
                        <div class="text-xs text-slate-500">
                            {{ row.email }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn
                    prop="staff_number"
                    label="Staff no."
                    width="130"
                />
                <ElTableColumn label="Station" min-width="170">
                    <template #default="{ row }">
                        {{ row.station ?? row.district ?? '—' }}
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Roles" min-width="220">
                    <template #default="{ row }">
                        <ElTag
                            v-for="role in row.roles"
                            :key="role"
                            size="small"
                            effect="plain"
                            class="mr-1 mb-1"
                        >
                            {{ role }}
                        </ElTag>
                        <span
                            v-if="row.roles.length === 0"
                            class="text-slate-400"
                            >None</span
                        >
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Status" width="150">
                    <template #default="{ row }">
                        <ElTag :type="statusTypes[row.status]" size="small">
                            {{ row.status_label }}
                        </ElTag>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Last sign-in" width="140">
                    <template #default="{ row }">
                        {{ formatRelative(row.last_login_at) }}
                    </template>
                </ElTableColumn>
            </ElTable>
            <TablePagination :paginator="users" />
        </ElCard>

        <ElDialog
            v-model="inviteOpen"
            title="Invite user"
            width="min(520px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="invite">
                <ElFormItem
                    label="Full name"
                    :error="inviteForm.errors.name"
                    required
                >
                    <ElInput v-model="inviteForm.name" />
                </ElFormItem>
                <ElFormItem
                    label="Official email address"
                    :error="inviteForm.errors.email"
                    required
                >
                    <ElInput v-model="inviteForm.email" type="email" />
                </ElFormItem>
                <div class="grid gap-4 sm:grid-cols-2">
                    <ElFormItem
                        label="Staff number"
                        :error="inviteForm.errors.staff_number"
                    >
                        <ElInput v-model="inviteForm.staff_number" />
                    </ElFormItem>
                    <ElFormItem
                        label="Job title"
                        :error="inviteForm.errors.job_title"
                    >
                        <ElInput v-model="inviteForm.job_title" />
                    </ElFormItem>
                </div>
                <ElFormItem
                    label="Station"
                    :error="inviteForm.errors.station_id"
                >
                    <ElSelect
                        v-model="inviteForm.station_id"
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
            </ElForm>
            <template #footer>
                <ElButton @click="inviteOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="inviteForm.processing"
                    @click="invite"
                >
                    Send invitation
                </ElButton>
            </template>
        </ElDialog>
    </div>
</template>
