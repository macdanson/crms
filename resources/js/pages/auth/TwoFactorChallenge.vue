<script setup lang="ts">
import { Iphone, Key } from '@element-plus/icons-vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ElButton, ElForm, ElFormItem, ElIcon, ElInput } from 'element-plus';
import { computed, nextTick, ref } from 'vue';
import AuthHeading from '@/components/AuthHeading.vue';
import { login } from '@/routes';
import { store } from '@/routes/two-factor/login';

const usingRecoveryCode = ref(false);
const codeInput = ref<InstanceType<typeof ElInput> | null>(null);

const form = useForm({
    code: '',
    recovery_code: '',
});

const heading = computed(() =>
    usingRecoveryCode.value
        ? {
              title: 'Use a recovery code',
              description:
                  'Enter one of the single-use recovery codes you saved when you set up your authenticator.',
          }
        : {
              title: 'Verify it’s you',
              description:
                  'Open your authenticator app and enter the 6-digit code for CRMS.',
          },
);

async function toggleMode(): Promise<void> {
    usingRecoveryCode.value = !usingRecoveryCode.value;
    form.reset();
    form.clearErrors();
    await nextTick();
    codeInput.value?.focus();
}

function submit(): void {
    form.transform((data) =>
        usingRecoveryCode.value
            ? { recovery_code: data.recovery_code.trim() }
            : { code: data.code.replace(/\s/g, '') },
    ).post(store.url(), {
        onError: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Verify sign-in" />

    <AuthHeading :title="heading.title" :description="heading.description" />

    <ElForm label-position="top" size="large" @submit.prevent="submit">
        <ElFormItem
            v-if="!usingRecoveryCode"
            label="Authentication code"
            :error="form.errors.code"
        >
            <ElInput
                ref="codeInput"
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

        <ElFormItem
            v-else
            label="Recovery code"
            :error="form.errors.recovery_code"
        >
            <ElInput
                ref="codeInput"
                v-model="form.recovery_code"
                name="recovery_code"
                autocomplete="off"
                placeholder="xxxxxxxxxx-xxxxxxxxxx"
                class="crms-code"
                :prefix-icon="Key"
                required
            />
        </ElFormItem>

        <ElButton
            type="primary"
            native-type="submit"
            class="w-full"
            :loading="form.processing"
        >
            Verify and continue
        </ElButton>
    </ElForm>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
        <button
            type="button"
            class="inline-flex items-center gap-1.5 font-semibold text-crest-600 hover:text-crest-800"
            @click="toggleMode"
        >
            <ElIcon
                ><component :is="usingRecoveryCode ? Iphone : Key"
            /></ElIcon>
            {{
                usingRecoveryCode
                    ? 'Use authenticator code'
                    : 'Use a recovery code'
            }}
        </button>
        <Link
            :href="login.url()"
            class="font-semibold text-slate-500 hover:text-slate-700"
        >
            Start over
        </Link>
    </div>

    <p class="mt-8 rounded-lg bg-slate-50 p-4 text-xs leading-5 text-slate-600">
        Lost your phone and your recovery codes? Contact your station system
        administrator to reset your authenticator.
    </p>
</template>

<style scoped>
.crms-otp :deep(input) {
    font-family: var(--font-mono);
    font-size: 1.375rem;
    letter-spacing: 0.5em;
    text-align: center;
}
</style>
