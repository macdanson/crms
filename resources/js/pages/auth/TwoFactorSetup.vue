<script setup lang="ts">
import { CopyDocument, Iphone } from '@element-plus/icons-vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCheckbox,
    ElForm,
    ElFormItem,
    ElInput,
    ElMessage,
    ElStep,
    ElSteps,
} from 'element-plus';
import { computed, ref } from 'vue';
import AuthHeading from '@/components/AuthHeading.vue';
import RecoveryCodesList from '@/components/RecoveryCodesList.vue';
import { dashboard, logout } from '@/routes';
import { confirm } from '@/routes/two-factor';

const props = defineProps<{
    step: 'scan' | 'recovery';
    qrCodeSvg: string | null;
    setupKey: string | null;
    recoveryCodes: string[];
}>();

const savedCodes = ref(false);

const form = useForm({
    code: '',
});

const formattedKey = computed(
    () => props.setupKey?.match(/.{1,4}/g)?.join(' ') ?? '',
);

function confirmCode(): void {
    form.transform((data) => ({ code: data.code.replace(/\s/g, '') })).post(
        confirm.url(),
        {
            errorBag: 'confirmTwoFactorAuthentication',
            preserveScroll: true,
            onError: () => form.reset(),
        },
    );
}

async function copyKey(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.setupKey ?? '');
        ElMessage.success('Setup key copied.');
    } catch {
        ElMessage.error('Copy failed. Type the key manually.');
    }
}

function finish(): void {
    router.visit(dashboard.url());
}
</script>

<template>
    <Head title="Set up multi-factor authentication" />

    <ElSteps
        :active="step === 'scan' ? 1 : 2"
        finish-status="success"
        align-center
        class="mb-8"
    >
        <ElStep title="Password" />
        <ElStep title="Authenticator" />
        <ElStep title="Ready" />
    </ElSteps>

    <template v-if="step === 'scan'">
        <AuthHeading
            title="Link your authenticator app"
            description="CRMS requires a second sign-in step. Scan the code with an authenticator app such as Microsoft Authenticator or Google Authenticator."
        />

        <div
            class="flex flex-col items-center gap-4 rounded-xl border border-slate-200 p-5"
        >
            <!-- The QR SVG is generated server-side by Fortify. -->
            <div
                class="size-48 rounded-lg bg-white p-2 ring-1 ring-slate-200 [&_svg]:h-full [&_svg]:w-full"
                aria-label="QR code for your authenticator app"
                role="img"
                v-html="qrCodeSvg"
            />
            <div class="w-full text-center">
                <p class="text-xs font-semibold text-slate-500 uppercase">
                    Can’t scan? Enter this key
                </p>
                <div class="mt-1 flex items-center justify-center gap-2">
                    <code
                        class="crms-code rounded bg-brand-50 px-2 py-1 text-sm text-brand-900"
                        >{{ formattedKey }}</code
                    >
                    <ElButton
                        :icon="CopyDocument"
                        size="small"
                        text
                        aria-label="Copy setup key"
                        @click="copyKey"
                    />
                </div>
            </div>
        </div>

        <ElForm
            label-position="top"
            size="large"
            class="mt-6"
            @submit.prevent="confirmCode"
        >
            <ElFormItem
                label="6-digit code from the app"
                :error="form.errors.code"
            >
                <ElInput
                    v-model="form.code"
                    v-focus
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    placeholder="000000"
                    class="crms-otp"
                    :prefix-icon="Iphone"
                    required
                />
            </ElFormItem>
            <ElButton
                type="primary"
                native-type="submit"
                class="w-full"
                :loading="form.processing"
            >
                Confirm and enable
            </ElButton>
        </ElForm>

        <button
            type="button"
            class="mt-6 text-sm font-semibold text-slate-500 hover:text-slate-700"
            @click="router.post(logout.url())"
        >
            Sign out and finish later
        </button>
    </template>

    <template v-else>
        <AuthHeading
            title="Save your recovery codes"
            description="If you lose your phone, each code lets you sign in once. They will not be shown again."
        />

        <ElAlert
            type="warning"
            show-icon
            :closable="false"
            title="Store these offline in a safe place. Do not share them."
            class="mb-5"
        />

        <RecoveryCodesList :codes="recoveryCodes" />

        <ElCheckbox v-model="savedCodes" class="mt-6">
            I have saved my recovery codes somewhere safe
        </ElCheckbox>

        <ElButton
            type="primary"
            size="large"
            class="mt-4 w-full"
            :disabled="!savedCodes"
            @click="finish"
        >
            Continue to CRMS
        </ElButton>
    </template>
</template>

<style scoped>
.crms-otp :deep(input) {
    font-family: var(--font-mono);
    font-size: 1.375rem;
    letter-spacing: 0.5em;
    text-align: center;
}
</style>
