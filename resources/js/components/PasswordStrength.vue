<script setup lang="ts">
import { CircleCheckFilled, CircleCloseFilled } from '@element-plus/icons-vue';
import { ElIcon } from 'element-plus';
import { computed } from 'vue';

/**
 * Live checklist for the CRMS password policy. The server enforces the same
 * rules (and breached-password screening in production); this is guidance only.
 */
const props = defineProps<{
    password: string;
}>();

const rules = computed(() => [
    { label: 'At least 12 characters', met: props.password.length >= 12 },
    {
        label: 'Upper and lower case letters',
        met: /[a-z]/.test(props.password) && /[A-Z]/.test(props.password),
    },
    { label: 'At least one number', met: /\d/.test(props.password) },
    {
        label: 'At least one symbol',
        met: /[^A-Za-z0-9]/.test(props.password),
    },
]);

const score = computed(() => rules.value.filter((rule) => rule.met).length);

const strength = computed(() => {
    if (props.password.length === 0) {
        return { label: '', color: 'bg-slate-200' };
    }

    return (
        [
            { label: 'Very weak', color: 'bg-signal-500' },
            { label: 'Weak', color: 'bg-signal-500' },
            { label: 'Fair', color: 'bg-gold-500' },
            { label: 'Good', color: 'bg-gold-500' },
            { label: 'Meets policy', color: 'bg-emerald-600' },
        ][score.value] ?? { label: '', color: 'bg-slate-200' }
    );
});
</script>

<template>
    <div class="mt-2 w-full" aria-live="polite">
        <div class="flex items-center gap-3">
            <div class="grid flex-1 grid-cols-4 gap-1.5" aria-hidden="true">
                <span
                    v-for="index in 4"
                    :key="index"
                    class="h-1.5 rounded-full transition-colors"
                    :class="index <= score ? strength.color : 'bg-slate-200'"
                />
            </div>
            <span
                class="w-24 text-right text-xs font-semibold text-slate-600"
                >{{ strength.label }}</span
            >
        </div>
        <ul class="mt-2 grid gap-1 text-xs sm:grid-cols-2">
            <li
                v-for="rule in rules"
                :key="rule.label"
                class="flex items-center gap-1.5"
                :class="rule.met ? 'text-emerald-700' : 'text-slate-500'"
            >
                <ElIcon :size="14">
                    <CircleCheckFilled v-if="rule.met" />
                    <CircleCloseFilled v-else />
                </ElIcon>
                {{ rule.label }}
            </li>
        </ul>
    </div>
</template>
