<script setup lang="ts">
import { Lock, Message } from '@element-plus/icons-vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ElButton, ElForm, ElFormItem, ElInput } from 'element-plus';
import AuthHeading from '@/components/AuthHeading.vue';
import { store } from '@/routes/login';
import { request as passwordRequest } from '@/routes/password';

const page = usePage();

const form = useForm({
    email: '',
    password: '',
});

function submit(): void {
    form.post(store.url(), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <AuthHeading
        title="Sign in"
        description="Use your official email address and CRMS password."
    />

    <ElForm label-position="top" size="large" @submit.prevent="submit">
        <!-- page.props: the reason a sign-in in progress was ended elsewhere. -->
        <ElFormItem
            label="Email address"
            :error="form.errors.email ?? page.props.errors.email"
        >
            <ElInput
                v-model="form.email"
                v-focus
                type="email"
                name="email"
                autocomplete="username"
                placeholder="name@organisation.org"
                :prefix-icon="Message"
                required
            />
        </ElFormItem>

        <ElFormItem label="Password" :error="form.errors.password">
            <ElInput
                v-model="form.password"
                type="password"
                name="password"
                autocomplete="current-password"
                :prefix-icon="Lock"
                show-password
                required
            />
        </ElFormItem>

        <div class="-mt-2 mb-6 flex justify-end">
            <Link
                :href="passwordRequest.url()"
                class="text-sm font-semibold text-brand-600 hover:text-brand-800"
            >
                Forgot your password?
            </Link>
        </div>

        <ElButton
            type="primary"
            native-type="submit"
            class="w-full"
            :loading="form.processing"
        >
            Sign in
        </ElButton>
    </ElForm>

    <p class="mt-8 rounded-lg bg-slate-50 p-4 text-xs leading-5 text-slate-600">
        Accounts are issued by your system administrator. Never share your
        password or authenticator codes with anyone, including IT staff.
    </p>
</template>
