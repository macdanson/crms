<script setup lang="ts">
import { Lock, Tickets, User } from '@element-plus/icons-vue';
import { usePage } from '@inertiajs/vue3';
import { ElAlert, ElIcon } from 'element-plus';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoMark from '@/components/AppLogoMark.vue';

const page = usePage();
const product = computed(() => page.props.product);
const flash = computed(() => page.props.flash);
const year = new Date().getFullYear();

const assurances = [
    {
        icon: User,
        title: 'Individual accounts',
        text: 'Every action is attributable to a named officer.',
    },
    {
        icon: Lock,
        title: 'Multi-factor sign-in',
        text: 'A password and your authenticator app, every time.',
    },
    {
        icon: Tickets,
        title: 'Audited access',
        text: 'Sign-ins and record access are logged for review.',
    },
];
</script>

<template>
    <div class="flex min-h-svh bg-white">
        <aside
            class="crms-pattern relative hidden w-[44%] max-w-[640px] flex-col justify-between overflow-hidden p-12 text-white lg:flex"
        >
            <AppLogo reversed size="md" />

            <div class="relative z-10 max-w-md">
                <p
                    class="text-xs font-semibold tracking-[0.22em] text-gold-500 uppercase"
                >
                    {{ product.motto }}
                </p>
                <h2 class="mt-4 text-4xl leading-tight font-bold">
                    Accurate records.<br />Accountable access.
                </h2>
                <p class="mt-4 text-base leading-7 text-crest-200">
                    The secure national platform for registering cases, linking
                    people to cases and recording verified outcomes.
                </p>

                <ul class="mt-10 space-y-5">
                    <li
                        v-for="item in assurances"
                        :key="item.title"
                        class="flex gap-4"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white/10 text-gold-500 ring-1 ring-white/15"
                        >
                            <ElIcon :size="20"
                                ><component :is="item.icon"
                            /></ElIcon>
                        </span>
                        <span>
                            <span class="block font-semibold">{{
                                item.title
                            }}</span>
                            <span class="block text-sm text-crest-200">{{
                                item.text
                            }}</span>
                        </span>
                    </li>
                </ul>
            </div>

            <p class="relative z-10 text-xs text-crest-300">
                &copy; {{ year }} {{ product.organisation }}. Restricted system
                for authorised personnel.
            </p>

            <AppLogoMark
                class="pointer-events-none absolute -right-24 -bottom-24 size-[420px] opacity-[0.06]"
                variant="compact"
                aria-hidden="true"
            />
        </aside>

        <main class="flex flex-1 flex-col">
            <div
                class="h-1 w-full bg-gradient-to-r from-crest-600 via-gold-500 to-signal-500 lg:hidden"
                aria-hidden="true"
            />
            <div
                class="flex flex-1 items-center justify-center px-4 py-10 sm:px-8"
            >
                <div class="w-full max-w-[420px]">
                    <AppLogo class="mb-10 lg:hidden" size="md" />

                    <ElAlert
                        v-if="flash.status"
                        :title="flash.status"
                        type="success"
                        show-icon
                        :closable="false"
                        class="mb-6"
                    />
                    <ElAlert
                        v-if="flash.error"
                        :title="flash.error"
                        type="error"
                        show-icon
                        :closable="false"
                        class="mb-6"
                    />

                    <slot />
                </div>
            </div>
            <footer
                class="border-t border-slate-200 px-4 py-5 text-center text-xs leading-5 text-slate-500 sm:px-8"
            >
                <strong class="font-semibold text-slate-700"
                    >Authorised use only.</strong
                >
                Activity on this system is recorded and may be audited. Misuse
                may lead to disciplinary or criminal action.
            </footer>
        </main>
    </div>
</template>
