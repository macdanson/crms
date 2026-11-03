<script setup lang="ts">
import { Timer } from '@element-plus/icons-vue';
import { ElButton, ElDialog, ElIcon } from 'element-plus';
import { computed } from 'vue';
import { useIdleTimeout } from '@/composables/useIdleTimeout';

const {
    showWarning,
    secondsRemaining,
    warningReason,
    staySignedIn,
    continueWorking,
    signOutNow,
} = useIdleTimeout();

const isMaximumLength = computed(() => warningReason.value === 'absolute');

const countdown = computed(() => {
    const minutes = Math.floor(secondsRemaining.value / 60);
    const seconds = String(secondsRemaining.value % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});
</script>

<template>
    <ElDialog
        :model-value="showWarning"
        width="min(420px, calc(100vw - 32px))"
        :show-close="false"
        :close-on-click-modal="false"
        :close-on-press-escape="false"
        align-center
        append-to-body
        aria-describedby="idle-timeout-description"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <span
                    class="flex size-10 items-center justify-center rounded-full bg-accent-100 text-accent-700"
                >
                    <ElIcon :size="22"><Timer /></ElIcon>
                </span>
                <h2 class="text-lg font-bold text-brand-900">
                    {{
                        isMaximumLength
                            ? 'Your session is about to end'
                            : 'Are you still there?'
                    }}
                </h2>
            </div>
        </template>
        <p
            id="idle-timeout-description"
            class="text-sm leading-6 text-slate-600"
        >
            <template v-if="isMaximumLength">
                For security, sessions have a maximum length. Save your work
                now; you will need to sign in again.
            </template>
            <template v-else>
                For security, you will be signed out after a period of
                inactivity.
            </template>
        </p>
        <p
            class="crms-code mt-4 text-center text-4xl font-semibold text-brand-900"
            role="timer"
            aria-live="assertive"
        >
            {{ countdown }}
        </p>
        <template #footer>
            <div class="flex justify-end gap-2">
                <ElButton @click="signOutNow">Sign out now</ElButton>
                <ElButton
                    v-if="isMaximumLength"
                    type="primary"
                    @click="continueWorking"
                >
                    Continue working
                </ElButton>
                <ElButton v-else type="primary" @click="staySignedIn">
                    Stay signed in
                </ElButton>
            </div>
        </template>
    </ElDialog>
</template>
