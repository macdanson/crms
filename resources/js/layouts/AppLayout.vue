<script setup lang="ts">
import {
    ArrowDown,
    Bell,
    Collection,
    CopyDocument,
    DataAnalysis,
    Document,
    Download,
    Expand,
    Files,
    Key,
    Lock,
    Odometer,
    OfficeBuilding,
    Search,
    SwitchButton,
    Tickets,
    TrendCharts,
    Unlock,
    User,
    UserFilled,
    Warning,
} from '@element-plus/icons-vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ElAvatar,
    ElBadge,
    ElDrawer,
    ElDropdown,
    ElDropdownItem,
    ElDropdownMenu,
    ElIcon,
    ElMenu,
    ElMenuItem,
    ElMenuItemGroup,
} from 'element-plus';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import IdleTimeoutDialog from '@/components/IdleTimeoutDialog.vue';
import { useFlashMessages } from '@/composables/useFlashMessages';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard, logout } from '@/routes';
import { index as accessIndex } from '@/routes/access-requests';
import { index as alertsIndex } from '@/routes/admin/alerts';
import { index as auditIndex } from '@/routes/admin/audit';
import { index as catalogueIndex } from '@/routes/admin/catalogue';
import { index as organisationIndex } from '@/routes/admin/organisation';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import { index as casesIndex } from '@/routes/cases';
import { index as exportsIndex } from '@/routes/exports';
import { index as notificationsIndex } from '@/routes/notifications';
import { index as personsIndex } from '@/routes/persons';
import { index as duplicatesIndex } from '@/routes/persons/duplicates';
import {
    index as reportsIndex,
    statistics as reportsStatistics,
} from '@/routes/reports';
import { show as searchShow } from '@/routes/search';
import { show as securityShow } from '@/routes/security';

type NavItem = {
    label: string;
    icon: Component;
    href: string;
};

const page = usePage();
const user = computed(() => page.props.auth.user);
const product = computed(() => page.props.product);
const drawerOpen = ref(false);

useFlashMessages();

const { can } = usePermissions();

function canAny(...permissions: string[]): boolean {
    return permissions.some((permission) => can(permission));
}

/*
 * Only what this user can use is shown. This is a convenience, not a control:
 * the server authorises every request.
 */
const operationalItems = computed<NavItem[]>(() =>
    [
        {
            label: 'Dashboard',
            icon: Odometer,
            href: dashboard.url(),
            show: true,
        },
        {
            label: 'Cases',
            icon: Files,
            href: casesIndex.url(),
            show: canAny(
                'cases.view.assigned',
                'cases.view.station',
                'cases.view.district',
                'cases.view.national',
            ),
        },
        {
            label: 'People',
            icon: User,
            href: personsIndex.url(),
            show: can('persons.view'),
        },
        {
            label: 'Duplicate review',
            icon: CopyDocument,
            href: duplicatesIndex.url(),
            show: can('persons.merge'),
        },
        {
            label: 'Search',
            icon: Search,
            href: searchShow.url(),
            show: can('search.records'),
        },
        {
            label: 'Access requests',
            icon: Unlock,
            href: accessIndex.url(),
            show: canAny('access.request', 'access.approve'),
        },
        {
            label: 'Exports',
            icon: Download,
            href: exportsIndex.url(),
            show: canAny('exports.request', 'exports.approve'),
        },
        {
            label: 'Reports',
            icon: DataAnalysis,
            href: reportsIndex.url(),
            show: can('reports.operational'),
        },
        {
            label: 'Statistics',
            icon: TrendCharts,
            href: reportsStatistics.url(),
            show: can('reports.statistics'),
        },
    ].filter((item) => item.show),
);

const adminItems = computed<NavItem[]>(() =>
    [
        {
            label: 'Users',
            icon: UserFilled,
            href: usersIndex.url(),
            show: can('users.view'),
        },
        {
            label: 'Roles',
            icon: Key,
            href: rolesIndex.url(),
            show: can('users.view'),
        },
        {
            label: 'Organisation',
            icon: OfficeBuilding,
            href: organisationIndex.url(),
            show: can('organisation.manage'),
        },
        {
            label: 'Offence catalogue',
            icon: Collection,
            href: catalogueIndex.url(),
            show: can('catalogue.manage'),
        },
        {
            label: 'Audit log',
            icon: Tickets,
            href: auditIndex.url(),
            show: can('audit.view'),
        },
        {
            label: 'Security alerts',
            icon: Warning,
            href: alertsIndex.url(),
            show: can('alerts.manage'),
        },
    ].filter((item) => item.show),
);

const accountItems: NavItem[] = [
    { label: 'Notifications', icon: Bell, href: notificationsIndex.url() },
    { label: 'Account security', icon: Lock, href: securityShow.url() },
];

const activePath = computed(() => page.url.split('?')[0]);

function navigate(href: string): void {
    drawerOpen.value = false;
    router.visit(href);
}

function handleUserCommand(command: string): void {
    if (command === 'security') {
        router.visit(securityShow.url());
    }

    if (command === 'logout') {
        router.post(logout.url());
    }
}
</script>

<template>
    <div class="flex min-h-svh bg-surface">
        <!-- Desktop sidebar -->
        <aside
            class="sticky top-0 hidden h-svh w-64 shrink-0 flex-col bg-brand-900 text-white lg:flex"
        >
            <div class="flex h-16 items-center border-b border-white/10 px-5">
                <AppLogo reversed size="sm" :show-descriptor="false" />
                <span
                    class="ml-3 rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold tracking-wider text-accent-500 uppercase"
                    >Restricted</span
                >
            </div>
            <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Main">
                <ElMenu
                    :default-active="activePath"
                    class="crms-nav"
                    background-color="transparent"
                    text-color="#C9D2EA"
                    active-text-color="#FFFFFF"
                    @select="navigate"
                >
                    <ElMenuItemGroup title="Operations">
                        <ElMenuItem
                            v-for="item in operationalItems"
                            :key="item.label"
                            :index="item.href"
                        >
                            <ElIcon><component :is="item.icon" /></ElIcon>
                            <span class="flex-1">{{ item.label }}</span>
                        </ElMenuItem>
                    </ElMenuItemGroup>
                    <ElMenuItemGroup
                        v-if="adminItems.length > 0"
                        title="Administration"
                    >
                        <ElMenuItem
                            v-for="item in adminItems"
                            :key="item.label"
                            :index="item.href"
                        >
                            <ElIcon><component :is="item.icon" /></ElIcon>
                            <span class="flex-1">{{ item.label }}</span>
                        </ElMenuItem>
                    </ElMenuItemGroup>
                    <ElMenuItemGroup title="Account">
                        <ElMenuItem
                            v-for="item in accountItems"
                            :key="item.label"
                            :index="item.href"
                        >
                            <ElIcon><component :is="item.icon" /></ElIcon>
                            <span>{{ item.label }}</span>
                        </ElMenuItem>
                    </ElMenuItemGroup>
                </ElMenu>
            </nav>
            <div
                class="border-t border-white/10 px-5 py-4 text-[11px] text-brand-300"
            >
                {{
                    [product.organisation, product.tagline]
                        .filter(Boolean)
                        .join(' · ')
                }}
            </div>
        </aside>

        <!-- Mobile navigation -->
        <ElDrawer
            v-model="drawerOpen"
            direction="ltr"
            size="272px"
            :with-header="false"
            class="crms-drawer"
        >
            <div class="flex h-full flex-col bg-brand-900 text-white">
                <div
                    class="flex h-16 items-center border-b border-white/10 px-5"
                >
                    <AppLogo reversed size="sm" :show-descriptor="false" />
                </div>
                <ElMenu
                    :default-active="activePath"
                    class="crms-nav flex-1 px-3 py-4"
                    background-color="transparent"
                    text-color="#C9D2EA"
                    active-text-color="#FFFFFF"
                    @select="navigate"
                >
                    <ElMenuItem
                        v-for="item in [
                            ...operationalItems,
                            ...adminItems,
                            ...accountItems,
                        ]"
                        :key="item.label"
                        :index="item.href"
                    >
                        <ElIcon><component :is="item.icon" /></ElIcon>
                        <span class="flex-1">{{ item.label }}</span>
                    </ElMenuItem>
                </ElMenu>
            </div>
        </ElDrawer>

        <div class="flex min-w-0 flex-1 flex-col">
            <div
                class="bg-brand-950 px-4 py-1 text-center text-[11px] font-medium tracking-wide text-brand-200"
            >
                Authorised use only · Activity is recorded and audited
            </div>
            <header
                class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6"
            >
                <button
                    type="button"
                    class="flex size-10 items-center justify-center rounded-md text-brand-900 hover:bg-brand-50 lg:hidden"
                    aria-label="Open navigation"
                    @click="drawerOpen = true"
                >
                    <ElIcon :size="22"><Expand /></ElIcon>
                </button>
                <AppLogo class="lg:hidden" size="sm" :show-descriptor="false" />

                <div class="ml-auto flex items-center gap-3">
                    <Link
                        v-if="user"
                        :href="notificationsIndex.url()"
                        class="flex size-10 items-center justify-center rounded-md text-brand-900 hover:bg-brand-50"
                        :aria-label="`Notifications (${user.unread_notifications} unread)`"
                    >
                        <ElBadge
                            :value="user.unread_notifications"
                            :hidden="user.unread_notifications === 0"
                            :max="99"
                        >
                            <ElIcon :size="20"><Bell /></ElIcon>
                        </ElBadge>
                    </Link>
                    <ElDropdown
                        v-if="user"
                        trigger="click"
                        @command="handleUserCommand"
                    >
                        <button
                            type="button"
                            class="flex items-center gap-3 rounded-lg px-2 py-1.5 text-left hover:bg-slate-100"
                        >
                            <ElAvatar
                                :size="34"
                                class="!bg-brand-600 font-semibold"
                                >{{ user.initials }}</ElAvatar
                            >
                            <span class="hidden leading-tight sm:block">
                                <span
                                    class="block text-sm font-semibold text-slate-900"
                                    >{{ user.name }}</span
                                >
                                <span class="block text-xs text-slate-500">
                                    {{ user.job_title ?? 'User' }}
                                    <template v-if="user.staff_number">
                                        ·
                                        <span class="crms-code">{{
                                            user.staff_number
                                        }}</span>
                                    </template>
                                </span>
                            </span>
                            <ElIcon class="text-slate-400"
                                ><ArrowDown
                            /></ElIcon>
                        </button>
                        <template #dropdown>
                            <ElDropdownMenu>
                                <ElDropdownItem command="security" :icon="Lock">
                                    Account security
                                </ElDropdownItem>
                                <ElDropdownItem
                                    command="logout"
                                    :icon="SwitchButton"
                                    divided
                                >
                                    Sign out
                                </ElDropdownItem>
                            </ElDropdownMenu>
                        </template>
                    </ElDropdown>
                </div>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <div class="mx-auto w-full max-w-6xl">
                    <slot />
                </div>
            </main>

            <footer
                class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-4 text-xs text-slate-600 sm:px-6 lg:px-8"
            >
                <span class="flex items-center gap-2">
                    <ElIcon><Document /></ElIcon>
                    {{ product.short_name }} · {{ product.full_name }}
                </span>
                <span>Need help? Contact {{ product.support_contact }}.</span>
            </footer>
        </div>

        <IdleTimeoutDialog />
    </div>
</template>

<style scoped>
.crms-nav {
    border-right: none;
    --el-menu-item-height: 42px;
    --el-menu-hover-bg-color: rgb(255 255 255 / 0.08);
}

.crms-nav :deep(.el-menu-item) {
    border-radius: 8px;
    margin-bottom: 2px;
    gap: 2px;
}

.crms-nav :deep(.el-menu-item.is-active) {
    background-color: rgb(255 255 255 / 0.12);
    box-shadow: inset 3px 0 0 var(--color-accent-500);
}

.crms-nav :deep(.el-menu-item.is-disabled) {
    opacity: 0.55;
}

.crms-nav :deep(.el-menu-item-group__title) {
    color: var(--color-brand-400);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    padding-left: 12px;
}

:global(.crms-drawer .el-drawer__body) {
    padding: 0;
}
</style>
