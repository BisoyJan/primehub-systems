import { usePage } from '@inertiajs/react';
import type { SharedData, UserRole } from '@/types';

/**
 * Hook to access user permissions
 * @returns Object containing permission checking functions
 */
export function usePermission() {
    const { auth } = usePage<SharedData>().props;
    const user = auth?.user;
    const permissions = user?.permissions || [];

    /**
     * Check if user has a specific permission
     */
    const can = (permission: string): boolean => {
        return permissions.includes(permission);
    };

    /**
     * Check if user has any of the specified permissions
     */
    const canAny = (permissionsToCheck: string[]): boolean => {
        return permissionsToCheck.some(permission => permissions.includes(permission));
    };

    /**
     * Check if user has all of the specified permissions
     */
    const canAll = (permissionsToCheck: string[]): boolean => {
        return permissionsToCheck.every(permission => permissions.includes(permission));
    };

    /**
     * Check if user does NOT have a specific permission
     */
    const cannot = (permission: string): boolean => {
        return !can(permission);
    };

    return {
        can,
        canAny,
        canAll,
        cannot,
        permissions,
    };
}

/**
 * Hook to access user role information
 * @returns Object containing role checking functions
 */
export function useRole() {
    const { auth } = usePage<SharedData>().props;
    const user = auth?.user;
    const role = user?.role;

    /**
     * Check if user has a specific role
     */
    const hasRole = (roleToCheck: UserRole | UserRole[]): boolean => {
        if (!role) return false;

        if (Array.isArray(roleToCheck)) {
            return roleToCheck.includes(role);
        }

        return role === roleToCheck;
    };

    /**
     * Check if user is Super Admin
     */
    const isSuperAdmin = (): boolean => {
        return role === 'Super Admin';
    };

    /**
     * Check if user is Admin or Super Admin
     */
    const isAdmin = (): boolean => {
        return role === 'Admin' || role === 'Super Admin';
    };

    /**
     * Check if user has any admin-level role
     */
    const isAdminLevel = (): boolean => {
        return hasRole(['Super Admin', 'Admin', 'Team Lead']);
    };

    return {
        role,
        hasRole,
        isSuperAdmin,
        isAdmin,
        isAdminLevel,
    };
}

/**
 * Combined hook for both permissions and roles
 * @returns Object containing both permission and role checking functions
 */
export function useAuthorization() {
    const permission = usePermission();
    const roleInfo = useRole();

    return {
        ...permission,
        ...roleInfo,
    };
}
