<script setup lang="ts">
import { Lock, Message } from '@element-plus/icons-vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ElButton, ElForm, ElFormItem, ElInput } from 'element-plus';
import AuthHeading from '@/components/AuthHeading.vue';
import PasswordStrength from '@/components/PasswordStrength.vue';
import { update } from '@/routes/password';

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
    form.post(update.url(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Choose a new password" />

    <AuthHeading
        title="Choose a new password"
        description="After resetting, you will be signed out of every device and asked to sign in again."
    />

    <ElForm label-position="top" size="large" @submit.prevent="submit">
        <ElFormItem label="Email address" :error="form.errors.email">
            <ElInput
                v-model="form.email"
                type="email"
                name="email"
                autocomplete="username"
                :prefix-icon="Message"
                required
            />
        </ElFormItem>

        <ElFormItem label="New password" :error="form.errors.password">
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
            label="Confirm new password"
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
            Reset password
        </ElButton>
    </ElForm>
</template>
