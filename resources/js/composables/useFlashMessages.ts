import { usePage } from '@inertiajs/vue3';
import { ElMessage } from 'element-plus';
import { onMounted, watch } from 'vue';

/**
 * Shows the server's one-time status and error messages as toasts.
 */
export function useFlashMessages(): void {
    const page = usePage();

    const show = (): void => {
        const { status, error } = page.props.flash ?? {};

        if (status) {
            ElMessage({ type: 'success', message: status, showClose: true });
        }

        if (error) {
            ElMessage({
                type: 'error',
                message: error,
                showClose: true,
                duration: 6000,
            });
        }
    };

    onMounted(show);
    watch(() => page.props.flash, show);
}
