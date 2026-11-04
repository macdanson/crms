<script setup lang="ts">
import { computed } from 'vue';

/**
 * The CRMS "Trace" mark: an open ring (the C of CRMS, and an analyst's lens)
 * with linked data points whose trace breaks out into an amber finding.
 *
 * `full` is the primary mark. `compact` uses heavier strokes and one fewer
 * point for sizes below 32px, where fine detail would blur.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'full' | 'compact';
        title?: string;
    }>(),
    {
        variant: 'full',
        title: 'CRMS',
    },
);

type Point = [number, number];

const geometry: Record<
    'full' | 'compact',
    {
        ring: string;
        ringWidth: number;
        points: Point[];
        pointRadius: number;
        lineWidth: number;
        finding: Point;
        findingRadius: number;
    }
> = {
    full: {
        ring: 'M42.02 44.02A17 17 0 1 1 42.02 19.98',
        ringWidth: 5,
        points: [
            [22, 37],
            [27, 27],
            [34, 34],
        ],
        pointRadius: 3,
        lineWidth: 2.5,
        finding: [49, 27],
        findingRadius: 4.5,
    },
    compact: {
        ring: 'M41.31 43.31A16 16 0 1 1 41.31 20.69',
        ringWidth: 7,
        points: [
            [23, 37],
            [31, 29],
        ],
        pointRadius: 3.6,
        lineWidth: 3.6,
        finding: [50, 27],
        findingRadius: 6.5,
    },
};

const shape = computed(() => geometry[props.variant]);
const trace = computed(
    () =>
        'M' +
        [...shape.value.points, shape.value.finding]
            .map(([x, y]) => `${x} ${y}`)
            .join('L'),
);
</script>

<template>
    <svg
        viewBox="0 0 64 64"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        :aria-label="title"
    >
        <rect x="2" y="2" width="60" height="60" rx="14" fill="#0E5E6F" />
        <path
            :d="shape.ring"
            fill="none"
            stroke="#FFFFFF"
            :stroke-width="shape.ringWidth"
            stroke-linecap="round"
        />
        <path
            :d="trace"
            fill="none"
            stroke="#FFFFFF"
            :stroke-width="shape.lineWidth"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <circle
            v-for="([cx, cy], index) in shape.points"
            :key="index"
            :cx="cx"
            :cy="cy"
            :r="shape.pointRadius"
            fill="#FFFFFF"
        />
        <circle
            :cx="shape.finding[0]"
            :cy="shape.finding[1]"
            :r="shape.findingRadius"
            fill="#F2A900"
        />
    </svg>
</template>
