import { router, usePage } from '@inertiajs/vue3';
import { ElMessage } from 'element-plus';
import { onMounted, watch } from 'vue';

/**
 * Back and Forward restore a page's old props, including its messages, which
 * were already shown. Kept outside the composable because Back can also
 * mount a new layout.
 */
let restoringFromHistory = false;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => {
        restoringFromHistory = true;
    });

    router.on('before', () => {
        restoringFromHistory = false;
    });
}

/**
 * Shows the server's one-time status and error messages as toasts.
 */
export function useFlashMessages(): void {
    const page = usePage();

    const show = (): void => {
        if (restoringFromHistory) {
            restoringFromHistory = false;

            return;
        }

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
