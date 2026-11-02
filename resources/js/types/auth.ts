/**
 * The minimal officer profile shared with every page. Secrets and
 * operational data are never included.
 */
export type User = {
    id: number;
    name: string;
    email: string;
    rank: string | null;
    service_number: string | null;
    initials: string;
    two_factor_enabled: boolean;
};

export type Auth = {
    user: User | null;
};

export type SessionPolicy = {
    /** Seconds of inactivity before the server signs the officer out. */
    idle_timeout: number;
    /** Seconds of warning shown before an idle sign-out. */
    idle_warning: number;
};

export type Flash = {
    status: string | null;
    error: string | null;
};

export type Product = {
    short_name: string;
    full_name: string;
    organisation: string;
    motto: string;
    support_contact: string;
};
