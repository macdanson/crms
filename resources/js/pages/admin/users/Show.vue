<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCard,
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
} from 'element-plus';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { formatDateTime, formatRelative } from '@/lib/utils';
import {
    deactivate as deactivateRoute,
    reactivate as reactivateRoute,
    resendInvitation,
    resetTwoFactor,
    suspend as suspendRoute,
    update as updateRoute,
} from '@/routes/admin/users';
import {
    destroy as revokeRoute,
    store as grantRoute,
} from '@/routes/admin/users/roles';

type Assignment = {
    id: number;
    role: string;
    reason: string;
    granted_by: string | null;
    granted_at: string;
    expires_at: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revocation_reason: string | null;
    is_active: boolean;
};

const props = defineProps<{
    subject: {
        id: number;
        name: string;
        email: string;
        staff_number: string | null;
        job_title: string | null;
        status: string;
        status_label: string;
        suspension_reason: string | null;
        district_id: number | null;
        station_id: number | null;
        department_id: number | null;
        two_factor_enabled: boolean;
        last_login_at: string | null;
        is_self: boolean;
    };
    assignments: Assignment[];
    activity: {
        id: string;
        event: string;
        outcome: string;
        occurred_at: string;
    }[];
    options: {
        roles: { value: string; label: string; requires_expiry: boolean }[];
        districts: { id: number; name: string }[];
        stations: { id: number; name: string; district_id: number }[];
        departments: { id: number; name: string; station_id: number }[];
    };
    can: {
        update: boolean;
        change_status: boolean;
        reset_two_factor: boolean;
        manage_roles: boolean;
    };
}>();

const detailsForm = useForm({
    name: props.subject.name,
    staff_number: props.subject.staff_number ?? '',
    job_title: props.subject.job_title ?? '',
    district_id: props.subject.district_id,
    station_id: props.subject.station_id,
    department_id: props.subject.department_id,
});

const departmentOptions = computed(() =>
    props.options.departments.filter(
        (department) => department.station_id === detailsForm.station_id,
    ),
);

function stationChanged(): void {
    detailsForm.department_id = null;
    detailsForm.district_id =
        props.options.stations.find(
            (station) => station.id === detailsForm.station_id,
        )?.district_id ?? detailsForm.district_id;
}

function saveDetails(): void {
    detailsForm.put(updateRoute.url(props.subject.id), {
        preserveScroll: true,
    });
}

/* Roles ---------------------------------------------------------------- */

const grantOpen = ref(false);
const grantForm = useForm({
    role: '',
    reason: '',
    expires_at: null as string | null,
});

const expiryRequired = computed(
    () =>
        props.options.roles.find((role) => role.value === grantForm.role)
            ?.requires_expiry ?? false,
);

function grant(): void {
    grantForm.post(grantRoute.url(props.subject.id), {
        preserveScroll: true,
        onSuccess: () => {
            grantOpen.value = false;
            grantForm.reset();
        },
    });
}

const revoking = ref<Assignment | null>(null);
const revokeOpen = ref(false);
const revokeForm = useForm({ reason: '' });

function startRevoke(assignment: Assignment): void {
    revoking.value = assignment;
    revokeForm.clearErrors();
    revokeOpen.value = true;
}

function revoke(reason: string): void {
    if (!revoking.value) {
        return;
    }

    revokeForm.reason = reason;
    revokeForm.delete(
        revokeRoute.url({
            user: props.subject.id,
            assignment: revoking.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                revokeOpen.value = false;
            },
        },
    );
}

/* Account status ------------------------------------------------------- */

const statusAction = ref<'suspend' | 'deactivate'>('suspend');
const statusOpen = ref(false);
const statusForm = useForm({ reason: '' });

function startStatusChange(action: 'suspend' | 'deactivate'): void {
    statusAction.value = action;
    statusForm.clearErrors();
    statusOpen.value = true;
}

function changeStatus(reason: string): void {
    statusForm.reason = reason;
    const route =
        statusAction.value === 'suspend' ? suspendRoute : deactivateRoute;

    statusForm.post(route.url(props.subject.id), {
        preserveScroll: true,
        onSuccess: () => {
            statusOpen.value = false;
        },
    });
}

function confirmThen(message: string, title: string, url: string): void {
    ElMessageBox.confirm(message, title, {
        confirmButtonText: 'Continue',
        cancelButtonText: 'Cancel',
        type: 'warning',
    })
        .then(() => router.post(url, {}, { preserveScroll: true }))
        .catch(() => undefined);
}
</script>

<template>
    <Head :title="subject.name" />

    <div class="space-y-6">
        <PageHeader :title="subject.name" :description="subject.email">
            <ElTag :type="subject.status === 'active' ? 'success' : 'warning'">
                {{ subject.status_label }}
            </ElTag>
        </PageHeader>

        <ElAlert
            v-if="subject.is_self"
            type="info"
            :closable="false"
            title="This is your own account. Another administrator must change your access."
        />
        <ElAlert
            v-if="subject.suspension_reason"
            type="warning"
            :closable="false"
            :title="`Reason on record: ${subject.suspension_reason}`"
        />

        <div class="grid gap-6 lg:grid-cols-3">
            <ElCard shadow="never" class="lg:col-span-2">
                <template #header>
                    <h2 class="font-bold text-brand-900">
                        Details and placement
                    </h2>
                </template>
                <ElForm
                    label-position="top"
                    :disabled="!can.update"
                    @submit.prevent="saveDetails"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <ElFormItem
                            label="Full name"
                            :error="detailsForm.errors.name"
                            required
                        >
                            <ElInput v-model="detailsForm.name" />
                        </ElFormItem>
                        <ElFormItem
                            label="Staff number"
                            :error="detailsForm.errors.staff_number"
                        >
                            <ElInput v-model="detailsForm.staff_number" />
                        </ElFormItem>
                        <ElFormItem
                            label="Job title"
                            :error="detailsForm.errors.job_title"
                        >
                            <ElInput v-model="detailsForm.job_title" />
                        </ElFormItem>
                        <ElFormItem
                            label="Station"
                            :error="detailsForm.errors.station_id"
                        >
                            <ElSelect
                                v-model="detailsForm.station_id"
                                filterable
                                clearable
                                class="w-full"
                                @change="stationChanged"
                            >
                                <ElOption
                                    v-for="station in options.stations"
                                    :key="station.id"
                                    :label="station.name"
                                    :value="station.id"
                                />
                            </ElSelect>
                        </ElFormItem>
                        <ElFormItem
                            label="Department"
                            :error="detailsForm.errors.department_id"
                        >
                            <ElSelect
                                v-model="detailsForm.department_id"
                                clearable
                                class="w-full"
                                :disabled="!detailsForm.station_id"
                            >
                                <ElOption
                                    v-for="department in departmentOptions"
                                    :key="department.id"
                                    :label="department.name"
                                    :value="department.id"
                                />
                            </ElSelect>
                        </ElFormItem>
                        <ElFormItem
                            v-if="!detailsForm.station_id"
                            label="District (no station)"
                            :error="detailsForm.errors.district_id"
                        >
                            <ElSelect
                                v-model="detailsForm.district_id"
                                clearable
                                class="w-full"
                            >
                                <ElOption
                                    v-for="district in options.districts"
                                    :key="district.id"
                                    :label="district.name"
                                    :value="district.id"
                                />
                            </ElSelect>
                        </ElFormItem>
                    </div>
                    <div v-if="can.update" class="flex items-center gap-3">
                        <ElButton
                            type="primary"
                            native-type="submit"
                            :loading="detailsForm.processing"
                        >
                            Save
                        </ElButton>
                        <span class="text-xs text-slate-500">
                            Changing the placement signs this user out.
                        </span>
                    </div>
                </ElForm>
            </ElCard>

            <ElCard shadow="never">
                <template #header>
                    <h2 class="font-bold text-brand-900">Account</h2>
                </template>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="font-semibold text-slate-900">
                            Authenticator
                        </dt>
                        <dd class="text-slate-600">
                            {{
                                subject.two_factor_enabled
                                    ? 'Enrolled'
                                    : 'Not enrolled'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-900">
                            Last sign-in
                        </dt>
                        <dd class="text-slate-600">
                            {{ formatRelative(subject.last_login_at) }}
                        </dd>
                    </div>
                </dl>
                <div
                    class="mt-5 flex flex-col items-stretch gap-2 [&>button]:ml-0!"
                >
                    <template v-if="can.change_status">
                        <ElButton
                            v-if="subject.status === 'pending'"
                            @click="
                                confirmThen(
                                    'Send a new activation link? The earlier link stops working.',
                                    'Resend invitation',
                                    resendInvitation.url(subject.id),
                                )
                            "
                        >
                            Resend invitation
                        </ElButton>
                        <ElButton
                            v-if="
                                subject.status === 'suspended' ||
                                subject.status === 'deactivated'
                            "
                            @click="
                                confirmThen(
                                    'Restore this account? A deactivated account gets no roles back.',
                                    'Restore account',
                                    reactivateRoute.url(subject.id),
                                )
                            "
                        >
                            Restore account
                        </ElButton>
                        <ElButton
                            v-if="
                                subject.status !== 'suspended' &&
                                subject.status !== 'deactivated'
                            "
                            type="warning"
                            plain
                            @click="startStatusChange('suspend')"
                        >
                            Suspend
                        </ElButton>
                        <ElButton
                            v-if="subject.status !== 'deactivated'"
                            type="danger"
                            plain
                            @click="startStatusChange('deactivate')"
                        >
                            Deactivate (leaver)
                        </ElButton>
                    </template>
                    <ElButton
                        v-if="
                            can.reset_two_factor && subject.two_factor_enabled
                        "
                        @click="
                            confirmThen(
                                'Clear this user\'s authenticator? They must enrol again at next sign-in. Confirm their identity first.',
                                'Reset authenticator',
                                resetTwoFactor.url(subject.id),
                            )
                        "
                    >
                        Reset authenticator
                    </ElButton>
                </div>
            </ElCard>
        </div>

        <ElCard shadow="never">
            <template #header>
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-brand-900">Roles</h2>
                    <ElButton
                        v-if="can.manage_roles"
                        type="primary"
                        size="small"
                        @click="grantOpen = true"
                    >
                        Grant role
                    </ElButton>
                </div>
            </template>
            <ElTable
                :data="assignments"
                empty-text="No roles have been granted"
            >
                <ElTableColumn prop="role" label="Role" min-width="200" />
                <ElTableColumn label="Status" width="110">
                    <template #default="{ row }">
                        <ElTag
                            :type="row.is_active ? 'success' : 'info'"
                            size="small"
                        >
                            {{
                                row.is_active
                                    ? 'Active'
                                    : row.revoked_at
                                      ? 'Revoked'
                                      : 'Expired'
                            }}
                        </ElTag>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Granted" min-width="190">
                    <template #default="{ row }">
                        {{ formatDateTime(row.granted_at) }}
                        <div class="text-xs text-slate-500">
                            by {{ row.granted_by ?? 'operator' }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Ends" width="170">
                    <template #default="{ row }">{{
                        formatDateTime(row.expires_at)
                    }}</template>
                </ElTableColumn>
                <ElTableColumn label="Reason" min-width="220">
                    <template #default="{ row }">
                        {{ row.reason }}
                        <div
                            v-if="row.revocation_reason"
                            class="text-xs text-slate-500"
                        >
                            Revoked: {{ row.revocation_reason }}
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn v-if="can.manage_roles" width="90" align="right">
                    <template #default="{ row }">
                        <ElButton
                            v-if="row.is_active"
                            link
                            type="danger"
                            @click="startRevoke(row as Assignment)"
                        >
                            Revoke
                        </ElButton>
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElCard v-if="activity.length > 0" shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">Recent audit events</h2>
            </template>
            <ElTable :data="activity">
                <ElTableColumn prop="event" label="Event" min-width="240" />
                <ElTableColumn prop="outcome" label="Outcome" width="120" />
                <ElTableColumn label="Time" width="200">
                    <template #default="{ row }">{{
                        formatDateTime(row.occurred_at)
                    }}</template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElDialog
            v-model="grantOpen"
            title="Grant role"
            width="min(480px, 92vw)"
        >
            <ElAlert
                type="info"
                :closable="false"
                class="mb-4"
                title="Granting a role signs this user out, so the change takes effect immediately."
            />
            <ElForm label-position="top" @submit.prevent="grant">
                <ElFormItem
                    label="Role"
                    :error="grantForm.errors.role"
                    required
                >
                    <ElSelect v-model="grantForm.role" class="w-full">
                        <ElOption
                            v-for="role in options.roles"
                            :key="role.value"
                            :label="role.label"
                            :value="role.value"
                        />
                    </ElSelect>
                </ElFormItem>
                <ElFormItem
                    label="Reason (kept on record)"
                    :error="grantForm.errors.reason"
                    required
                >
                    <ElInput
                        v-model="grantForm.reason"
                        type="textarea"
                        :rows="2"
                        maxlength="500"
                    />
                </ElFormItem>
                <ElFormItem
                    label="Ends on"
                    :error="grantForm.errors.expires_at"
                    :required="expiryRequired"
                >
                    <ElDatePicker
                        v-model="grantForm.expires_at"
                        type="datetime"
                        value-format="YYYY-MM-DDTHH:mm:ssZ"
                        class="w-full!"
                    />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="grantOpen = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="grantForm.processing"
                    @click="grant"
                >
                    Grant
                </ElButton>
            </template>
        </ElDialog>

        <ReasonDialog
            v-model="revokeOpen"
            :title="`Revoke ${revoking?.role ?? 'role'}`"
            confirm-text="Revoke"
            danger
            warning="This signs the user out immediately."
            :error="revokeForm.errors.reason"
            :processing="revokeForm.processing"
            @confirm="revoke"
        />

        <ReasonDialog
            v-model="statusOpen"
            :title="
                statusAction === 'suspend'
                    ? 'Suspend account'
                    : 'Deactivate account'
            "
            :confirm-text="
                statusAction === 'suspend' ? 'Suspend' : 'Deactivate'
            "
            danger
            :warning="
                statusAction === 'suspend'
                    ? 'This signs the user out everywhere, immediately.'
                    : 'This signs the user out everywhere and revokes all their roles.'
            "
            :error="statusForm.errors.reason"
            :processing="statusForm.processing"
            @confirm="changeStatus"
        />
    </div>
</template>
