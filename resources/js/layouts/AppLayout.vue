<script setup lang="ts">
import {
    ArrowDown,
    DataAnalysis,
    Document,
    Expand,
    Files,
    Lock,
    Odometer,
    Search,
    SwitchButton,
    User,
} from '@element-plus/icons-vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    ElAvatar,
    ElDrawer,
    ElDropdown,
    ElDropdownItem,
    ElDropdownMenu,
    ElIcon,
    ElMenu,
    ElMenuItem,
    ElMenuItemGroup,
    ElTag,
} from 'element-plus';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import IdleTimeoutDialog from '@/components/IdleTimeoutDialog.vue';
import { useFlashMessages } from '@/composables/useFlashMessages';
import { dashboard, logout } from '@/routes';
import { show as securityShow } from '@/routes/security';

type NavItem = {
    label: string;
    icon: Component;
    href?: string;
    upcoming?: boolean;
};

const page = usePage();
const user = computed(() => page.props.auth.user);
const product = computed(() => page.props.product);
const drawerOpen = ref(false);

useFlashMessages();

const operationalItems: NavItem[] = [
    { label: 'Dashboard', icon: Odometer, href: dashboard.url() },
    { label: 'Cases', icon: Files, upcoming: true },
    { label: 'Persons', icon: User, upcoming: true },
    { label: 'Search', icon: Search, upcoming: true },
    { label: 'Reports', icon: DataAnalysis, upcoming: true },
];

const accountItems: NavItem[] = [
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
                            :index="item.href ?? item.label"
                            :disabled="item.upcoming"
                        >
                            <ElIcon><component :is="item.icon" /></ElIcon>
                            <span class="flex-1">{{ item.label }}</span>
                            <ElTag
                                v-if="item.upcoming"
                                size="small"
                                effect="dark"
                                type="info"
                                round
                                >Soon</ElTag
                            >
                        </ElMenuItem>
                    </ElMenuItemGroup>
                    <ElMenuItemGroup title="Account">
                        <ElMenuItem
                            v-for="item in accountItems"
                            :key="item.label"
                            :index="item.href ?? item.label"
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
                        v-for="item in [...operationalItems, ...accountItems]"
                        :key="item.label"
                        :index="item.href ?? item.label"
                        :disabled="item.upcoming"
                    >
                        <ElIcon><component :is="item.icon" /></ElIcon>
                        <span class="flex-1">{{ item.label }}</span>
                        <ElTag
                            v-if="item.upcoming"
                            size="small"
                            effect="dark"
                            type="info"
                            round
                            >Soon</ElTag
                        >
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
