<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ElAlert,
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
import { store } from '@/routes/persons';

type Option = { value: string; label: string };

defineProps<{ options: { sexes: Option[]; identity_types: Option[] } }>();

const form = useForm({
    given_names: '',
    family_name: '',
    other_names: '',
    sex: null as string | null,
    date_of_birth: null as string | null,
    nationality: '',
    occupation: '',
    identity_type: null as string | null,
    identity_number: '',
});

function submit(): void {
    form.post(store.url(), {
        onError: () => form.reset('identity_number'),
    });
}
</script>

<template>
    <Head title="Register person" />

    <div class="space-y-6">
        <PageHeader
            title="Register person"
            description="Collect only what is needed. The person is registered at your station."
        />

        <ElCard shadow="never" class="max-w-3xl">
            <ElForm label-position="top" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <ElFormItem
                        label="Given names"
                        :error="form.errors.given_names"
                        required
                    >
                        <ElInput v-model="form.given_names" />
                    </ElFormItem>
                    <ElFormItem
                        label="Family name"
                        :error="form.errors.family_name"
                        required
                    >
                        <ElInput v-model="form.family_name" />
                    </ElFormItem>
                    <ElFormItem
                        label="Other names"
                        :error="form.errors.other_names"
                    >
                        <ElInput v-model="form.other_names" />
                    </ElFormItem>
                    <ElFormItem label="Sex" :error="form.errors.sex">
                        <ElSelect v-model="form.sex" clearable class="w-full">
                            <ElOption
                                v-for="option in options.sexes"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </ElSelect>
                    </ElFormItem>
                    <ElFormItem
                        label="Date of birth"
                        :error="form.errors.date_of_birth"
                    >
                        <ElDatePicker
                            v-model="form.date_of_birth"
                            type="date"
                            value-format="YYYY-MM-DD"
                            class="w-full!"
                        />
                    </ElFormItem>
                    <ElFormItem
                        label="Nationality"
                        :error="form.errors.nationality"
                    >
                        <ElInput v-model="form.nationality" />
                    </ElFormItem>
                    <ElFormItem
                        label="Occupation"
                        :error="form.errors.occupation"
                    >
                        <ElInput v-model="form.occupation" />
                    </ElFormItem>
                </div>

                <h2 class="mt-2 mb-3 font-bold text-brand-900">
                    Identity document (optional)
                </h2>
                <ElAlert
                    type="info"
                    :closable="false"
                    class="mb-4"
                    title="A number is stored encrypted and starts as unverified: having it on file does not prove someone's identity."
                />
                <div class="grid gap-4 sm:grid-cols-2">
                    <ElFormItem
                        label="Document type"
                        :error="form.errors.identity_type"
                    >
                        <ElSelect
                            v-model="form.identity_type"
                            clearable
                            class="w-full"
                        >
                            <ElOption
                                v-for="option in options.identity_types"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </ElSelect>
                    </ElFormItem>
                    <ElFormItem
                        label="Number"
                        :error="form.errors.identity_number"
                    >
                        <ElInput
                            v-model="form.identity_number"
                            autocomplete="off"
                        />
                    </ElFormItem>
                </div>

                <ElButton
                    type="primary"
                    native-type="submit"
                    :loading="form.processing"
                >
                    Register
                </ElButton>
            </ElForm>
        </ElCard>
    </div>
</template>
