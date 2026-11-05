<script setup lang="ts">
import { ElCard } from 'element-plus';

defineProps<{
    title: string;
    rows: { label: string; count: number | null }[];
    /** Shown in place of a count hidden to protect individuals. */
    suppressedLabel?: string;
}>();
</script>

<template>
    <ElCard shadow="never">
        <template #header>
            <h2 class="font-bold text-brand-900">{{ title }}</h2>
        </template>
        <p v-if="rows.length === 0" class="text-sm text-slate-500">No data.</p>
        <table v-else class="w-full text-sm">
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.label"
                    class="border-b border-slate-100 last:border-0"
                >
                    <th
                        scope="row"
                        class="py-2 pr-4 text-left font-normal text-slate-700"
                    >
                        {{ row.label }}
                    </th>
                    <td
                        class="py-2 text-right font-semibold text-slate-900 tabular-nums"
                    >
                        {{ row.count ?? suppressedLabel ?? 'Hidden' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </ElCard>
</template>
