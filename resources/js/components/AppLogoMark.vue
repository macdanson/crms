<script setup lang="ts">
import { useId } from 'vue';

/**
 * The CRMS "Crane Shield" mark.
 *
 * `full` is the primary mark (inner keyline and laurel). `compact` drops the
 * fine detail for sizes below 32px, where it would blur.
 */
withDefaults(
    defineProps<{
        variant?: 'full' | 'compact';
        title?: string;
    }>(),
    {
        variant: 'full',
        title: 'CRMS',
    },
);

const clipId = `crms-mark-${useId()}`;

const shield =
    'M32 2.5 57 10.5V30c0 15.6-10.4 26.6-25 31.5C17.4 56.6 7 45.6 7 30V10.5Z';
const inner =
    'M32 6.2 53.4 13V30c0 13.4-8.8 23-21.4 27.6C19.4 53 10.6 43.4 10.6 30V13Z';
const crown =
    'M30.4 18.6L22.8 15.53M30.4 18.6L22.99 12.81M30.4 18.6L24.4 10.35M30.4 18.6L26.84 8.83M30.4 18.6L29.72 8.82M30.4 18.6L32.19 10.19';
const crownTips: [number, number][] = [
    [22.8, 15.53],
    [22.99, 12.81],
    [24.4, 10.35],
    [26.84, 8.83],
    [29.72, 8.82],
    [32.19, 10.19],
];
const body =
    'M28.2 25.2C29.4 29.6 28 32.4 25.8 35.6 22.6 40.4 21.6 46 24.4 52.2L26.2 60H44C42.6 55.4 39.4 52.2 35.6 49.6 32 47 30.8 43.6 31.8 39.8 32.8 36 35.4 31.6 34.6 25.6Z';
const stems =
    'M16.6 29.5C15.6 37 18.4 43.6 23.4 48.2M47.4 29.5C48.4 37 45.6 43.6 40.6 48.2';
const leaves: [number, number, number][] = [
    [15.2, 31.5, -15],
    [15.6, 36.6, -32],
    [17.4, 41.4, -48],
    [20.4, 45.6, -62],
    [48.8, 31.5, 15],
    [48.4, 36.6, 32],
    [46.6, 41.4, 48],
    [43.6, 45.6, 62],
];
</script>

<template>
    <svg
        viewBox="0 0 64 64"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        :aria-label="title"
    >
        <defs>
            <clipPath :id="clipId">
                <path :d="inner" />
            </clipPath>
        </defs>
        <path :d="shield" fill="#1D3C8F" />
        <template v-if="variant === 'full'">
            <path :d="inner" fill="none" stroke="#D4A017" stroke-width="1.1" />
            <path
                :d="stems"
                fill="none"
                stroke="#D4A017"
                stroke-width=".9"
                stroke-linecap="round"
            />
            <ellipse
                v-for="([cx, cy, angle], index) in leaves"
                :key="index"
                :cx="cx"
                :cy="cy"
                rx="1.05"
                ry="2.4"
                fill="#D4A017"
                :transform="`rotate(${angle} ${cx} ${cy})`"
            />
        </template>
        <path
            :d="crown"
            stroke="#D4A017"
            :stroke-width="variant === 'full' ? 1.15 : 1.8"
            stroke-linecap="round"
        />
        <circle
            v-for="([cx, cy], index) in crownTips"
            :key="`tip-${index}`"
            :cx="cx"
            :cy="cy"
            :r="variant === 'full' ? 1.25 : 1.7"
            fill="#D4A017"
        />
        <path :d="body" fill="#FFFFFF" :clip-path="`url(#${clipId})`" />
        <circle cx="31.3" cy="22.4" r="4.3" fill="#FFFFFF" />
        <path d="M34.4 20.5 43.6 23.4 34.6 25.2Z" fill="#FFFFFF" />
        <circle
            cx="32.1"
            cy="21.5"
            :r="variant === 'full' ? 1 : 1.3"
            fill="#0B1B45"
        />
        <ellipse
            cx="30.4"
            cy="27.6"
            :rx="variant === 'full' ? 1.25 : 1.6"
            :ry="variant === 'full' ? 1.6 : 1.9"
            fill="#C8102E"
        />
    </svg>
</template>
