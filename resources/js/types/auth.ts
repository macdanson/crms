/**
 * The minimal user profile shared with every page. Secrets and
 * operational data are never included.
 */
export type User = {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    staff_number: string | null;
    initials: string;
    two_factor_enabled: boolean;
    /** Used only to show the right menu items; the server authorises every action. */
    permissions: string[];
    unread_notifications: number;
};

export type Auth = {
    user: User | null;
};

export type SessionPolicy = {
    /** Seconds of inactivity before the server signs the user out. */
    idle_timeout: number;
    /** Seconds of warning shown before an idle sign-out. */
    idle_warning: number;
    /** Seconds until the session's maximum length is reached (null = none). */
    absolute_remaining: number | null;
    /** Seconds of warning shown before the maximum length is reached. */
    absolute_warning: number;
};

export type Flash = {
    status: string | null;
    error: string | null;
};

export type Product = {
    short_name: string;
    full_name: string;
    /** Optional: the operating organisation, when configured. */
    organisation: string | null;
    tagline: string;
    support_contact: string;
};
