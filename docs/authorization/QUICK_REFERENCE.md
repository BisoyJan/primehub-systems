# RBAC Quick Reference

## Quick Start

### Backend - Protect a Route

```php
// Single permission
Route::get('/accounts', [AccountController::class, 'index'])
    ->middleware('permission:accounts.view');

// Multiple permissions
Route::resource('accounts', AccountController::class)
    ->middleware('permission:accounts.view,accounts.create,accounts.edit');

// Role-based
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware('role:Admin,Super Admin');
```

### Backend - Check in Controller

```php
// Using User model methods
if (!auth()->user()->hasPermission('accounts.edit')) {
    abort(403);
}

// Check role
if (auth()->user()->hasRole(['Admin', 'Super Admin'])) {
    // Do something
}
```

### Frontend - Component Wrapper

```tsx
import { Can } from '@/components/authorization';

<Can permission="accounts.create">
    <Button>Create Account</Button>
</Can>

<Can permission="accounts.edit" fallback={<p>No permission</p>}>
    <EditForm />
</Can>
```

### Frontend - Hook Usage

```tsx
import { usePermission } from '@/hooks/useAuthorization';

function MyComponent() {
    const { can, canAny } = usePermission();
    
    if (can('accounts.edit')) {
        // Show edit button
    }
    
    if (canAny(['accounts.edit', 'accounts.delete'])) {
        // Show management section
    }
}
```

## Available Components

| Component | Usage | Example |
|-----------|-------|---------|
| `<Can>` | Check single permission | `<Can permission="accounts.view">...</Can>` |
| `<CanAny>` | Check any permission | `<CanAny permissions={["a", "b"]}>...</CanAny>` |
| `<CanAll>` | Check all permissions | `<CanAll permissions={["a", "b"]}>...</CanAll>` |
| `<Cannot>` | Check permission absence | `<Cannot permission="accounts.delete">...</Cannot>` |
| `<HasRole>` | Check role | `<HasRole role="Admin">...</HasRole>` |
| `<IsAdmin>` | Check if admin | `<IsAdmin>...</IsAdmin>` |
| `<IsSuperAdmin>` | Check if super admin | `<IsSuperAdmin>...</IsSuperAdmin>` |

## Available Hooks

### usePermission()

```tsx
const { 
    can,           // (permission: string) => boolean
    canAny,        // (permissions: string[]) => boolean
    canAll,        // (permissions: string[]) => boolean
    cannot,        // (permission: string) => boolean
    permissions    // string[] - all user permissions
} = usePermission();
```

### useRole()

```tsx
const { 
    role,          // UserRole - current role
    hasRole,       // (role: UserRole | UserRole[]) => boolean
    isSuperAdmin,  // () => boolean
    isAdmin,       // () => boolean (Admin or Super Admin)
    isAdminLevel   // () => boolean (Super Admin, Admin, or Team Lead)
} = useRole();
```

### useAuthorization()

Combines both `usePermission()` and `useRole()`:

```tsx
const { 
    can, canAny, canAll, cannot, permissions,
    role, hasRole, isSuperAdmin, isAdmin, isAdminLevel
} = useAuthorization();
```

## Common Permission Patterns

### Page Access

```tsx
<Can permission="dashboard.view">
    <DashboardPage />
</Can>
```

### Create Button

```tsx
<Can permission="accounts.create">
    <Button onClick={handleCreate}>Create</Button>
</Can>
```

### Edit/Delete Actions

```tsx
<div className="flex gap-2">
    <Can permission="accounts.edit">
        <Button>Edit</Button>
    </Can>
    <Can permission="accounts.delete">
        <Button variant="destructive">Delete</Button>
    </Can>
</div>
```

### Conditional Header

```tsx
<Can 
    permission="accounts.create"
    fallback={<PageHeader title="Accounts" />}
>
    <PageHeader 
        title="Accounts" 
        createLink="/accounts/create"
        createLabel="Create Account"
    />
</Can>
```

### Complex Logic

```tsx
const { can, canAny } = usePermission();
const { hasRole } = useRole();

const canManage = canAny(['accounts.edit', 'accounts.delete']);
const canViewSensitive = can('accounts.view_sensitive') || hasRole('Admin');

return (
    <div>
        {canManage && <ManagementPanel />}
        {canViewSensitive && <SensitiveData />}
    </div>
);
```

## Roles Overview

| Role (Database Format) | Config Key | Access Level |
|----------------------|------------|-------------|
| **Super Admin** | super_admin | Full access to everything |
| **Admin** | admin | Most features, limited system settings |
| **Team Lead** | team_lead | Supervisor access, can review/verify |
| **Agent** | agent | Basic read-only access |
| **HR** | hr | Attendance, schedules, leave management |
| **IT** | it | Hardware, PCs, infrastructure |
| **Utility** | utility | Minimal access |

**Note:** Database uses Title Case (e.g., "Team Lead"). Config keys use snake_case (e.g., 'team_lead').

## Common Permissions by Feature

### Accounts
- `accounts.view`, `accounts.create`, `accounts.edit`, `accounts.delete`

### Hardware
- `hardware.view`, `hardware.create`, `hardware.edit`, `hardware.delete`

### PC Specs
- `pcspecs.view`, `pcspecs.create`, `pcspecs.edit`, `pcspecs.delete`
- `pcspecs.qrcode`, `pcspecs.update_issue`

### Stations
- `stations.view`, `stations.create`, `stations.edit`, `stations.delete`
- `stations.qrcode`, `stations.bulk`

### Attendance
- `attendance.view`, `attendance.create`, `attendance.import`
- `attendance.review`, `attendance.verify`, `attendance.approve`

### Leave
- `leave.view`, `leave.create`, `leave.approve`, `leave.deny`
- `leave.cancel`, `leave.view_all`

## Files to Know

- **Backend Config:** `config/permissions.php`
- **Backend Service:** `app/Services/PermissionService.php`
- **Backend Middleware:** `app/Http/Middleware/CheckPermission.php`, `CheckRole.php`
- **Frontend Hooks:** `resources/js/hooks/useAuthorization.ts`
- **Frontend Components:** `resources/js/components/authorization/index.tsx`
- **Types:** `resources/js/types/index.d.ts`

## Adding New Permission

1. Add to `config/permissions.php`:
```php
'permissions' => [
    'feature.action' => 'Description',
],
```

2. Assign to roles:
```php
'role_permissions' => [
    'admin' => [
        'feature.action',
    ],
],
```

3. Protect route:
```php
Route::get('/feature', [Controller::class, 'index'])
    ->middleware('permission:feature.action');
```

4. Use in frontend:
```tsx
<Can permission="feature.action">
    <FeatureComponent />
</Can>
```

## Remember

✅ **Always protect backend routes** with middleware  
✅ **Check permissions in controllers** for extra security  
✅ **Use frontend checks** for better UX  
✅ **Never rely on frontend** for security  
✅ **Test with different roles** before deploying  

---

For complete documentation, see [`RBAC_GUIDE.md`](./RBAC_GUIDE.md)
