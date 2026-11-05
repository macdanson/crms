<script setup lang="ts">
import { Check } from '@element-plus/icons-vue';
import { Head } from '@inertiajs/vue3';
import { ElAlert, ElCard, ElIcon } from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';

defineProps<{
    permissions: string[];
    roles: { value: string; label: string; permissions: string[] }[];
}>();
</script>

<template>
    <Head title="Roles and permissions" />

    <div class="space-y-6">
        <PageHeader
            title="Roles and permissions"
            description="What each role allows. This view is read-only: the matrix changes only through an approved release."
        />

        <ElAlert
            type="warning"
            :closable="false"
            title="Provisional matrix: the final roles and permissions must be approved by the institution."
        />

        <ElCard shadow="never" body-class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th
                            scope="col"
                            class="sticky left-0 bg-white p-2 text-left font-semibold text-slate-900"
                        >
                            Permission
                        </th>
                        <th
                            v-for="role in roles"
                            :key="role.value"
                            scope="col"
                            class="min-w-24 p-2 text-center align-bottom text-xs font-semibold text-slate-700"
                        >
                            {{ role.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="permission in permissions"
                        :key="permission"
                        class="border-t border-slate-100"
                    >
                        <th
                            scope="row"
                            class="crms-code sticky left-0 bg-white p-2 text-left font-normal whitespace-nowrap text-slate-700"
                        >
                            {{ permission }}
                        </th>
                        <td
                            v-for="role in roles"
                            :key="role.value"
                            class="p-2 text-center"
                        >
                            <template
                                v-if="role.permissions.includes(permission)"
                            >
                                <ElIcon class="text-emerald-600"
                                    ><Check
                                /></ElIcon>
                                <span class="sr-only">Allowed</span>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </ElCard>
    </div>
</template>
