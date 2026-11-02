<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header class="mb-7">
        <h1 class="text-2xl font-bold tracking-tight text-crest-900">
            {{ title }}
        </h1>
        <p v-if="description" class="mt-2 text-sm leading-6 text-slate-600">
            {{ description }}
        </p>
    </header>
</template>
