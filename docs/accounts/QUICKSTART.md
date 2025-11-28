# User Account System - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Laravel application running
- Database migrated
- Mail configured (for password reset)

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
3. Click "Approve" button
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

1. Go to `/settings`
2. Click "Enable 2FA"
3. Scan QR code with authenticator app
4. Enter verification code
5. Save recovery codes

### Update User Profile

1. Go to `/settings/profile`
2. Update name, email
3. Save changes

### Search for Users

1. Go to `/accounts`
2. Use search box
3. Filter by role if needed
4. Click user to edit

### Deactivate User

1. Go to `/accounts/{id}/edit`
2. Click "Unapprove"
3. User loses access immediately

## 📋 Quick Reference

### Key URLs

| Page | URL |
|------|-----|
| User List | `/accounts` |
| Create User | `/accounts/create` |
| Edit User | `/accounts/{id}/edit` |
| Activity Logs | `/activity-logs` |
| Profile Settings | `/settings/profile` |
| Password Settings | `/settings/password` |
| Login | `/login` |
| Register | `/register` |

### User Statuses

| Status | Badge | Description |
|--------|-------|-------------|
| Approved | Green | Full access |
| Pending | Yellow | Awaiting approval |
| Unapproved | Red | Access blocked |

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
$user->hasPermission('accounts.view'); // Check permission
```

### Test Login

```bash
# Via browser
1. Go to /login
2. Enter credentials
3. Should redirect to dashboard

# Via API (if applicable)
curl -X POST /login -d "email=test@example.com&password=secret"
```

### Test 2FA

```php
// Check if 2FA is enabled
$user->two_factor_confirmed_at !== null
```

### Manual Testing Checklist

- [ ] Create user → Saved with role
- [ ] User registers → Status pending
- [ ] Approve user → Can login
- [ ] Unapprove → Cannot access
- [ ] Change password → Works
- [ ] Enable 2FA → Required on login
- [ ] Activity log → Changes recorded

## 🐛 Troubleshooting

### User Cannot Login

**Cause 1:** Not approved
```php
$user->is_approved; // Check if true
```

**Cause 2:** Wrong password
```php
// Reset password
$user->update(['password' => bcrypt('newpassword')]);
```

**Cause 3:** 2FA required
- User needs authenticator app
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
