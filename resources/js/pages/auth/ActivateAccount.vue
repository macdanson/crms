<script setup lang="ts">
import { Lock, Message } from '@element-plus/icons-vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    ElButton,
    ElForm,
    ElFormItem,
    ElInput,
    ElSteps,
    ElStep,
} from 'element-plus';
import AuthHeading from '@/components/AuthHeading.vue';
import PasswordStrength from '@/components/PasswordStrength.vue';
import { store } from '@/routes/activation';

const props = defineProps<{
    token: string;
    email: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post(store.url(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Activate your account" />

    <ElSteps :active="0" finish-status="success" align-center class="mb-8">
        <ElStep title="Password" />
        <ElStep title="Authenticator" />
        <ElStep title="Ready" />
    </ElSteps>

    <AuthHeading
        title="Activate your account"
        description="Choose a strong password you have not used anywhere else. Next, you will link an authenticator app on your phone."
    />

    <ElForm label-position="top" size="large" @submit.prevent="submit">
        <ElFormItem label="Email address" :error="form.errors.email">
            <ElInput
                v-model="form.email"
                type="email"
                name="email"
                autocomplete="username"
                :prefix-icon="Message"
                readonly
            />
        </ElFormItem>

        <ElFormItem label="Password" :error="form.errors.password">
            <ElInput
                v-model="form.password"
                v-focus
                type="password"
                name="password"
                autocomplete="new-password"
                :prefix-icon="Lock"
                show-password
                required
            />
            <PasswordStrength :password="form.password" />
        </ElFormItem>

        <ElFormItem
            label="Confirm password"
            :error="form.errors.password_confirmation"
        >
            <ElInput
                v-model="form.password_confirmation"
                type="password"
                name="password_confirmation"
                autocomplete="new-password"
                :prefix-icon="Lock"
                show-password
                required
            />
        </ElFormItem>

        <ElButton
            type="primary"
            native-type="submit"
            class="w-full"
            :loading="form.processing"
        >
            Activate account
        </ElButton>
    </ElForm>
</template>
