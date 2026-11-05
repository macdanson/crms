<script setup lang="ts">
import {
    ElAlert,
    ElButton,
    ElDialog,
    ElForm,
    ElFormItem,
    ElInput,
} from 'element-plus';
import { ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        label?: string;
        confirmText?: string;
        warning?: string;
        error?: string;
        processing?: boolean;
        danger?: boolean;
    }>(),
    {
        label: 'Reason (kept on record)',
        confirmText: 'Confirm',
        warning: undefined,
        error: undefined,
        processing: false,
        danger: false,
    },
);

const open = defineModel<boolean>({ required: true });
const emit = defineEmits<{ confirm: [reason: string] }>();

const reason = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        reason.value = '';
    }
});
</script>

<template>
    <ElDialog v-model="open" :title="props.title" width="min(480px, 92vw)">
        <ElAlert
            v-if="warning"
            :title="warning"
            type="warning"
            :closable="false"
            class="mb-4"
        />
        <ElForm label-position="top" @submit.prevent="emit('confirm', reason)">
            <ElFormItem :label="label" :error="error" required>
                <ElInput
                    v-model="reason"
                    v-focus
                    type="textarea"
                    :rows="3"
                    maxlength="500"
                    show-word-limit
                />
            </ElFormItem>
        </ElForm>
        <template #footer>
            <ElButton @click="open = false">Cancel</ElButton>
            <ElButton
                :type="danger ? 'danger' : 'primary'"
                :loading="processing"
                @click="emit('confirm', reason)"
            >
                {{ confirmText }}
            </ElButton>
        </template>
    </ElDialog>
</template>
