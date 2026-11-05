<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ElButton, ElCard, ElEmpty } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import TablePagination from '@/components/TablePagination.vue';
import { formatRelative } from '@/lib/utils';
import { open as openRoute, readAll } from '@/routes/notifications';
import type { Paginated } from '@/types/pagination';

defineProps<{
    notifications: Paginated<{
        id: string;
        title: string;
        read_at: string | null;
        created_at: string | null;
    }>;
}>();

function open(id: string): void {
    router.visit(openRoute.url(id));
}

function markAllRead(): void {
    router.post(readAll.url(), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <div class="space-y-6">
        <PageHeader title="Notifications">
            <ElButton @click="markAllRead">Mark all as read</ElButton>
        </PageHeader>

        <ElCard shadow="never">
            <ElEmpty
                v-if="notifications.data.length === 0"
                description="Nothing here yet"
            />
            <ul v-else class="divide-y divide-slate-100">
                <li
                    v-for="notification in notifications.data"
                    :key="notification.id"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 py-3 text-left hover:bg-slate-50"
                        @click="open(notification.id)"
                    >
                        <span
                            :class="
                                notification.read_at
                                    ? 'text-slate-600'
                                    : 'font-semibold text-slate-900'
                            "
                        >
                            {{ notification.title }}
                        </span>
                        <span class="shrink-0 text-xs text-slate-500">
                            {{ formatRelative(notification.created_at) }}
                        </span>
                    </button>
                </li>
            </ul>
            <TablePagination :paginator="notifications" />
        </ElCard>
    </div>
</template>
