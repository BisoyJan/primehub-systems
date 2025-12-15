# User Account System - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Laravel application running
- Database migrated
- Mail configured (for password reset and access revoked emails)

### 1. Create a User Account

**Option A: Via Admin Interface**
1. Navigate to `/accounts/create`
2. Fill in user details:
   - First Name, Last Name
   - Email address
   - Password
   - Role
   - Hired Date
3. Save

**Option B: Via Registration**
1. User visits `/register`
2. Fills registration form
3. Awaits admin approval

### 2. Approve New Users

1. Navigate to `/accounts`
2. Find pending users (yellow badge)
3. Click "Approve" button (single) or select multiple and "Bulk Approve"
4. User receives access

### 3. Assign Roles

Roles determine what users can access:

| Role | Access Level |
|------|--------------|
| super_admin | Everything |
| admin | Administrative |
| team_lead | Supervisory |
| agent | Basic |
| hr | HR functions |
| it | IT functions |
| utility | Minimal |

### 4. View Activity Logs

1. Navigate to `/activity-logs`
2. Filter by:
   - Model type
   - User who made change
   - Date range
3. View change details

## 🔧 Common Tasks

### Change User Password

**As Admin:**
1. Edit user at `/accounts/{id}/edit`
2. Enter new password
3. Save

**As User:**
1. Go to `/settings/password`
2. Enter current password
3. Enter new password
4. Save

### Enable Two-Factor Authentication

1. Go to `/settings/two-factor`
2. Click "Enable 2FA"
3. Scan QR code with authenticator app
4. Enter verification code
5. Save recovery codes

### Update User Profile

1. Go to `/settings/account`
2. Update name, email
3. Save changes

### Search and Filter Users

1. Go to `/accounts`
2. Use search box for name/email
3. Filter options:
   - **Role**: Filter by specific role
   - **Status**: `pending`, `approved`, `pending_deletion`, `deleted`
   - **Employee Status**: `active`, `inactive`
4. Click user to edit

### Deactivate User (Unapprove)

1. Go to `/accounts/{id}/edit`
2. Click "Unapprove"
3. Optionally send email notification
4. User loses access immediately

### Toggle Employee Active Status

1. Go to `/accounts`
2. Find the user
3. Click "Toggle Active" action
4. Employee schedules are deactivated when inactive

### Delete User Account (Two-Stage Process)

**Stage 1: Soft Delete (Pending Deletion)**
1. Go to `/accounts/{id}/edit`
2. Click "Delete"
3. Account enters "Pending Deletion" state
4. User can self-reactivate at `/account/reactivate`

**Stage 2: Confirm Deletion**
1. Go to `/accounts`
2. Filter by "Pending Deletion" status
3. Click "Confirm Delete" on the account
4. Account is permanently marked as deleted

### Restore Deleted Account

1. Go to `/accounts`
2. Filter by "Pending Deletion" or "Deleted" status
3. Click "Restore" on the account
4. Account returns to previous state

### Force Delete (Permanent)

1. Only available for confirmed-deleted accounts
2. Go to `/accounts`
3. Filter by "Deleted" status
4. Click "Force Delete"
5. Account is permanently removed

### Bulk Operations

1. Go to `/accounts`
2. Select multiple users using checkboxes
3. Use bulk action buttons:
   - **Bulk Approve**: Approve all selected pending users
   - **Bulk Unapprove**: Unapprove all selected (with optional email)

## 📋 Quick Reference

### Key URLs

| Page | URL |
|------|-----|
| User List | `/accounts` |
| Create User | `/accounts/create` |
| Edit User | `/accounts/{id}/edit` |
| Activity Logs | `/activity-logs` |
| Account Settings | `/settings/account` |
| Password Settings | `/settings/password` |
| Preferences | `/settings/preferences` |
| Appearance | `/settings/appearance` |
| Two-Factor Auth | `/settings/two-factor` |
| Login | `/login` |
| Register | `/register` |
| Reactivate Account | `/account/reactivate` |

### User Statuses

| Status | Badge | Description |
|--------|-------|-------------|
| Approved | Green | Full access |
| Pending | Yellow | Awaiting approval |
| Unapproved | Red | Access blocked |
| Pending Deletion | Orange | Soft deleted, can reactivate |
| Deleted | Dark Red | Deletion confirmed |

### Employee Active Status

| Status | Description |
|--------|-------------|
| Active | Employee can work, schedules active |
| Inactive | Employee schedules deactivated |

### Role Hierarchy

```
super_admin (Full Access)
└── admin (Administrative)
    ├── team_lead (Supervisory)
    ├── hr (HR Functions)
    └── it (IT Functions)
        └── agent (Basic)
            └── utility (Minimal)
```

### Activity Log Events

| Event | Description |
|-------|-------------|
| Created | New record added |
| Updated | Record modified |
| Deleted | Record removed |

## 🧪 Testing

### Verify User Creation

```php
php artisan tinker

$user = \App\Models\User::where('email', 'test@example.com')->first();
$user->role;          // Check role
$user->is_approved;   // Check approval
$user->is_active;     // Check employee active status
$user->isSoftDeleted();     // Check if deleted
$user->isDeletionPending(); // Check if pending deletion
```

### Test Login

```bash
# Via browser
1. Go to /login
2. Enter credentials
3. Should redirect to dashboard (if approved)
4. Should see "Pending Approval" page (if not approved)
5. Should see "Account Deleted" page (if deleted)
```

### Test 2FA

```php
// Check if 2FA is enabled
$user->two_factor_confirmed_at !== null
```

### Test Account Lifecycle

```php
// Test soft delete status methods
$user = App\Models\User::find(1);

// Before deletion
$user->isSoftDeleted();        // false
$user->isDeletionPending();    // false
$user->isDeletionConfirmed();  // false

// After soft delete (pending)
$user->update(['deleted_at' => now(), 'deleted_by' => auth()->id()]);
$user->isSoftDeleted();        // true
$user->isDeletionPending();    // true
$user->isDeletionConfirmed();  // false

// After confirm delete
$user->update(['deletion_confirmed_at' => now(), 'deletion_confirmed_by' => auth()->id()]);
$user->isDeletionPending();    // false
$user->isDeletionConfirmed();  // true
```

### Manual Testing Checklist

- [ ] Create user → Saved with role
- [ ] User registers → Status pending
- [ ] Approve user → Can login
- [ ] Unapprove → Cannot access, receives email (optional)
- [ ] Toggle active → Employee schedules deactivated
- [ ] Bulk approve → Multiple users approved
- [ ] Bulk unapprove → Multiple users blocked
- [ ] Delete → Status "Pending Deletion"
- [ ] User self-reactivate → Account restored (via /account/reactivate)
- [ ] Admin restore → Account restored
- [ ] Confirm delete → Status "Deleted"
- [ ] Force delete → Account permanently removed
- [ ] Change password → Works
- [ ] Enable 2FA → Required on login
- [ ] Activity log → Changes recorded

## 🐛 Troubleshooting

### User Cannot Login

**Cause 1:** Not approved
```php
$user->is_approved; // Check if true
```

**Cause 2:** Account deleted
```php
$user->isSoftDeleted(); // Check if deleted_at is set
// User will see "Account Deleted" page with reactivation option
```

**Cause 3:** Wrong password
```php
// Reset password
$user->update(['password' => bcrypt('newpassword')]);
```

**Cause 4:** 2FA required
- User needs authenticator app
- Or use recovery code

### User Sees "Pending Approval" Page

**Cause:** User registered but not approved yet

**Fix:**
```php
$user->update([
    'is_approved' => true,
    'approved_at' => now(),
]);
```

### User Sees "Account Deleted" Page

**Cause:** Account was soft deleted

**Options:**
1. User can self-reactivate at `/account/reactivate`
2. Admin can restore via `/accounts` → Filter "Pending Deletion" → Restore
- Or use recovery code

### Activity Logs Empty

**Cause:** Logging not enabled on model

**Fix:** Add to model:
```php
use Spatie\Activitylog\Traits\LogsActivity;

class YourModel extends Model
{
    use LogsActivity;
    
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }
}
```

### Cannot Approve User

**Cause:** Missing permission

**Check:**
```php
auth()->user()->hasPermission('accounts.edit');
```

### Password Reset Not Working

**Cause:** Mail not configured

**Fix:** Check `.env`:
```
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
```

## 📊 User Relationships

Each user has access to:

| Relationship | Description |
|--------------|-------------|
| `employeeSchedules` | Work schedules |
| `attendances` | Attendance records |
| `attendancePoints` | Violation points |
| `leaveCredits` | Leave balances |
| `leaveRequests` | Leave submissions |
| `notifications` | User notifications |

## 🔗 Related Features

### Authorization
- Role-based access
- Permission system
- See: [Authorization](../authorization/README.md)

### Notifications
- User notifications
- See: [Notifications](../NOTIFICATION_SYSTEM.md)

### Leave Management
- Leave credits per user
- See: [Leave System](../leave/README.md)

### Attendance
- Attendance tracking per user
- See: [Attendance](../attendance/README.md)

---

**Need help?** Check the [full documentation](README.md) or [implementation details](IMPLEMENTATION_SUMMARY.md).
