<script setup lang="ts">
import {
    CircleCheckFilled,
    Iphone,
    Key,
    Lock,
    Monitor,
    Refresh,
    SwitchButton,
    WarningFilled,
} from '@element-plus/icons-vue';
import { Head, router, useForm, useHttp } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCard,
    ElDescriptions,
    ElDescriptionsItem,
    ElDialog,
    ElEmpty,
    ElForm,
    ElFormItem,
    ElIcon,
    ElInput,
    ElMessage,
    ElMessageBox,
    ElTable,
    ElTableColumn,
    ElTag,
} from 'element-plus';
import { ref } from 'vue';
import PasswordStrength from '@/components/PasswordStrength.vue';
import { formatDateTime, formatRelative } from '@/lib/utils';
import { show as securityShow } from '@/routes/security';
import { reset as resetTwoFactor } from '@/routes/security/two-factor';
import {
    destroy as destroySession,
    destroyOthers,
} from '@/routes/security/sessions';
import {
    recoveryCodes as recoveryCodesRoute,
    regenerateRecoveryCodes,
} from '@/routes/two-factor';
import { update as updatePassword } from '@/routes/user-password';

type BrowserSession = {
    id: string;
    ip_address: string | null;
    browser: string;
    platform: string;
    is_mobile: boolean;
    is_current: boolean;
    last_active_at: string;
};

type ActivityEvent = {
    id: string;
    event: string;
    outcome: 'success' | 'failure' | 'blocked';
    ip_address: string | null;
    device: string;
    occurred_at: string;
};

defineProps<{
    account: {
        name: string;
        email: string;
        staff_number: string | null;
        job_title: string | null;
        status: string;
        password_changed_at: string | null;
        previous_login: { at: string | null; ip: string | null } | null;
    };
    twoFactor: {
        enabled: boolean;
        confirmed_at: string | null;
        required: boolean;
        recovery_codes_remaining: number;
    };
    sessionsSupported: boolean;
    sessions: BrowserSession[];
    activity: ActivityEvent[];
}>();

const eventLabels: Record<string, string> = {
    'auth.login': 'Signed in',
    'auth.login.failed': 'Failed sign-in attempt',
    'auth.lockout': 'Sign-in temporarily locked',
    'auth.login.blocked': 'Sign-in blocked (account inactive)',
    'auth.logout': 'Signed out',
    'auth.session.expired': 'Session expired',
    'auth.session.revoked': 'Signed out a session',
    'auth.sessions.revoked_others': 'Signed out other sessions',
    'auth.password.changed': 'Password changed',
    'auth.password_reset.completed': 'Password reset',
    'mfa.enrolment.confirmed': 'Authenticator linked',
    'mfa.reset': 'Authenticator reset',
    'mfa.reset.failed': 'Authenticator replacement refused (wrong code)',
    'auth.session.invalidated': 'Signed out after a security change',
    'mfa.recovery_codes.generated': 'Recovery codes regenerated',
    'mfa.recovery_code.used': 'Recovery code used',
    'mfa.challenge.failed': 'Incorrect authentication code',
};

/* Password ------------------------------------------------------------- */

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword(): void {
    passwordForm.put(updatePassword.url(), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('password', 'password_confirmation'),
    });
}

/* Recovery codes ------------------------------------------------------- */

const recoveryHttp = useHttp<Record<string, never>, string[]>({});
const recoveryDialogOpen = ref(false);
const visibleCodes = ref<string[]>([]);

/** Password confirmation has expired; the server answers JSON with 423. */
const PASSWORD_CONFIRMATION_REQUIRED = 423;

async function showRecoveryCodes(): Promise<void> {
    try {
        visibleCodes.value = await recoveryHttp.get(recoveryCodesRoute.url());
        recoveryDialogOpen.value = true;
    } catch (error) {
        const status = (error as { response?: { status?: number } })?.response
            ?.status;

        if (status === PASSWORD_CONFIRMATION_REQUIRED) {
            // Reloading this page asks for the password, then returns here.
            router.visit(securityShow.url());

            return;
        }

        ElMessage.error(
            'Your recovery codes could not be loaded. Please try again.',
        );
    }
}

async function confirmRegenerateCodes(): Promise<void> {
    try {
        await ElMessageBox.confirm(
            'Your current recovery codes will stop working immediately.',
            'Generate new recovery codes?',
            {
                confirmButtonText: 'Generate new codes',
                cancelButtonText: 'Cancel',
                type: 'warning',
            },
        );
    } catch {
        return;
    }

    router.post(
        regenerateRecoveryCodes.url(),
        {},
        {
            preserveScroll: true,
            onSuccess: () => void showRecoveryCodes(),
        },
    );
}

const resetAuthenticatorOpen = ref(false);
const resetWithRecoveryCode = ref(false);
const resetForm = useForm({ code: '', recovery_code: '' });

function openResetAuthenticator(): void {
    resetWithRecoveryCode.value = false;
    resetForm.reset();
    resetForm.clearErrors();
    resetAuthenticatorOpen.value = true;
}

function toggleResetMethod(): void {
    resetWithRecoveryCode.value = !resetWithRecoveryCode.value;
    resetForm.reset();
    resetForm.clearErrors();
}

/** Replacing the authenticator needs the current code (or a recovery code). */
function submitResetAuthenticator(): void {
    resetForm
        .transform((data) =>
            resetWithRecoveryCode.value
                ? { recovery_code: data.recovery_code.trim() }
                : { code: data.code.replace(/\s/g, '') },
        )
        .post(resetTwoFactor.url(), {
            preserveScroll: true,
            onError: () => resetForm.reset(),
        });
}

/* Sessions ------------------------------------------------------------- */

const signOutOthersOpen = ref(false);
const sessionsForm = useForm({ password: '' });

function revokeSession(sessionId: string): void {
    router.delete(destroySession.url(sessionId), { preserveScroll: true });
}

function submitSignOutOthers(): void {
    sessionsForm.delete(destroyOthers.url(), {
        preserveScroll: true,
        onSuccess: () => {
            signOutOthersOpen.value = false;
            sessionsForm.reset();
        },
        onError: () => sessionsForm.reset(),
    });
}
</script>

<template>
    <Head title="Account security" />

    <div class="space-y-6">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-brand-900">
                Account security
            </h1>
            <p class="mt-1 text-sm text-slate-600">
                Manage how you sign in and review recent activity on your
                account.
            </p>
        </header>

        <!-- Profile -->
        <ElCard shadow="never">
            <template #header>
                <h2 class="font-bold text-brand-900">Your account</h2>
            </template>
            <ElDescriptions :column="2" border class="crms-descriptions">
                <ElDescriptionsItem label="Name">{{
                    account.name
                }}</ElDescriptionsItem>
                <ElDescriptionsItem label="Email">{{
                    account.email
                }}</ElDescriptionsItem>
                <ElDescriptionsItem label="Job title">{{
                    account.job_title ?? '—'
                }}</ElDescriptionsItem>
                <ElDescriptionsItem label="Staff number">
                    <span class="crms-code">{{
                        account.staff_number ?? '—'
                    }}</span>
                </ElDescriptionsItem>
                <ElDescriptionsItem label="Status">
                    <ElTag type="success" effect="light">{{
                        account.status
                    }}</ElTag>
                </ElDescriptionsItem>
                <ElDescriptionsItem label="Previous sign-in">
                    <template v-if="account.previous_login?.at">
                        {{ formatDateTime(account.previous_login.at) }}
                        <span v-if="account.previous_login.ip">
                            from
                            <span class="crms-code">{{
                                account.previous_login.ip
                            }}</span>
                        </span>
                    </template>
                    <template v-else>None before this one</template>
                </ElDescriptionsItem>
            </ElDescriptions>
            <p class="mt-3 text-xs text-slate-500">
                Profile details are managed by your administrator. Report any
                errors so your records stay accurate.
            </p>
        </ElCard>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Password -->
            <ElCard shadow="never">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-brand-900">Password</h2>
                        <span class="text-xs text-slate-500"
                            >Changed
                            {{
                                formatRelative(account.password_changed_at)
                            }}</span
                        >
                    </div>
                </template>
                <ElForm label-position="top" @submit.prevent="submitPassword">
                    <ElFormItem
                        label="Current password"
                        :error="passwordForm.errors.current_password"
                    >
                        <ElInput
                            v-model="passwordForm.current_password"
                            type="password"
                            autocomplete="current-password"
                            :prefix-icon="Lock"
                            show-password
                        />
                    </ElFormItem>
                    <ElFormItem
                        label="New password"
                        :error="passwordForm.errors.password"
                    >
                        <ElInput
                            v-model="passwordForm.password"
                            type="password"
                            autocomplete="new-password"
                            :prefix-icon="Lock"
                            show-password
                        />
                        <PasswordStrength :password="passwordForm.password" />
                    </ElFormItem>
                    <ElFormItem
                        label="Confirm new password"
                        :error="passwordForm.errors.password_confirmation"
                    >
                        <ElInput
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            :prefix-icon="Lock"
                            show-password
                        />
                    </ElFormItem>
                    <p class="mb-4 text-xs text-slate-500">
                        Changing your password signs out your other sessions.
                    </p>
                    <ElButton
                        type="primary"
                        native-type="submit"
                        :loading="passwordForm.processing"
                    >
                        Update password
                    </ElButton>
                </ElForm>
            </ElCard>

            <!-- Multi-factor authentication -->
            <ElCard shadow="never">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-brand-900">
                            Multi-factor authentication
                        </h2>
                        <ElTag
                            v-if="twoFactor.required"
                            type="info"
                            effect="plain"
                            round
                            >Required</ElTag
                        >
                    </div>
                </template>

                <div class="flex items-start gap-4">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"
                    >
                        <ElIcon :size="22"><Iphone /></ElIcon>
                    </span>
                    <div>
                        <p class="flex items-center gap-1.5 font-semibold">
                            <ElIcon class="text-emerald-600"
                                ><CircleCheckFilled
                            /></ElIcon>
                            Authenticator app linked
                        </p>
                        <p class="text-sm text-slate-500">
                            Since {{ formatDateTime(twoFactor.confirmed_at) }}
                        </p>
                    </div>
                </div>

                <div
                    class="mt-5 flex items-center justify-between rounded-lg border border-slate-200 p-4"
                >
                    <div class="flex items-center gap-3">
                        <ElIcon
                            :size="20"
                            :class="
                                twoFactor.recovery_codes_remaining <= 3
                                    ? 'text-accent-700'
                                    : 'text-slate-500'
                            "
                        >
                            <WarningFilled
                                v-if="twoFactor.recovery_codes_remaining <= 3"
                            />
                            <Key v-else />
                        </ElIcon>
                        <div>
                            <p class="text-sm font-semibold">Recovery codes</p>
                            <p class="text-xs text-slate-500">
                                {{ twoFactor.recovery_codes_remaining }} of 8
                                unused
                            </p>
                        </div>
                    </div>
                    <ElButton
                        size="small"
                        :loading="recoveryHttp.processing"
                        @click="showRecoveryCodes"
                        >View</ElButton
                    >
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <ElButton :icon="Refresh" @click="confirmRegenerateCodes">
                        New recovery codes
                    </ElButton>
                    <ElButton
                        type="danger"
                        plain
                        :icon="Iphone"
                        @click="openResetAuthenticator"
                    >
                        Replace authenticator
                    </ElButton>
                </div>
            </ElCard>
        </div>

        <!-- Sessions -->
        <ElCard shadow="never">
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-brand-900">
                            Active sessions
                        </h2>
                        <p class="text-xs text-slate-500">
                            Devices currently signed in to your account.
                        </p>
                    </div>
                    <ElButton
                        v-if="sessions.length > 1"
                        :icon="SwitchButton"
                        @click="signOutOthersOpen = true"
                    >
                        Sign out other sessions
                    </ElButton>
                </div>
            </template>

            <ElAlert
                v-if="!sessionsSupported"
                type="info"
                :closable="false"
                show-icon
                title="Session listing requires the database session driver."
            />
            <ElTable v-else :data="sessions" class="w-full">
                <ElTableColumn label="Device" min-width="220">
                    <template #default="{ row }">
                        <div class="flex items-center gap-3">
                            <ElIcon :size="20" class="text-slate-500">
                                <Iphone v-if="row.is_mobile" />
                                <Monitor v-else />
                            </ElIcon>
                            <span>
                                <span class="block font-semibold text-slate-900"
                                    >{{ row.browser }} on
                                    {{ row.platform }}</span
                                >
                                <ElTag
                                    v-if="row.is_current"
                                    type="success"
                                    size="small"
                                    >This device</ElTag
                                >
                            </span>
                        </div>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Network address" min-width="140">
                    <template #default="{ row }">
                        <span class="crms-code">{{
                            row.ip_address ?? '—'
                        }}</span>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="Last active" min-width="140">
                    <template #default="{ row }">
                        {{
                            row.is_current
                                ? 'Now'
                                : formatRelative(row.last_active_at)
                        }}
                    </template>
                </ElTableColumn>
                <ElTableColumn label="" width="120" align="right">
                    <template #default="{ row }">
                        <ElButton
                            v-if="!row.is_current"
                            size="small"
                            text
                            type="danger"
                            @click="revokeSession(row.id)"
                            >Sign out</ElButton
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <!-- Activity -->
        <ElCard shadow="never">
            <template #header>
                <div>
                    <h2 class="font-bold text-brand-900">Recent activity</h2>
                    <p class="text-xs text-slate-500">
                        Sign-in and security events recorded for your account.
                    </p>
                </div>
            </template>
            <ElEmpty
                v-if="activity.length === 0"
                description="No activity recorded yet"
                :image-size="80"
            />
            <ElTable v-else :data="activity" class="w-full">
                <ElTableColumn label="Event" min-width="220">
                    <template #default="{ row }">
                        <span class="flex items-center gap-2">
                            <span
                                class="size-2 rounded-full"
                                :class="
                                    row.outcome === 'success'
                                        ? 'bg-emerald-500'
                                        : 'bg-signal-500'
                                "
                                aria-hidden="true"
                            />
                            {{ eventLabels[row.event] ?? row.event }}
                        </span>
                    </template>
                </ElTableColumn>
                <ElTableColumn prop="device" label="Device" min-width="180" />
                <ElTableColumn label="Network address" min-width="140">
                    <template #default="{ row }">
                        <span class="crms-code">{{
                            row.ip_address ?? '—'
                        }}</span>
                    </template>
                </ElTableColumn>
                <ElTableColumn label="When" min-width="170">
                    <template #default="{ row }">
                        {{ formatDateTime(row.occurred_at) }}
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>
    </div>

    <!-- Recovery codes dialog -->
    <ElDialog
        v-model="recoveryDialogOpen"
        title="Recovery codes"
        width="min(480px, calc(100vw - 32px))"
        append-to-body
        @closed="visibleCodes = []"
    >
        <p class="mb-4 text-sm text-slate-600">
            Each code can be used once to sign in without your authenticator.
            Keep them private.
        </p>
        <div
            class="crms-code grid grid-cols-1 gap-x-4 gap-y-2 rounded-lg border border-dashed border-brand-300 bg-brand-50 p-4 text-[13px] whitespace-nowrap text-brand-900 sm:grid-cols-2"
        >
            <span v-for="code in visibleCodes" :key="code">{{ code }}</span>
        </div>
    </ElDialog>

    <!-- Sign out other sessions dialog -->
    <ElDialog
        v-model="resetAuthenticatorOpen"
        title="Replace your authenticator"
        width="min(440px, calc(100vw - 32px))"
        append-to-body
    >
        <p class="mb-4 text-sm text-slate-600">
            Your current authenticator and recovery codes will stop working, and
            other devices will be signed out. You will link a new authenticator
            straight away.
        </p>
        <ElForm label-position="top" @submit.prevent="submitResetAuthenticator">
            <ElFormItem
                v-if="!resetWithRecoveryCode"
                label="Code from your current authenticator app"
                :error="resetForm.errors.code"
            >
                <ElInput
                    v-model="resetForm.code"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    placeholder="000000"
                    :prefix-icon="Iphone"
                />
            </ElFormItem>
            <ElFormItem
                v-else
                label="Recovery code"
                :error="resetForm.errors.recovery_code"
            >
                <ElInput
                    v-model="resetForm.recovery_code"
                    name="recovery_code"
                    autocomplete="off"
                    placeholder="xxxxxxxxxx-xxxxxxxxxx"
                    :prefix-icon="Key"
                />
            </ElFormItem>
        </ElForm>
        <button
            type="button"
            class="text-sm font-semibold text-brand-600 hover:text-brand-800"
            @click="toggleResetMethod"
        >
            {{
                resetWithRecoveryCode
                    ? 'Use authenticator code'
                    : 'Lost your phone? Use a recovery code'
            }}
        </button>
        <template #footer>
            <ElButton @click="resetAuthenticatorOpen = false">Cancel</ElButton>
            <ElButton
                type="danger"
                :loading="resetForm.processing"
                @click="submitResetAuthenticator"
                >Replace authenticator</ElButton
            >
        </template>
    </ElDialog>
    <ElDialog
        v-model="signOutOthersOpen"
        title="Sign out other sessions"
        width="min(440px, calc(100vw - 32px))"
        append-to-body
    >
        <p class="mb-4 text-sm text-slate-600">
            Enter your password to sign out every other device. This device
            stays signed in.
        </p>
        <ElForm label-position="top" @submit.prevent="submitSignOutOthers">
            <ElFormItem label="Password" :error="sessionsForm.errors.password">
                <ElInput
                    v-model="sessionsForm.password"
                    type="password"
                    autocomplete="current-password"
                    :prefix-icon="Lock"
                    show-password
                />
            </ElFormItem>
        </ElForm>
        <template #footer>
            <ElButton @click="signOutOthersOpen = false">Cancel</ElButton>
            <ElButton
                type="primary"
                :loading="sessionsForm.processing"
                @click="submitSignOutOthers"
                >Sign out others</ElButton
            >
        </template>
    </ElDialog>
</template>
