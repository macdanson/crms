import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

const dateTimeFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * Formats an ISO-8601 timestamp for display, e.g. "2 Nov 2026, 14:05".
 */
export function formatDateTime(iso: string | null | undefined): string {
    return iso ? dateTimeFormatter.format(new Date(iso)) : '—';
}

const relativeFormatter = new Intl.RelativeTimeFormat('en-GB', {
    numeric: 'auto',
});

/**
 * Formats an ISO-8601 timestamp relative to now, e.g. "5 minutes ago".
 */
export function formatRelative(iso: string | null | undefined): string {
    if (!iso) {
        return 'Never';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relativeFormatter.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}
