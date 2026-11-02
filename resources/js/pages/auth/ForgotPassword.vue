<script setup lang="ts">
import { ArrowLeft, Message } from '@element-plus/icons-vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ElButton, ElForm, ElFormItem, ElIcon, ElInput } from 'element-plus';
import AuthHeading from '@/components/AuthHeading.vue';
import { login } from '@/routes';
import { email } from '@/routes/password';

const form = useForm({
    email: '',
});

function submit(): void {
    form.post(email.url());
}
</script>

<template>
    <Head title="Reset password" />

    <AuthHeading
        title="Reset your password"
        description="Enter your official email address. If it belongs to an account, we will send a single-use reset link."
    />

    <ElForm label-position="top" size="large" @submit.prevent="submit">
        <ElFormItem label="Email address" :error="form.errors.email">
            <ElInput
                v-model="form.email"
                v-focus
                type="email"
                name="email"
                autocomplete="username"
                :prefix-icon="Message"
                required
            />
        </ElFormItem>

        <ElButton
            type="primary"
            native-type="submit"
            class="w-full"
            :loading="form.processing"
        >
            Send reset link
        </ElButton>
    </ElForm>

    <Link
        :href="login.url()"
        class="mt-8 inline-flex items-center gap-2 text-sm font-semibold text-crest-600 hover:text-crest-800"
    >
        <ElIcon><ArrowLeft /></ElIcon>
        Back to sign in
    </Link>
</template>
