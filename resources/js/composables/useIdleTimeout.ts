import { router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { dashboard, logout } from '@/routes';
import { heartbeat } from '@/routes/session';

/**
 * Browser half of the idle-timeout policy (developer guide §16).
 *
 * The server is the authority: EnforceSessionTimeouts signs the officer out
 * once no request has arrived for the idle window. This composable keeps the
 * server informed while the officer is actively working on a page that makes
 * no requests, warns before an idle sign-out, and keeps open tabs in sync.
 */
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

    async function sendHeartbeat(): Promise<void> {
        try {
            await http.post(heartbeat.url());
            recordServerContact();
        } catch {
            // The session has already ended on the server.
            expire();
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
        // Any authenticated request now meets the server-side timeout, which
        // records the event and returns the officer to the sign-in page.
        router.visit(dashboard.url(), { replace: true });
    }

    function tick(): void {
        if (idleTimeout.value <= 0 || expiring) {
            return;
        }

        const now = Date.now();
        const sinceContact = (now - lastServerContact) / 1000;
        const remaining = Math.ceil(idleTimeout.value - sinceContact);

        // Officer is active but quiet: refresh the server well before expiry.
        if (
            lastUserActivity > lastServerContact &&
            sinceContact >= idleTimeout.value / 3 &&
            !showWarning.value
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
