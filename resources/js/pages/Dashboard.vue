<script setup lang="ts">
import {
    CircleCheckFilled,
    DataAnalysis,
    Files,
    Key,
    Lock,
    Search,
    User,
    WarningFilled,
} from '@element-plus/icons-vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ElCard, ElIcon, ElTag } from 'element-plus';
import { computed } from 'vue';
import { formatDateTime, formatRelative } from '@/lib/utils';
import { show as securityShow } from '@/routes/security';

const props = defineProps<{
    security: {
        mfa_enabled: boolean;
        recovery_codes_remaining: number;
        password_changed_at: string | null;
        last_login_at: string | null;
        last_login_ip: string | null;
    };
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const greeting = computed(() => {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 17 ? 'Good afternoon' : 'Good evening';
});

const lowRecoveryCodes = computed(
    () => props.security.recovery_codes_remaining <= 3,
);

const modules = [
    {
        icon: Files,
        title: 'Case management',
        text: 'Register cases, assign investigators and track progress.',
    },
    {
        icon: User,
        title: 'Person records',
        text: 'Biodata, identity verification and duplicate review.',
    },
    {
        icon: Search,
        title: 'Search & verification',
        text: 'Purpose-bound, audited search across authorised areas.',
    },
    {
        icon: DataAnalysis,
        title: 'Reports',
        text: 'Operational dashboards and de-identified statistics.',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-6">
        <section
            class="crms-pattern relative overflow-hidden rounded-2xl px-6 py-8 text-white sm:px-8"
        >
            <p
                class="text-xs font-semibold tracking-[0.2em] text-gold-500 uppercase"
            >
                {{ page.props.product.organisation }}
            </p>
            <h1 class="mt-2 text-2xl font-bold sm:text-3xl">
                {{ greeting }}, {{ user?.name }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-crest-200">
                You are signed in to the {{ page.props.product.full_name }}.
                Access only the records you need for your duties.
            </p>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <ElCard shadow="never" class="lg:col-span-1">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-crest-900">
                            Account security
                        </h2>
                        <Link
                            :href="securityShow.url()"
                            class="text-sm font-semibold text-crest-600 hover:text-crest-800"
                            >Manage</Link
                        >
                    </div>
                </template>
                <dl class="space-y-4 text-sm">
                    <div class="flex items-start gap-3">
                        <ElIcon
                            :size="20"
                            :class="
                                security.mfa_enabled
                                    ? 'text-emerald-600'
                                    : 'text-signal-500'
                            "
                        >
                            <CircleCheckFilled v-if="security.mfa_enabled" />
                            <WarningFilled v-else />
                        </ElIcon>
                        <div>
                            <dt class="font-semibold text-slate-900">
                                Multi-factor authentication
                            </dt>
                            <dd class="text-slate-500">
                                {{
                                    security.mfa_enabled
                                        ? 'Enabled with authenticator app'
                                        : 'Not enabled'
                                }}
                            </dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <ElIcon
                            :size="20"
                            :class="
                                lowRecoveryCodes
                                    ? 'text-gold-700'
                                    : 'text-emerald-600'
                            "
                        >
                            <Key />
                        </ElIcon>
                        <div>
                            <dt class="font-semibold text-slate-900">
                                Recovery codes
                            </dt>
                            <dd class="text-slate-500">
                                {{ security.recovery_codes_remaining }} of 8
                                remaining
                                <ElTag
                                    v-if="lowRecoveryCodes"
                                    type="warning"
                                    size="small"
                                    class="ml-1"
                                    >Regenerate soon</ElTag
                                >
                            </dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <ElIcon :size="20" class="text-crest-600"
                            ><Lock
                        /></ElIcon>
                        <div>
                            <dt class="font-semibold text-slate-900">
                                Last sign-in
                            </dt>
                            <dd class="text-slate-500">
                                {{ formatDateTime(security.last_login_at) }}
                                <span v-if="security.last_login_ip">
                                    from
                                    <span class="crms-code">{{
                                        security.last_login_ip
                                    }}</span>
                                </span>
                            </dd>
                            <dd class="mt-1 text-xs text-slate-500">
                                Password changed
                                {{
                                    formatRelative(security.password_changed_at)
                                }}
                            </dd>
                        </div>
                    </div>
                </dl>
                <p
                    class="mt-5 rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-600"
                >
                    Don’t recognise a sign-in? Change your password and report
                    it to {{ page.props.product.support_contact }}.
                </p>
            </ElCard>

            <ElCard shadow="never" class="lg:col-span-2">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-crest-900">Modules</h2>
                        <ElTag type="info" effect="plain" round
                            >Phase 2 rollout</ElTag
                        >
                    </div>
                </template>
                <ul class="grid gap-4 sm:grid-cols-2">
                    <li
                        v-for="module in modules"
                        :key="module.title"
                        class="flex gap-4 rounded-xl border border-dashed border-slate-300 p-4"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-crest-50 text-crest-600"
                        >
                            <ElIcon :size="20"
                                ><component :is="module.icon"
                            /></ElIcon>
                        </span>
                        <span>
                            <span class="block font-semibold text-slate-900">{{
                                module.title
                            }}</span>
                            <span class="mt-0.5 block text-sm text-slate-500">{{
                                module.text
                            }}</span>
                        </span>
                    </li>
                </ul>
                <p class="mt-4 text-xs text-slate-500">
                    These modules become available as they pass security testing
                    and institutional approval.
                </p>
            </ElCard>
        </div>
    </div>
</template>
