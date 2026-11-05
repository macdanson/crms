<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ElButton,
    ElCard,
    ElDatePicker,
    ElForm,
    ElFormItem,
    ElInput,
    ElOption,
    ElSelect,
} from 'element-plus';
import PageHeader from '@/components/PageHeader.vue';
import { store } from '@/routes/cases';

defineProps<{ categories: { id: number; code: string; name: string }[] }>();

const form = useForm({
    offence_category_id: null as number | null,
    incident_date: null as string | null,
    incident_location: '',
    narrative: '',
});

function submit(): void {
    form.post(store.url());
}
</script>

<template>
    <Head title="Open case" />

    <div class="space-y-6">
        <PageHeader
            title="Open case"
            description="The case opens at your station and gets its reference automatically. Link people to it afterwards."
        />

        <ElCard shadow="never" class="max-w-3xl">
            <ElForm label-position="top" @submit.prevent="submit">
                <ElFormItem
                    label="Offence category"
                    :error="form.errors.offence_category_id"
                    required
                >
                    <ElSelect
                        v-model="form.offence_category_id"
                        filterable
                        class="w-full"
                    >
                        <ElOption
                            v-for="category in categories"
                            :key="category.id"
                            :label="category.name"
                            :value="category.id"
                        />
                    </ElSelect>
                </ElFormItem>
                <div class="grid gap-4 sm:grid-cols-2">
                    <ElFormItem
                        label="Incident date"
                        :error="form.errors.incident_date"
                        required
                    >
                        <ElDatePicker
                            v-model="form.incident_date"
                            type="date"
                            value-format="YYYY-MM-DD"
                            class="w-full!"
                        />
                    </ElFormItem>
                    <ElFormItem
                        label="Incident location"
                        :error="form.errors.incident_location"
                        required
                    >
                        <ElInput
                            v-model="form.incident_location"
                            maxlength="255"
                        />
                    </ElFormItem>
                </div>
                <ElFormItem
                    label="Narrative"
                    :error="form.errors.narrative"
                    required
                >
                    <ElInput
                        v-model="form.narrative"
                        type="textarea"
                        :rows="8"
                        maxlength="20000"
                        show-word-limit
                    />
                </ElFormItem>
                <ElButton
                    type="primary"
                    native-type="submit"
                    :loading="form.processing"
                    >Open case</ElButton
                >
            </ElForm>
        </ElCard>
    </div>
</template>
