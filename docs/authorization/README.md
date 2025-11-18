# Role-Based Access Control (RBAC) System

A comprehensive role-based access control system for the PrimeHub Systems application, providing fine-grained permission management for both backend and frontend.

## 🚀 Features

- **Role-Based Access**: 7 predefined roles (Super Admin, Admin, Team Lead, Agent, HR, IT, Utility)
- **Fine-Grained Permissions**: 60+ permissions covering all system features
- **Backend Protection**: Middleware for route protection
- **Frontend Components**: React components for conditional rendering
- **Easy to Use**: Hooks and helper methods for permission checks
- **Fully Typed**: TypeScript support throughout
- **Flexible Configuration**: Easy to add new roles and permissions

## 📚 Documentation

- **[Complete Guide](./RBAC_GUIDE.md)** - Full documentation with examples
- **[Quick Reference](./QUICK_REFERENCE.md)** - Cheat sheet for common tasks

## 🎯 Quick Start

### Backend Protection

Protect your routes with middleware:

```php
Route::get('/accounts', [AccountController::class, 'index'])
    ->middleware('permission:accounts.view');
```

Check permissions in controllers:

```php
if (!auth()->user()->hasPermission('accounts.edit')) {
    abort(403);
}
```

### Frontend Usage

Use authorization components:

```tsx
import { Can } from '@/components/authorization';

<Can permission="accounts.create">
    <Button>Create Account</Button>
</Can>
```

Or use hooks for more control:

```tsx
import { usePermission } from '@/hooks/useAuthorization';

const { can, canAny } = usePermission();

if (can('accounts.edit')) {
    // Show edit functionality
}
```

## 🔐 Security Model

The authorization system uses a **layered security approach**:

1. **Backend Middleware** - First line of defense, blocks unauthorized requests
2. **Controller Checks** - Additional validation before processing
3. **Frontend Components** - UI/UX improvements, shows/hides elements based on permissions

**Important:** Never rely on frontend checks alone for security!

## 📦 What's Included

### Backend
- `config/permissions.php` - Permission configuration
- `app/Services/PermissionService.php` - Core authorization logic
- `app/Http/Middleware/CheckPermission.php` - Permission middleware
- `app/Http/Middleware/CheckRole.php` - Role middleware
- Helper methods in `User` model

### Frontend
- `resources/js/hooks/useAuthorization.ts` - React hooks
- `resources/js/components/authorization/index.tsx` - React components
- TypeScript types in `resources/js/types/index.d.ts`

### Documentation
- `docs/authorization/RBAC_GUIDE.md` - Complete guide
- `docs/authorization/QUICK_REFERENCE.md` - Quick reference
- `docs/authorization/README.md` - This file

## 🛠️ Configuration

All permissions are configured in `config/permissions.php`:

```php
return [
    'roles' => [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        // ...
    ],
    
    'permissions' => [
        'accounts.view' => 'View User Accounts',
        'accounts.create' => 'Create User Accounts',
        // ...
    ],
    
    'role_permissions' => [
        'admin' => [
            'accounts.view',
            'accounts.create',
            // ...
        ],
    ],
];
```

## 📋 Available Roles

| Role | Description |
|------|-------------|
| Super Admin | Full system access |
| Admin | Administrative access |
| Team Lead | Supervisor access |
| Agent | Basic user access |
| HR | HR-focused access |
| IT | IT-focused access |
| Utility | Minimal access |

## 🎨 Usage Examples

### Example 1: Protect a Resource Controller

```php
Route::resource('accounts', AccountController::class)
    ->middleware('permission:accounts.view,accounts.create,accounts.edit,accounts.delete');
```

### Example 2: Conditional UI Elements

```tsx
<div className="flex gap-2">
    <Can permission="accounts.edit">
        <Button onClick={handleEdit}>Edit</Button>
    </Can>
    
    <Can permission="accounts.delete">
        <Button variant="destructive" onClick={handleDelete}>
            Delete
        </Button>
    </Can>
</div>
```

### Example 3: Role-Based Navigation

```tsx
import { HasRole } from '@/components/authorization';

<nav>
    <Link href="/dashboard">Dashboard</Link>
    
    <HasRole role={["Admin", "Super Admin"]}>
        <Link href="/accounts">User Management</Link>
    </HasRole>
    
    <HasRole role="HR">
        <Link href="/attendance">Attendance</Link>
    </HasRole>
</nav>
```

### Example 4: Complex Permission Logic

```tsx
const { can, canAny } = usePermission();
const { hasRole } = useRole();

// Complex conditions
const canManageUsers = canAny(['accounts.edit', 'accounts.delete']);
const canViewReports = can('reports.view') || hasRole('Admin');

return (
    <div>
        {canManageUsers && <UserManagementPanel />}
        {canViewReports && <ReportsSection />}
    </div>
);
```

## 🔧 Adding New Permissions

1. **Define the permission** in `config/permissions.php`:
```php
'permissions' => [
    'reports.generate' => 'Generate Reports',
],
```

2. **Assign to roles**:
```php
'role_permissions' => [
    'admin' => [
        'reports.generate',
    ],
],
```

3. **Protect the route**:
```php
Route::post('/reports/generate', [ReportController::class, 'generate'])
    ->middleware('permission:reports.generate');
```

4. **Use in frontend**:
```tsx
<Can permission="reports.generate">
    <Button>Generate Report</Button>
</Can>
```

## 📊 Permission Categories

The system includes permissions for:

- ✅ Dashboard access
- ✅ User account management
- ✅ Hardware specifications
- ✅ PC specifications and QR codes
- ✅ Sites and campaigns
- ✅ Station management
- ✅ Stock inventory
- ✅ PC transfers and maintenance
- ✅ Attendance management
- ✅ Employee schedules
- ✅ Biometric records
- ✅ Leave requests
- ✅ Settings

## 🧪 Testing

After implementing the system:

1. **Test each role** to ensure proper access
2. **Verify backend protection** by attempting unauthorized requests
3. **Check frontend visibility** for each role
4. **Test permission changes** by updating configuration

## 🐛 Troubleshooting

**403 Errors:**
- Verify permission name matches configuration
- Check user role assignment
- Clear config cache: `php artisan config:clear`

**Permissions not updating:**
- Clear Laravel cache: `php artisan cache:clear`
- Restart development server

**Frontend shows but backend blocks:**
- This is expected! Backend is the security layer
- Ensure middleware is applied to routes

## 📞 Support

For questions or issues:

1. Check the [Complete Guide](./RBAC_GUIDE.md)
2. Review the [Quick Reference](./QUICK_REFERENCE.md)
3. Check `config/permissions.php` for permission definitions

## ⚠️ Security Best Practices

1. **Always use backend middleware** for route protection
2. **Never trust frontend checks** for security decisions
3. **Validate permissions** in controller methods
4. **Use HTTPS** in production
5. **Regularly audit** user permissions
6. **Test thoroughly** before deployment

## 📝 License

This authorization system is part of the PrimeHub Systems application.

---

**Version:** 1.0.0  
**Last Updated:** November 2025
