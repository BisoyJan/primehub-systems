# Role-Based Access Control (RBAC) Documentation

## Overview

The PrimeHub Systems application now includes a comprehensive role-based access control (RBAC) system that allows you to control access to features and pages based on user roles and permissions.

## Table of Contents

1. [Roles](#roles)
2. [Permissions](#permissions)
3. [Backend Implementation](#backend-implementation)
4. [Frontend Implementation](#frontend-implementation)
5. [Usage Examples](#usage-examples)
6. [Configuration](#configuration)

---

## Roles

The system supports the following user roles:

| Role | Description |
|------|-------------|
| **Super Admin** | Full system access with all permissions |
| **Admin** | Administrative access to most features |
| **Team Lead** | Supervisor-level access with limited management capabilities |
| **Agent** | Basic user access with read-only permissions for most features |
| **HR** | Human Resources access focused on attendance, schedules, and leave management |
| **IT** | IT Department access focused on hardware, PCs, and infrastructure |
| **Utility** | Basic utility access with minimal permissions |

---

## Permissions

Permissions are organized by feature/module. Here are the main permission categories:

### Dashboard
- `dashboard.view` - View Dashboard

### User Management
- `accounts.view` - View User Accounts
- `accounts.create` - Create User Accounts
- `accounts.edit` - Edit User Accounts
- `accounts.delete` - Delete User Accounts

### Hardware Specs
- `hardware.view` - View Hardware Specs
- `hardware.create` - Create Hardware Specs
- `hardware.edit` - Edit Hardware Specs
- `hardware.delete` - Delete Hardware Specs

### PC Specs
- `pcspecs.view` - View PC Specs
- `pcspecs.create` - Create PC Specs
- `pcspecs.edit` - Edit PC Specs
- `pcspecs.delete` - Delete PC Specs
- `pcspecs.qrcode` - Generate PC QR Codes
- `pcspecs.update_issue` - Update PC Issues

### Sites & Campaigns
- `sites.view`, `sites.create`, `sites.edit`, `sites.delete`
- `campaigns.view`, `campaigns.create`, `campaigns.edit`, `campaigns.delete`

### Stations
- `stations.view`, `stations.create`, `stations.edit`, `stations.delete`
- `stations.qrcode` - Generate Station QR Codes
- `stations.bulk` - Bulk Create Stations

### Stocks
- `stocks.view`, `stocks.create`, `stocks.edit`, `stocks.delete`
- `stocks.adjust` - Adjust Stock Levels

### PC Transfers
- `pc_transfers.view`, `pc_transfers.create`, `pc_transfers.remove`
- `pc_transfers.history` - View Transfer History

### PC Maintenance
- `pc_maintenance.view`, `pc_maintenance.create`, `pc_maintenance.edit`, `pc_maintenance.delete`

### Attendance Management
- `attendance.view`, `attendance.create`, `attendance.import`
- `attendance.review`, `attendance.verify`, `attendance.approve`
- `attendance.statistics`, `attendance.delete`

### Employee Schedules
- `schedules.view`, `schedules.create`, `schedules.edit`, `schedules.delete`
- `schedules.toggle` - Toggle Schedule Active Status

### Biometric Records
- `biometric.view`, `biometric.reprocess`, `biometric.anomalies`
- `biometric.export`, `biometric.retention`

### Attendance Points
- `attendance_points.view`, `attendance_points.excuse`
- `attendance_points.export`, `attendance_points.rescan`

### Leave Requests
- `leave.view`, `leave.create`, `leave.approve`, `leave.deny`
- `leave.cancel`, `leave.view_all`

### Settings
- `settings.view`, `settings.account`, `settings.password`

For a complete list of permissions and their role assignments, see `config/permissions.php`.

---

## Backend Implementation

### Permission Service

The `PermissionService` class (`app/Services/PermissionService.php`) provides the core authorization logic:

```php
use App\Services\PermissionService;

$permissionService = app(PermissionService::class);

// Check if user has a permission
$canEdit = $permissionService->userHasPermission($user, 'accounts.edit');

// Check if user has any of the permissions
$canManage = $permissionService->userHasAnyPermission($user, ['accounts.edit', 'accounts.delete']);

// Check if user has all permissions
$hasAll = $permissionService->userHasAllPermissions($user, ['accounts.view', 'accounts.edit']);

// Check if user has a role
$isAdmin = $permissionService->userHasRole($user, 'Admin');
$isAdminOrLead = $permissionService->userHasRole($user, ['Admin', 'Team Lead']);
```

### User Model Methods

The `User` model includes helper methods for permission checking:

```php
// Check permission
if ($user->hasPermission('accounts.edit')) {
    // User can edit accounts
}

// Check any permission
if ($user->hasAnyPermission(['accounts.edit', 'accounts.delete'])) {
    // User can edit or delete accounts
}

// Check all permissions
if ($user->hasAllPermissions(['accounts.view', 'accounts.edit'])) {
    // User can both view and edit accounts
}

// Check role
if ($user->hasRole('Admin')) {
    // User is an Admin
}

if ($user->hasRole(['Admin', 'Super Admin'])) {
    // User is Admin or Super Admin
}

// Get all permissions
$permissions = $user->getPermissions();
```

### Middleware

Two middleware classes are available for route protection:

#### CheckPermission Middleware

Protects routes based on permissions:

```php
// Single permission
Route::get('/accounts', [AccountController::class, 'index'])
    ->middleware('permission:accounts.view');

// Multiple permissions (user needs ANY of these)
Route::resource('accounts', AccountController::class)
    ->middleware('permission:accounts.view,accounts.create,accounts.edit,accounts.delete');

// On route groups
Route::middleware('permission:hardware.view')->group(function () {
    Route::get('/ramspecs', [RamSpecsController::class, 'index']);
    Route::get('/diskspecs', [DiskSpecsController::class, 'index']);
});
```

#### CheckRole Middleware

Protects routes based on roles:

```php
// Single role
Route::get('/admin/settings', [SettingsController::class, 'index'])
    ->middleware('role:Admin');

// Multiple roles (user needs ANY of these)
Route::get('/management', [ManagementController::class, 'index'])
    ->middleware('role:Admin,Team Lead,Super Admin');
```

### Controller Authorization

You can also check permissions directly in controllers:

```php
public function edit(User $account)
{
    // Using authorization helper
    if (!auth()->user()->hasPermission('accounts.edit')) {
        abort(403, 'Unauthorized');
    }

    // Or using the service
    if (!app(PermissionService::class)->userHasPermission(auth()->user(), 'accounts.edit')) {
        abort(403, 'Unauthorized');
    }

    return Inertia::render('Account/Edit', [
        'account' => $account,
    ]);
}
```

---

## Frontend Implementation

### Authorization Hooks

Three hooks are available for permission checking in React components:

#### usePermission Hook

```tsx
import { usePermission } from '@/hooks/useAuthorization';

function MyComponent() {
    const { can, canAny, canAll, cannot, permissions } = usePermission();

    // Check single permission
    if (can('accounts.edit')) {
        // User can edit accounts
    }

    // Check any permission
    if (canAny(['accounts.edit', 'accounts.delete'])) {
        // User can edit OR delete accounts
    }

    // Check all permissions
    if (canAll(['accounts.view', 'accounts.edit'])) {
        // User can view AND edit accounts
    }

    // Check if user cannot
    if (cannot('accounts.delete')) {
        // User cannot delete accounts
    }

    // Access all permissions
    console.log(permissions); // Array of permission strings
}
```

#### useRole Hook

```tsx
import { useRole } from '@/hooks/useAuthorization';

function MyComponent() {
    const { role, hasRole, isSuperAdmin, isAdmin, isAdminLevel } = useRole();

    // Check specific role
    if (hasRole('Admin')) {
        // User is Admin
    }

    // Check multiple roles
    if (hasRole(['Admin', 'Super Admin'])) {
        // User is Admin or Super Admin
    }

    // Convenience methods
    if (isSuperAdmin()) {
        // User is Super Admin
    }

    if (isAdmin()) {
        // User is Admin or Super Admin
    }

    if (isAdminLevel()) {
        // User is Super Admin, Admin, or Team Lead
    }

    // Access current role
    console.log(role); // e.g., "Admin"
}
```

#### useAuthorization Hook

Combined hook that provides both permission and role functions:

```tsx
import { useAuthorization } from '@/hooks/useAuthorization';

function MyComponent() {
    const { can, hasRole, isAdmin, permissions, role } = useAuthorization();

    // Use both permission and role checks
    if (can('accounts.view') && hasRole('Admin')) {
        // User is Admin and can view accounts
    }
}
```

### Authorization Components

Components for conditional rendering based on permissions:

#### Can Component

Renders children only if user has the specified permission:

```tsx
import { Can } from '@/components/authorization';

<Can permission="accounts.create">
    <Button>Create Account</Button>
</Can>

// With fallback
<Can 
    permission="accounts.create"
    fallback={<p>You cannot create accounts</p>}
>
    <Button>Create Account</Button>
</Can>
```

#### CanAny Component

Renders children only if user has any of the specified permissions:

```tsx
import { CanAny } from '@/components/authorization';

<CanAny permissions={["accounts.create", "accounts.edit"]}>
    <Button>Manage Accounts</Button>
</CanAny>
```

#### CanAll Component

Renders children only if user has all of the specified permissions:

```tsx
import { CanAll } from '@/components/authorization';

<CanAll permissions={["accounts.view", "accounts.edit"]}>
    <EditAccountForm />
</CanAll>
```

#### Cannot Component

Renders children only if user does NOT have the specified permission:

```tsx
import { Cannot } from '@/components/authorization';

<Cannot permission="accounts.delete">
    <p className="text-muted-foreground">You don't have permission to delete accounts</p>
</Cannot>
```

#### HasRole Component

Renders children only if user has the specified role(s):

```tsx
import { HasRole } from '@/components/authorization';

<HasRole role="Super Admin">
    <AdminPanel />
</HasRole>

// Multiple roles
<HasRole role={["Admin", "Super Admin"]}>
    <ManagementTools />
</HasRole>
```

#### IsAdmin Component

Renders children only if user is Admin or Super Admin:

```tsx
import { IsAdmin } from '@/components/authorization';

<IsAdmin>
    <AdminDashboard />
</IsAdmin>
```

#### IsSuperAdmin Component

Renders children only if user is Super Admin:

```tsx
import { IsSuperAdmin } from '@/components/authorization';

<IsSuperAdmin>
    <SystemSettings />
</IsSuperAdmin>
```

---

## Usage Examples

### Example 1: Protecting a Page Header with Create Button

```tsx
import { Can } from '@/components/authorization';
import { PageHeader } from '@/components/PageHeader';

function AccountsPage() {
    return (
        <Can 
            permission="accounts.create"
            fallback={
                <PageHeader 
                    title="Accounts" 
                    description="View user accounts" 
                />
            }
        >
            <PageHeader 
                title="Accounts" 
                description="Manage user accounts"
                createLink="/accounts/create"
                createLabel="Create Account"
            />
        </Can>
    );
}
```

### Example 2: Conditional Action Buttons

```tsx
import { Can } from '@/components/authorization';

function AccountRow({ user }) {
    return (
        <div className="flex gap-2">
            <Can permission="accounts.edit">
                <Button onClick={() => editUser(user)}>Edit</Button>
            </Can>
            
            <Can permission="accounts.delete">
                <Button 
                    variant="destructive" 
                    onClick={() => deleteUser(user)}
                >
                    Delete
                </Button>
            </Can>
        </div>
    );
}
```

### Example 3: Role-Based Navigation

```tsx
import { HasRole, useRole } from '@/components/authorization';

function NavigationMenu() {
    const { isAdminLevel } = useRole();

    return (
        <nav>
            <Link href="/dashboard">Dashboard</Link>
            
            {isAdminLevel() && (
                <>
                    <Link href="/accounts">User Management</Link>
                    <Link href="/settings">Settings</Link>
                </>
            )}
            
            <HasRole role={["HR", "Admin", "Super Admin"]}>
                <Link href="/attendance">Attendance</Link>
                <Link href="/leave-requests">Leave Requests</Link>
            </HasRole>
            
            <HasRole role={["IT", "Admin", "Super Admin"]}>
                <Link href="/pcspecs">PC Management</Link>
                <Link href="/stations">Stations</Link>
            </HasRole>
        </nav>
    );
}
```

### Example 4: Complex Permission Logic

```tsx
import { usePermission, useRole } from '@/hooks/useAuthorization';

function DataTable() {
    const { can, canAny } = usePermission();
    const { hasRole } = useRole();

    const canManageData = canAny(['data.edit', 'data.delete']);
    const canExport = can('data.export') || hasRole('Admin');

    return (
        <div>
            <Table data={data} />
            
            {canManageData && (
                <div className="actions">
                    {can('data.edit') && <Button>Edit</Button>}
                    {can('data.delete') && <Button>Delete</Button>}
                </div>
            )}
            
            {canExport && (
                <Button>Export Data</Button>
            )}
        </div>
    );
}
```

---

## Configuration

### Adding New Permissions

To add new permissions, edit `config/permissions.php`:

1. Add the permission to the `permissions` array:

```php
'permissions' => [
    // ... existing permissions
    'reports.view' => 'View Reports',
    'reports.generate' => 'Generate Reports',
],
```

2. Assign the permission to roles in the `role_permissions` array:

```php
'role_permissions' => [
    'admin' => [
        // ... existing permissions
        'reports.view',
        'reports.generate',
    ],
    'team_lead' => [
        // ... existing permissions
        'reports.view',
    ],
],
```

### Adding New Roles

To add a new role:

1. Update the `users` table migration to add the new role to the enum
2. Add the role to the `roles` array in `config/permissions.php`
3. Add the role permissions in the `role_permissions` array
4. Update the `UserRole` type in `resources/js/types/index.d.ts`

### Permission Inheritance

The system uses explicit permission assignment. Super Admin has all permissions by using the wildcard `'*'` in the configuration.

---

## Best Practices

1. **Always protect sensitive routes** with middleware on the backend
2. **Use frontend authorization** for UI/UX improvements, but never rely on it for security
3. **Check permissions in controllers** for additional security layers
4. **Use semantic permission names** that clearly describe what they allow
5. **Group related permissions** by feature/module for easier management
6. **Document custom permissions** when adding new features
7. **Test permission changes** thoroughly across different roles
8. **Use role-based checks** for high-level access, permission checks for specific actions

---

## Troubleshooting

### User has permission but still gets 403 error

- Check if the middleware is correctly applied to the route
- Verify the permission name matches exactly (case-sensitive)
- Ensure the user's role is correctly assigned in the database
- Check if permissions are correctly configured in `config/permissions.php`

### Frontend shows buttons but backend rejects action

- This is expected! Frontend authorization is for UX only
- Backend middleware is the security layer
- Make sure both frontend and backend checks are in place

### Permission changes not reflecting

- Clear Laravel config cache: `php artisan config:clear`
- Clear application cache: `php artisan cache:clear`
- Restart the development server

---

## Security Notes

⚠️ **Important Security Considerations:**

1. **Never trust frontend checks alone** - Always enforce permissions on the backend
2. **Middleware is required** for route protection
3. **Controller checks** provide additional security
4. **Frontend authorization** is for improving user experience, not security
5. **Validate permissions** on every sensitive action
6. **Use HTTPS** in production to protect session data
7. **Regularly audit** user roles and permissions

---

## Support

For questions or issues related to the authorization system, please:

1. Check this documentation first
2. Review the example implementations in the codebase
3. Check `config/permissions.php` for permission definitions
4. Consult the team lead or system administrator

---

**Last Updated:** November 2025  
**Version:** 1.0.0
