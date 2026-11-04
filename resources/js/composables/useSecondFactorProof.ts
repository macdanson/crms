import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * A form that proves the second factor with an authenticator code or a
 * recovery code. Only the field in use is sent, and a used or refused code is
 * never kept: sending a spent code again counts as a wrong guess.
 */
export function useSecondFactorProof() {
    const usingRecoveryCode = ref(false);
    const form = useForm({ code: '', recovery_code: '' });

    function emptyFields(): void {
        form.code = '';
        form.recovery_code = '';
    }

    /**
     * Not form.reset(): after a successful submit the form's defaults are the
     * values just sent, so reset() would put a spent recovery code back.
     */
    function clear(): void {
        emptyFields();
        form.defaults();
        form.clearErrors();
    }

    /** Begin again, asking for an authenticator code. */
    function start(): void {
        usingRecoveryCode.value = false;
        clear();
    }

    function toggleMethod(): void {
        usingRecoveryCode.value = !usingRecoveryCode.value;
        clear();
    }

    function submit(url: string, onSuccess?: () => void): void {
        form.transform((data) =>
            usingRecoveryCode.value
                ? { recovery_code: data.recovery_code.trim() }
                : { code: data.code.replace(/\s/g, '') },
        ).post(url, {
            preserveScroll: true,
            onSuccess: () => onSuccess?.(),
            onError: emptyFields,
        });
    }

    return { form, usingRecoveryCode, start, toggleMethod, submit };
}
