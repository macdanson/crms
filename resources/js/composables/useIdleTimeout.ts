import { router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { logout } from '@/routes';
import { heartbeat } from '@/routes/session';

/**
 * Browser half of the session timeout policy (developer guide §16).
 *
 * The server is the authority: EnforceSessionTimeouts signs the user out
 * once no request has arrived for the idle window, or once the session reaches
 * its maximum length. This composable keeps the server informed while the
 * user is actively working on a page that makes no requests, warns before
 * either sign-out, and keeps open tabs in sync.
 */
/**
 * How long to wait before retrying a heartbeat that failed for a temporary
 * reason (network blip, throttling, server error).
 */
const HEARTBEAT_RETRY_DELAY_MS = 30_000;

/**
 * Responses meaning the server-side session has really ended.
 */
const SESSION_ENDED_STATUSES = [401, 419];

export function useIdleTimeout() {
    const page = usePage();
    const http = useHttp({});

    const idleTimeout = computed(() => page.props.session?.idle_timeout ?? 0);
    const warningWindow = computed(() =>
        Math.min(page.props.session?.idle_warning ?? 60, idleTimeout.value),
    );

    const absoluteWarningWindow = computed(
        () => page.props.session?.absolute_warning ?? 300,
    );

    const showWarning = ref(false);
    const secondsRemaining = ref(0);
    const warningReason = ref<'idle' | 'absolute'>('idle');

    // Browser time at which the session reaches its maximum length.
    let absoluteDeadline: number | null = null;
    let absoluteWarningDismissed = false;

    watch(
        () => page.props.session?.absolute_remaining,
        (remaining) => {
            absoluteDeadline =
                typeof remaining === 'number'
                    ? Date.now() + remaining * 1000
                    : null;
        },
        { immediate: true },
    );

    let lastServerContact = Date.now();
    let lastUserActivity = Date.now();
    let ticker: ReturnType<typeof setInterval> | undefined;
    let channel: BroadcastChannel | undefined;
    let removeFinishListener: (() => void) | undefined;
    let expiring = false;
    let heartbeatInFlight = false;
    let nextHeartbeatAllowedAt = 0;

    const activityEvents = [
        'pointerdown',
        'keydown',
        'wheel',
        'touchstart',
        'mousemove',
    ] as const;

    function recordServerContact(at = Date.now(), broadcast = true): void {
        lastServerContact = Math.max(lastServerContact, at);

        if (broadcast) {
            channel?.postMessage({ type: 'contact', at: lastServerContact });
        }
    }

    function recordUserActivity(): void {
        lastUserActivity = Date.now();
    }

    function canSendHeartbeat(): boolean {
        return !heartbeatInFlight && Date.now() >= nextHeartbeatAllowedAt;
    }

    async function sendHeartbeat(): Promise<void> {
        if (!canSendHeartbeat()) {
            return;
        }

        heartbeatInFlight = true;

        try {
            await http.post(heartbeat.url());
            recordServerContact();
        } catch (error) {
            const status = (error as { response?: { status?: number } })
                ?.response?.status;

            if (
                status !== undefined &&
                SESSION_ENDED_STATUSES.includes(status)
            ) {
                expire();

                return;
            }

            // The session is still valid; a temporary failure must not cost
            // the user their unsaved work. Try again shortly.
            nextHeartbeatAllowedAt = Date.now() + HEARTBEAT_RETRY_DELAY_MS;
        } finally {
            heartbeatInFlight = false;
        }
    }

    function staySignedIn(): void {
        showWarning.value = false;
        void sendHeartbeat();
    }

    /** Hide the maximum-length warning; it does not come back. */
    function continueWorking(): void {
        absoluteWarningDismissed = true;
        showWarning.value = false;
    }

    function warn(reason: 'idle' | 'absolute', seconds: number): void {
        warningReason.value = reason;
        secondsRemaining.value = seconds;
        showWarning.value = true;
    }

    function signOutNow(): void {
        expiring = true;
        router.post(logout.url());
    }

    function expire(): void {
        if (expiring) {
            return;
        }

        expiring = true;
        showWarning.value = false;
        // Reload this page. If the session has ended, the server records it
        // and redirects to the sign-in page. If it is still alive (this tab's
        // clock was behind), the user stays where they were and the timer
        // starts again.
        router.reload({
            onFinish: () => {
                expiring = false;
            },
        });
    }

    function tick(): void {
        if (expiring) {
            return;
        }

        const now = Date.now();
        const absoluteLeft =
            absoluteDeadline === null
                ? null
                : Math.ceil((absoluteDeadline - now) / 1000);

        if (absoluteLeft !== null && absoluteLeft <= 0) {
            expire();

            return;
        }

        let idleLeft: number | null = null;

        if (idleTimeout.value > 0) {
            const sinceContact = (now - lastServerContact) / 1000;
            const idleWarningShown =
                showWarning.value && warningReason.value === 'idle';

            idleLeft = Math.ceil(idleTimeout.value - sinceContact);

            // User is active but quiet: refresh the server well before expiry.
            if (
                lastUserActivity > lastServerContact &&
                sinceContact >= idleTimeout.value / 3 &&
                !idleWarningShown &&
                canSendHeartbeat()
            ) {
                void sendHeartbeat();

                return;
            }

            if (idleLeft <= 0) {
                expire();

                return;
            }
        }

        if (
            absoluteLeft !== null &&
            absoluteLeft <= absoluteWarningWindow.value &&
            !absoluteWarningDismissed
        ) {
            warn('absolute', absoluteLeft);

            return;
        }

        if (idleLeft !== null && idleLeft <= warningWindow.value) {
            warn('idle', idleLeft);

            return;
        }

        showWarning.value = false;
    }

    onMounted(() => {
        activityEvents.forEach((event) =>
            window.addEventListener(event, recordUserActivity, {
                passive: true,
            }),
        );

        removeFinishListener = router.on('finish', () => recordServerContact());

        if ('BroadcastChannel' in window) {
            channel = new BroadcastChannel('crms-session');
            channel.onmessage = (
                event: MessageEvent<{ type: string; at: number }>,
            ) => {
                if (event.data?.type === 'contact') {
                    recordServerContact(event.data.at, false);

                    if (showWarning.value && warningReason.value === 'idle') {
                        showWarning.value = false;
                    }
                }
            };
        }

        ticker = setInterval(tick, 1000);
    });

    onBeforeUnmount(() => {
        activityEvents.forEach((event) =>
            window.removeEventListener(event, recordUserActivity),
        );
        removeFinishListener?.();
        channel?.close();
        clearInterval(ticker);
    });

    return {
        showWarning,
        secondsRemaining,
        warningReason,
        staySignedIn,
        continueWorking,
        signOutNow,
    };
}
