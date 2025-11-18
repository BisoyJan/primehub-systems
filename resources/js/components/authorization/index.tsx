import { type ReactNode } from 'react';
import { usePermission, useRole } from '@/hooks/useAuthorization';
import type { UserRole } from '@/types';

interface CanProps {
    permission: string;
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Component that renders children only if user has the specified permission
 *
 * @example
 * <Can permission="accounts.create">
 *   <Button>Create Account</Button>
 * </Can>
 */
export function Can({ permission, children, fallback = null }: CanProps) {
    const { can } = usePermission();

    if (!can(permission)) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}

interface CanAnyProps {
    permissions: string[];
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Component that renders children only if user has any of the specified permissions
 *
 * @example
 * <CanAny permissions={["accounts.create", "accounts.edit"]}>
 *   <Button>Manage Accounts</Button>
 * </CanAny>
 */
export function CanAny({ permissions, children, fallback = null }: CanAnyProps) {
    const { canAny } = usePermission();

    if (!canAny(permissions)) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}

interface CanAllProps {
    permissions: string[];
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Component that renders children only if user has all of the specified permissions
 *
 * @example
 * <CanAll permissions={["accounts.view", "accounts.edit"]}>
 *   <EditAccountForm />
 * </CanAll>
 */
export function CanAll({ permissions, children, fallback = null }: CanAllProps) {
    const { canAll } = usePermission();

    if (!canAll(permissions)) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}

interface CannotProps {
    permission: string;
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Component that renders children only if user does NOT have the specified permission
 *
 * @example
 * <Cannot permission="accounts.delete">
 *   <p>You don't have permission to delete accounts</p>
 * </Cannot>
 */
export function Cannot({ permission, children, fallback = null }: CannotProps) {
    const { cannot } = usePermission();

    if (!cannot(permission)) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}

interface HasRoleProps {
    role: UserRole | UserRole[];
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Component that renders children only if user has the specified role(s)
 *
 * @example
 * <HasRole role="Super Admin">
 *   <AdminPanel />
 * </HasRole>
 *
 * @example
 * <HasRole role={["Admin", "Super Admin"]}>
 *   <ManagementTools />
 * </HasRole>
 */
export function HasRole({ role, children, fallback = null }: HasRoleProps) {
    const { hasRole } = useRole();

    if (!hasRole(role)) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}

interface IsAdminProps {
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Component that renders children only if user is Admin or Super Admin
 *
 * @example
 * <IsAdmin>
 *   <AdminDashboard />
 * </IsAdmin>
 */
export function IsAdmin({ children, fallback = null }: IsAdminProps) {
    const { isAdmin } = useRole();

    if (!isAdmin()) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}

interface IsSuperAdminProps {
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Component that renders children only if user is Super Admin
 *
 * @example
 * <IsSuperAdmin>
 *   <SystemSettings />
 * </IsSuperAdmin>
 */
export function IsSuperAdmin({ children, fallback = null }: IsSuperAdminProps) {
    const { isSuperAdmin } = useRole();

    if (!isSuperAdmin()) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}
