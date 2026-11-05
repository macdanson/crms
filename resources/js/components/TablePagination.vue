<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ElPagination } from 'element-plus';
import type { Paginated } from '@/types/pagination';

defineProps<{ paginator: Paginated<unknown> }>();

const page = usePage();

function goToPage(pageNumber: number): void {
    const url = new URL(page.url, window.location.origin);
    url.searchParams.set('page', String(pageNumber));
    router.visit(`${url.pathname}${url.search}`, { preserveScroll: true });
}
</script>

<template>
    <div v-if="paginator.last_page > 1" class="mt-4 flex justify-end">
        <ElPagination
            layout="prev, pager, next, total"
            background
            :current-page="paginator.current_page"
            :page-size="paginator.per_page"
            :total="paginator.total"
            @current-change="goToPage"
        />
    </div>
</template>
