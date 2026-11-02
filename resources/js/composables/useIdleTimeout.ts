import { router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { logout } from '@/routes';
import { heartbeat } from '@/routes/session';

/**
 * Browser half of the idle-timeout policy (developer guide §16).
 *
 * The server is the authority: EnforceSessionTimeouts signs the user out
 * once no request has arrived for the idle window. This composable keeps the
 * server informed while the user is actively working on a page that makes
 * no requests, warns before an idle sign-out, and keeps open tabs in sync.
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

    const showWarning = ref(false);
    const secondsRemaining = ref(0);

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
        if (idleTimeout.value <= 0 || expiring) {
            return;
        }

        const now = Date.now();
        const sinceContact = (now - lastServerContact) / 1000;
        const remaining = Math.ceil(idleTimeout.value - sinceContact);

        // User is active but quiet: refresh the server well before expiry.
        if (
            lastUserActivity > lastServerContact &&
            sinceContact >= idleTimeout.value / 3 &&
            !showWarning.value &&
            canSendHeartbeat()
        ) {
            void sendHeartbeat();

            return;
        }

        if (remaining <= 0) {
            expire();

            return;
        }

        secondsRemaining.value = remaining;
        showWarning.value = remaining <= warningWindow.value;
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

                    if (showWarning.value) {
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

    return { showWarning, secondsRemaining, staySignedIn, signOutNow };
}
