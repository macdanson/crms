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
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatRelative } from '@/lib/utils';
import { index as casesIndex } from '@/routes/cases';
import { index as personsIndex } from '@/routes/persons';
import { index as reportsIndex } from '@/routes/reports';
import { show as searchShow } from '@/routes/search';
import { show as securityShow } from '@/routes/security';

const props = defineProps<{
    security: {
        mfa_enabled: boolean;
        recovery_codes_remaining: number;
        password_changed_at: string | null;
        previous_login: { at: string | null; ip: string | null } | null;
    };
    workload: {
        open_cases: number | null;
        assigned_to_me: number | null;
        access_requests_to_decide: number | null;
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

const { can } = usePermissions();

const counts = computed(() =>
    [
        { label: 'Open cases you can see', value: props.workload.open_cases },
        { label: 'Assigned to you', value: props.workload.assigned_to_me },
        {
            label: 'Access requests to decide',
            value: props.workload.access_requests_to_decide,
        },
    ].filter((count) => count.value !== null),
);

const modules = computed(() =>
    [
        {
            icon: Files,
            title: 'Cases',
            text: 'Register cases, assign investigators and track progress.',
            href: casesIndex.url(),
            show: props.workload.open_cases !== null,
        },
        {
            icon: User,
            title: 'People',
            text: 'Biodata, identity verification and case roles.',
            href: personsIndex.url(),
            show: can('persons.view'),
        },
        {
            icon: Search,
            title: 'Search',
            text: 'Purpose-bound, audited search across authorised areas.',
            href: searchShow.url(),
            show: can('search.records'),
        },
        {
            icon: DataAnalysis,
            title: 'Reports',
            text: 'Operational dashboards for the cases you can see.',
            href: reportsIndex.url(),
            show: can('reports.operational'),
        },
    ].filter((module) => module.show),
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-6">
        <section
            class="crms-pattern relative overflow-hidden rounded-2xl px-6 py-8 text-white sm:px-8"
        >
            <p
                class="text-xs font-semibold tracking-[0.2em] text-accent-500 uppercase"
            >
                {{
                    page.props.product.organisation ??
                    page.props.product.tagline
                }}
            </p>
            <h1 class="mt-2 text-2xl font-bold sm:text-3xl">
                {{ greeting }}, {{ user?.name }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-brand-200">
                You are signed in to the {{ page.props.product.full_name }}.
                Access only the records you need for your duties.
            </p>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <ElCard shadow="never" class="lg:col-span-1">
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-brand-900">
                            Account security
                        </h2>
                        <Link
                            :href="securityShow.url()"
                            class="text-sm font-semibold text-brand-600 hover:text-brand-800"
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
                                    ? 'text-accent-700'
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
                        <ElIcon :size="20" class="text-brand-600"
                            ><Lock
                        /></ElIcon>
                        <div>
                            <dt class="font-semibold text-slate-900">
                                Previous sign-in
                            </dt>
                            <dd
                                v-if="security.previous_login?.at"
                                class="text-slate-500"
                            >
                                {{ formatDateTime(security.previous_login.at) }}
                                <span v-if="security.previous_login.ip">
                                    from
                                    <span class="crms-code">{{
                                        security.previous_login.ip
                                    }}</span>
                                </span>
                            </dd>
                            <dd v-else class="text-slate-500">
                                None before this one
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
                    <h2 class="font-bold text-brand-900">Your work</h2>
                </template>
                <ul
                    v-if="counts.length > 0"
                    class="mb-4 grid gap-4 sm:grid-cols-3"
                >
                    <li
                        v-for="count in counts"
                        :key="count.label"
                        class="rounded-xl bg-brand-50 p-4"
                    >
                        <span class="block text-2xl font-bold text-brand-900">{{
                            count.value
                        }}</span>
                        <span class="text-sm text-slate-600">{{
                            count.label
                        }}</span>
                    </li>
                </ul>
                <ul v-if="modules.length > 0" class="grid gap-4 sm:grid-cols-2">
                    <li v-for="module in modules" :key="module.title">
                        <Link
                            :href="module.href"
                            class="flex gap-4 rounded-xl border border-slate-200 p-4 hover:border-brand-400 hover:bg-brand-50"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"
                            >
                                <ElIcon :size="20"
                                    ><component :is="module.icon"
                                /></ElIcon>
                            </span>
                            <span>
                                <span
                                    class="block font-semibold text-slate-900"
                                    >{{ module.title }}</span
                                >
                                <span
                                    class="mt-0.5 block text-sm text-slate-500"
                                    >{{ module.text }}</span
                                >
                            </span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-500">
                    No operational records are available to your account. If you
                    need access, ask your supervisor to request a role for you.
                </p>
            </ElCard>
        </div>
    </div>
</template>
