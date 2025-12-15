# Authorization System - Quick Start Guide

## 🚀 Getting Started

### 1. Check User Role

Every user has a role assigned. Check current role:

```php
php artisan tinker

$user = User::find(1);
echo $user->role; // "Admin", "Agent", etc.
```

### 2. Protect a Route

Add permission middleware to any route:

```php
// In routes/web.php
Route::get('/accounts', [AccountController::class, 'index'])
    ->middleware('permission:accounts.view');

// Multiple permissions (user needs ANY of them)
Route::resource('accounts', AccountController::class)
    ->middleware('permission:accounts.view,accounts.create');
```

### 3. Check Permission in Controller

```php
public function edit(User $account)
{
    // Option 1: Abort if unauthorized
    if (!auth()->user()->hasPermission('accounts.edit')) {
        abort(403, 'Unauthorized');
    }
    
    // Option 2: Redirect
    if (!auth()->user()->can('accounts.edit')) {
        return redirect('/dashboard')->with('error', 'Access denied');
    }
    
    return Inertia::render('Account/Edit', ['account' => $account]);
}
```

### 4. Protect Frontend UI

```tsx
import { Can } from '@/components/authorization';

// Show button only if user has permission
<Can permission="accounts.create">
    <Button>Create Account</Button>
</Can>

// With fallback content
<Can 
    permission="accounts.delete" 
    fallback={<span className="text-gray-400">No access</span>}
>
    <Button variant="destructive">Delete</Button>
</Can>
```

### 5. Use Authorization Hooks

```tsx
import { usePermission, useRole } from '@/hooks/useAuthorization';

function MyComponent() {
    const { can, canAny } = usePermission();
    const { hasRole, isAdmin } = useRole();
    
    return (
        <div>
            {can('accounts.edit') && <EditButton />}
            {canAny(['accounts.edit', 'accounts.delete']) && <ManagePanel />}
            {isAdmin() && <AdminSection />}
            {hasRole('HR') && <HRDashboard />} {/* Use Title Case database value */}
        </div>
    );
}
```

## 🔧 Common Tasks

### Add New Permission

1. Edit `config/permissions.php`:
```php
'permissions' => [
    // ... existing permissions
    'reports.generate' => 'Generate Reports',
],

'role_permissions' => [
    'admin' => [
        // ... existing
        'reports.generate',
    ],
],
```

2. Clear cache:
```bash
php artisan config:clear
php artisan cache:clear
```

3. Use in route:
```php
Route::post('/reports', [ReportController::class, 'generate'])
    ->middleware('permission:reports.generate');
```

### Change User Role

```php
php artisan tinker

$user = User::find(1);
$user->update(['role' => 'Admin']); // Use Title Case: 'Super Admin', 'Team Lead', etc.
```

### Check All User Permissions

```php
php artisan tinker

$user = User::find(1);
print_r($user->getPermissions());
```

## 📋 Quick Reference

### Available Roles

| Config Key (snake_case) | Database Value (Title Case) | Description |
|------------------------|----------------------------|-------------|
| super_admin | Super Admin | Full system access |
| admin | Admin | Administrative access |
| team_lead | Team Lead | Supervisor access |
| agent | Agent | Basic user access |
| hr | HR | HR-focused access |
| it | IT | IT-focused access |
| utility | Utility | Minimal access |

**Note:** Database stores roles as Title Case with spaces (e.g., "Super Admin"). Config file uses snake_case keys for array access (e.g., 'super_admin').

### Authorization Components

```tsx
// Permission-based
<Can permission="accounts.view">...</Can>
<CanAny permissions={['accounts.edit', 'accounts.delete']}>...</CanAny>
<CanAll permissions={['accounts.view', 'accounts.edit']}>...</CanAll>
<Cannot permission="accounts.delete">...</Cannot>

// Role-based
<HasRole role="Admin">...</HasRole>
<HasRole role={['Admin', 'HR']}>...</HasRole>
<IsAdmin>...</IsAdmin>
<IsSuperAdmin>...</IsSuperAdmin>
```

### Hook Functions

```tsx
const { can, canAny, canAll, cannot, permissions } = usePermission();
const { hasRole, isSuperAdmin, isAdmin, isAdminLevel, role } = useRole();
```

### Permission Categories

| Category | Permissions |
|----------|-------------|
| Dashboard | `dashboard.view` |
| Accounts | `accounts.{view,create,edit,delete}` |
| Hardware | `hardware.{view,create,edit,delete}` |
| PC Specs | `pcspecs.{view,create,edit,delete,qrcode,update_issue}` |
| Stations | `stations.{view,create,edit,delete,qrcode,bulk}` |
| Attendance | `attendance.{view,create,import,review,verify,approve}` |
| Leave | `leave.{view,create,approve,deny,cancel,view_all}` |

## 🧪 Testing

### Verify Permissions Work

```bash
# Test as different user
php artisan tinker

$user = User::where('role', 'Agent')->first();
Auth::login($user);

// Try to access protected route
$response = $this->get('/accounts');
// Should return 403 if Agent doesn't have accounts.view
```

### Manual Testing Checklist

- [ ] Login as Super Admin → Full access
- [ ] Login as Agent → Limited access
- [ ] Unauthorized route → 403 error
- [ ] Frontend hides restricted buttons
- [ ] Role change → Permissions update

## 🐛 Troubleshooting

### 403 Forbidden Error

**Cause:** User doesn't have required permission

**Fix:**
1. Check user role: `$user->role`
2. Check role has permission in `config/permissions.php`
3. Clear cache: `php artisan config:clear`

### Permissions Not Updating

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### Frontend Shows Button But Backend Blocks

**This is correct!** Backend is the security layer. Frontend hiding is just UX.

**Fix:** Ensure permission check exists in both:
- Route middleware
- React component

### "Permission Not Found" Error

**Cause:** Typo in permission name

**Fix:** Check exact name in `config/permissions.php`

## 📊 Role Permission Matrix

| Feature | Super Admin | Admin | Team Lead | Agent | HR | IT |
|---------|:-----------:|:-----:|:---------:|:-----:|:--:|:--:|
| Dashboard | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Accounts | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| Hardware | ✅ | ✅ | View | View | ❌ | ✅ |
| Attendance | ✅ | ✅ | Review | View | ✅ | ❌ |
| Leave | ✅ | ✅ | View All | Own | ✅ | Own |

## 🔗 Related Documentation

- [RBAC Guide](RBAC_GUIDE.md) - Complete documentation
- [Quick Reference](QUICK_REFERENCE.md) - Cheat sheet
- [Implementation Summary](IMPLEMENTATION_SUMMARY.md) - Technical details

---

**Need help?** Check `config/permissions.php` for all available permissions.
