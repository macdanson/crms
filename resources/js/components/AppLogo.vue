<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import { cn } from '@/lib/utils';

/**
 * Horizontal lockup: Trace mark + CRMS wordmark + descriptor. The
 * organisation line only appears when CRMS_ORGANISATION is configured.
 * Use `reversed` on Ink / dark backgrounds.
 */
const props = withDefaults(
    defineProps<{
        reversed?: boolean;
        size?: 'sm' | 'md' | 'lg';
        showDescriptor?: boolean;
    }>(),
    {
        reversed: false,
        size: 'md',
        showDescriptor: true,
    },
);

const page = usePage();
const product = computed(() => page.props.product);

const markSize = computed(
    () => ({ sm: 'size-8', md: 'size-10', lg: 'size-14' })[props.size],
);
const wordSize = computed(
    () => ({ sm: 'text-lg', md: 'text-xl', lg: 'text-3xl' })[props.size],
);
</script>

<template>
    <div class="flex items-center gap-3">
        <AppLogoMark
            :variant="size === 'sm' ? 'compact' : 'full'"
            :class="cn('shrink-0', markSize)"
            :title="product.short_name"
        />
        <div class="flex items-center gap-3">
            <span
                :class="
                    cn(
                        'font-extrabold tracking-[0.12em]',
                        wordSize,
                        reversed ? 'text-white' : 'text-brand-900',
                    )
                "
            >
                {{ product.short_name }}
            </span>
            <template v-if="showDescriptor">
                <span
                    :class="
                        cn(
                            'h-8 w-px',
                            reversed ? 'bg-white/25' : 'bg-slate-300',
                        )
                    "
                    aria-hidden="true"
                />
                <span class="flex flex-col leading-tight">
                    <span
                        :class="
                            cn(
                                'text-xs font-semibold',
                                reversed ? 'text-white' : 'text-brand-900',
                            )
                        "
                    >
                        {{ product.full_name }}
                    </span>
                    <span
                        v-if="product.organisation"
                        :class="
                            cn(
                                'text-[10px] font-semibold tracking-[0.16em] uppercase',
                                reversed
                                    ? 'text-accent-500'
                                    : 'text-accent-700',
                            )
                        "
                    >
                        {{ product.organisation }}
                    </span>
                </span>
            </template>
        </div>
    </div>
</template>
