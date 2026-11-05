<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ElAlert,
    ElButton,
    ElCard,
    ElDialog,
    ElForm,
    ElFormItem,
    ElInput,
    ElSwitch,
    ElTable,
    ElTableColumn,
    ElTag,
} from 'element-plus';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { store, update } from '@/routes/admin/catalogue';

type Category = { id: number; code: string; name: string; is_active: boolean };

defineProps<{ categories: Category[] }>();

const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ code: '', name: '', is_active: true });

function add(): void {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(category: Category): void {
    editingId.value = category.id;
    form.clearErrors();
    form.code = category.code;
    form.name = category.name;
    form.is_active = category.is_active;
    open.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editingId.value === null) {
        form.post(store.url(), options);
    } else {
        form.put(update.url(editingId.value), options);
    }
}
</script>

<template>
    <Head title="Offence catalogue" />

    <div class="space-y-6">
        <PageHeader
            title="Offence catalogue"
            description="The controlled list used to classify cases. A category in use can be retired but not removed, so older cases keep their classification."
        >
            <ElButton type="primary" @click="add">Add category</ElButton>
        </PageHeader>

        <ElAlert
            type="warning"
            :closable="false"
            title="The content of this catalogue must come from the institution."
        />

        <ElCard shadow="never">
            <ElTable :data="categories" empty-text="No categories yet">
                <ElTableColumn prop="code" label="Code" width="160" />
                <ElTableColumn prop="name" label="Name" min-width="280" />
                <ElTableColumn label="Status" width="120">
                    <template #default="{ row }">
                        <ElTag
                            size="small"
                            :type="row.is_active ? 'success' : 'info'"
                        >
                            {{ row.is_active ? 'Active' : 'Retired' }}
                        </ElTag>
                    </template>
                </ElTableColumn>
                <ElTableColumn width="90" align="right">
                    <template #default="{ row }">
                        <ElButton
                            link
                            type="primary"
                            @click="edit(row as Category)"
                            >Edit</ElButton
                        >
                    </template>
                </ElTableColumn>
            </ElTable>
        </ElCard>

        <ElDialog
            v-model="open"
            :title="editingId === null ? 'Add category' : 'Edit category'"
            width="min(480px, 92vw)"
        >
            <ElForm label-position="top" @submit.prevent="submit">
                <ElFormItem
                    v-if="editingId === null"
                    label="Code"
                    :error="form.errors.code"
                    required
                >
                    <ElInput v-model="form.code" maxlength="20" />
                </ElFormItem>
                <ElFormItem label="Name" :error="form.errors.name" required>
                    <ElInput v-model="form.name" maxlength="160" />
                </ElFormItem>
                <ElFormItem v-if="editingId !== null" label="Active">
                    <ElSwitch v-model="form.is_active" />
                </ElFormItem>
            </ElForm>
            <template #footer>
                <ElButton @click="open = false">Cancel</ElButton>
                <ElButton
                    type="primary"
                    :loading="form.processing"
                    @click="submit"
                    >Save</ElButton
                >
            </template>
        </ElDialog>
    </div>
</template>
