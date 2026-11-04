<script setup lang="ts">
import { Lock } from '@element-plus/icons-vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ElButton, ElForm, ElFormItem, ElInput } from 'element-plus';
import { computed } from 'vue';
import AuthHeading from '@/components/AuthHeading.vue';
import { store } from '@/routes/password/confirm';

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    password: '',
});

function submit(): void {
    form.post(store.url(), {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Confirm password" />

    <AuthHeading
        title="Confirm it’s you"
        description="This area contains sensitive account settings. Re-enter your password to continue."
    />

    <div
        v-if="user"
        class="mb-6 flex items-center gap-3 rounded-lg border border-slate-200 p-3"
    >
        <span
            class="flex size-10 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white"
            >{{ user.initials }}</span
        >
        <span class="leading-tight">
            <span class="block font-semibold text-slate-900">{{
                user.name
            }}</span>
            <span class="block text-sm text-slate-500">{{ user.email }}</span>
        </span>
    </div>

    <ElForm label-position="top" size="large" @submit.prevent="submit">
        <ElFormItem label="Password" :error="form.errors.password">
            <ElInput
                v-model="form.password"
                v-focus
                type="password"
                name="password"
                autocomplete="current-password"
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
            Confirm password
        </ElButton>
    </ElForm>
</template>
