<script setup lang="ts">
import { CopyDocument, Download } from '@element-plus/icons-vue';
import { ElButton, ElMessage } from 'element-plus';

/**
 * Displays single-use recovery codes with copy and download helpers.
 * Codes are never written to browser storage.
 */
const props = defineProps<{
    codes: string[];
}>();

async function copyCodes(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.codes.join('\n'));
        ElMessage.success('Recovery codes copied.');
    } catch {
        ElMessage.error(
            'Copy failed. Select the codes and copy them manually.',
        );
    }
}

function downloadCodes(): void {
    const contents = [
        'CRMS recovery codes',
        'Each code can be used once if you cannot use your authenticator app.',
        'Store these somewhere safe and private.',
        '',
        ...props.codes,
    ].join('\n');

    const url = URL.createObjectURL(
        new Blob([contents], { type: 'text/plain' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = 'crms-recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <div>
        <ol
            class="crms-code grid grid-cols-1 gap-x-4 gap-y-2 rounded-lg border border-dashed border-brand-300 bg-brand-50 p-4 text-[13px] whitespace-nowrap text-brand-900 sm:grid-cols-2"
        >
            <li v-for="code in codes" :key="code">{{ code }}</li>
        </ol>
        <div class="mt-3 flex flex-wrap gap-2">
            <ElButton :icon="CopyDocument" @click="copyCodes">Copy</ElButton>
            <ElButton :icon="Download" @click="downloadCodes">
                Download
            </ElButton>
        </div>
    </div>
</template>
