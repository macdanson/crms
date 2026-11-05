import { usePage } from '@inertiajs/vue3';

/**
 * Whether to show something to this user. This is a convenience only: hiding
 * a control is not authorisation, and the server checks every action.
 */
export function usePermissions(): { can: (permission: string) => boolean } {
    const page = usePage();

    return {
        can: (permission) =>
            page.props.auth.user?.permissions.includes(permission) ?? false,
    };
}
