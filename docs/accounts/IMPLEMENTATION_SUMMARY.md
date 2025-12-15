# User Account System Implementation Summary

## Overview

A comprehensive user account management system with role-based access control, approval workflow, two-stage deletion process, activity logging, and profile management.

## What Was Implemented

### Backend (Laravel)

1. **Database**
   - `users` table with role, approval status, employee active status, soft delete fields
   - `activity_log` table (via Spatie)
   - Two-factor authentication fields

2. **Model (`app/Models/User.php`)**
   - Role-based access control methods
   - Permission checking methods
   - Soft delete methods: `isSoftDeleted()`, `isDeletionPending()`, `isDeletionConfirmed()`
   - Scopes: `scopeActive()`, `scopeOnlyDeleted()`, `scopePendingDeletion()`
   - Relationships (schedules, attendance, leaves, notifications, deletedBy, deletionConfirmedBy)
   - Activity logging trait
   - Two-factor authentication support
   - Automatic name capitalization

3. **Controllers**
   - `AccountController.php` - User CRUD, approval, bulk operations, deletion workflow, restoration
   - `ActivityLogController.php` - Audit trail viewing
   - `Settings/AccountController.php` - Profile management
   - `Settings/PasswordController.php` - Password management
   - `Settings/PreferencesController.php` - User preferences

4. **Middleware**
   - `EnsureUserIsApproved.php` - Block unapproved/deleted users
   - `CheckPermission.php` - Permission verification
   - `CheckRole.php` - Role verification

5. **Policies**
   - `AccountPolicy.php` - User authorization rules

6. **Mail**
   - `EmployeeAccessRevoked.php` - Email when user is unapproved

7. **Services**
   - `PermissionService.php` - Authorization logic
   - `NotificationService.php` - In-app notifications for account events

### Frontend (React + TypeScript)

1. **Account Pages** (`resources/js/pages/Account/`)
   - `Index.tsx` - User list with search, filters, bulk actions, approval/revocation, deletion
   - `Create.tsx` - Add new user
   - `Edit.tsx` - Modify user details, role, and employee status

2. **Activity Log Pages** (`resources/js/pages/Admin/ActivityLogs/`)
   - `Index.tsx` - Audit trail viewer

3. **Settings Pages** (`resources/js/pages/settings/`)
   - `account.tsx` - Profile editor
   - `password.tsx` - Password change
   - `preferences.tsx` - User preferences
   - `appearance.tsx` - UI theme
   - `two-factor.tsx` - 2FA management

4. **Auth Pages** (`resources/js/pages/auth/`)
   - Login, Register, Password Reset
   - Two-Factor Authentication
   - Email Verification
   - `pending-approval.tsx` - Pending approval message
   - `account-deleted.tsx` - Deleted account with reactivation option

## Key Features

### 1. User Management

| Feature | Description |
|---------|-------------|
| Create | Add new users with role assignment |
| Edit | Modify user details and role |
| Delete | Two-stage deletion (soft → confirm) |
| Restore | Restore soft-deleted accounts |
| Force Delete | Permanently remove confirmed-deleted accounts |
| Search | Find users by name/email |
| Filter | By role, status, employee status |
| Bulk Actions | Approve/unapprove multiple users |

### 2. Role System

| Role | Description |
|------|-------------|
| Super Admin | Full system access |
| Admin | Administrative access |
| Team Lead | Supervisor access |
| Agent | Basic user access |
| HR | HR-focused access |
| IT | IT-focused access |
| Utility | Minimal access |

### 3. Account Lifecycle

```
User Registers → Status: Pending Approval
→ Admin Approves → Full Access
→ Admin Unapproves → Access Blocked (+ optional email)
→ Admin Deletes → Status: Pending Deletion
    → User Self-Reactivates (via /account/reactivate) → Restored
    → Admin Restores → Restored
    → Admin Confirms Delete → Status: Deleted (Confirmed)
        → Admin Force Deletes → Permanently Removed
```

### 4. Employee Active Status

Separate from approval:
- `is_active = true` - Employee can work normally
- `is_active = false` - Employee schedules deactivated
- Auto-set to false when unapproved or deletion confirmed

### 5. Activity Logging

All model changes are logged:
- Created events
- Updated events
- Deleted events
- Before/after values
- User attribution

### 6. Two-Factor Authentication

- TOTP-based (Time-based One-Time Password)
- Recovery codes
- Configurable per user
- Via Laravel Fortify

### 7. Profile Management

- Update name and email (auto-capitalization)
- Change password
- Configure 2FA
- Set time format preference
- Theme preference

### 8. Email Notifications

- `EmployeeAccessRevoked` - Sent when admin unapproves user (optional)

## Database Schema

```sql
users (
    id,
    first_name, middle_name, last_name,
    email, email_verified_at,
    password,
    role,                      -- enum: 'Super Admin', 'Admin', 'Team Lead', 'Agent', 'HR', 'IT', 'Utility'
    time_format,
    hired_date,
    is_approved,               -- boolean (approval status)
    is_active,                 -- boolean (employee active status)
    approved_at,
    deleted_at,                -- timestamp (soft delete marker)
    deleted_by,                -- foreignId (user who initiated deletion)
    deletion_confirmed_at,     -- timestamp (when deletion was confirmed)
    deletion_confirmed_by,     -- foreignId (user who confirmed deletion)
    remember_token,
    two_factor_secret,
    two_factor_recovery_codes,
    two_factor_confirmed_at,
    timestamps
)

activity_log (
    id,
    log_name,
    description,
    subject_type, subject_id,
    causer_type, causer_id,
    properties,                -- JSON: old/new values
    batch_uuid,
    timestamps
)
```

## Routes

```
# Account Management
GET    /accounts                     - List users
GET    /accounts/create              - Create form
POST   /accounts                     - Store user
GET    /accounts/{id}/edit           - Edit form
PUT    /accounts/{id}                - Update user
DELETE /accounts/{id}                - Soft delete user
POST   /accounts/{id}/approve        - Approve user
POST   /accounts/{id}/unapprove      - Unapprove user (+ optional email)
POST   /accounts/{id}/toggle-active  - Toggle employee active status
POST   /accounts/bulk-approve        - Bulk approve users
POST   /accounts/bulk-unapprove      - Bulk unapprove users
POST   /accounts/{id}/confirm-delete - Confirm account deletion
POST   /accounts/{id}/restore        - Restore deleted account
DELETE /accounts/{id}/force-delete   - Permanently delete account

# Account Reactivation (Guest)
GET    /account/reactivate           - Reactivation page
POST   /account/reactivate           - Process reactivation

# Activity Logs
GET    /activity-logs                - View logs

# Settings
GET    /settings                     - Redirect to /settings/account
GET    /settings/account             - Account settings
PATCH  /settings/account             - Update profile
GET    /settings/password            - Password settings
PUT    /settings/password            - Update password
GET    /settings/preferences         - User preferences
PATCH  /settings/preferences         - Update preferences
GET    /settings/appearance          - Appearance settings
GET    /settings/two-factor          - 2FA settings

# Authentication
GET    /login                        - Login page
POST   /login                        - Authenticate
POST   /logout                       - Logout
GET    /register                     - Register page
POST   /register                     - Create account
GET    /forgot-password              - Reset request
POST   /forgot-password              - Send reset email
GET    /reset-password/{token}       - Reset form
POST   /reset-password               - Update password
```

## Permissions

| Permission | Description |
|------------|-------------|
| `accounts.view` | View user accounts |
| `accounts.create` | Create user accounts |
| `accounts.edit` | Edit user accounts |
| `accounts.delete` | Delete user accounts |
| `activity_logs.view` | View activity logs |
| `settings.view` | Access settings |
| `settings.account` | Manage account settings |
| `settings.password` | Change password |

## How It Works

### 1. User Registration Flow

```
User submits registration
→ Account created (is_approved = false, is_active = true)
→ User sees "Pending Approval" page
→ Admin receives notification (optional)
→ Admin approves user
→ User gains full access
```

### 2. Account Deletion Flow

```
Admin clicks "Delete"
→ Account soft-deleted (deleted_at set, deleted_by recorded)
→ User sees "Account Deleted" page with reactivation option
→ User can self-reactivate via /account/reactivate
    → Password reset required
    → Account restored (deleted_at cleared)
→ OR Admin confirms deletion
    → deletion_confirmed_at set
    → is_active set to false
    → Schedules deactivated
→ Admin can force-delete confirmed accounts
    → Permanent removal from database
```

### 3. Activity Logging Flow

```
Model change occurs
→ Spatie Activity Log intercepts
→ Records: who, what, when, before/after
→ Stored in activity_log table
→ Viewable in /activity-logs
```

### 4. Permission Check Flow

```
Request to protected route
→ Middleware checks permission
→ If allowed: Continue
→ If denied: 403 Forbidden
```

## Files Reference

### Backend
```
app/
├── Models/
│   └── User.php
├── Http/
│   ├── Controllers/
│   │   ├── AccountController.php
│   │   ├── ActivityLogController.php
│   │   └── Settings/
│   │       ├── AccountController.php
│   │       ├── PasswordController.php
│   │       └── PreferencesController.php
│   └── Middleware/
│       ├── EnsureUserIsApproved.php
│       ├── CheckPermission.php
│       └── CheckRole.php
├── Policies/
│   └── AccountPolicy.php
├── Mail/
│   └── EmployeeAccessRevoked.php
├── Providers/
│   └── FortifyServiceProvider.php
└── Services/
    ├── PermissionService.php
    └── NotificationService.php
```

### Frontend
```
resources/js/pages/
├── Account/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
├── Admin/
│   └── ActivityLogs/
│       └── Index.tsx
├── settings/
│   ├── account.tsx
│   ├── password.tsx
│   ├── preferences.tsx
│   ├── appearance.tsx
│   └── two-factor.tsx
└── auth/
    ├── login.tsx
    ├── register.tsx
    ├── forgot-password.tsx
    ├── reset-password.tsx
    ├── verify-email.tsx
    ├── confirm-password.tsx
    ├── two-factor-challenge.tsx
    ├── pending-approval.tsx
    └── account-deleted.tsx
```

### Configuration
```
config/
├── fortify.php           - Auth features
├── permissions.php       - Roles & permissions
└── activitylog.php       - Logging settings
```

## User Model Methods

```php
// Soft Delete Status
$user->isSoftDeleted();        // Is deleted_at set?
$user->isDeletionPending();    // Deleted but not confirmed
$user->isDeletionConfirmed();  // Deletion confirmed

// Scopes
User::active()->get();          // Only is_active = true
User::onlyDeleted()->get();     // Only soft-deleted
User::pendingDeletion()->get(); // Only pending deletion

// Relationships
$user->employeeSchedules;       // Work schedules
$user->activeSchedule;          // Currently active schedule
$user->attendances;             // Attendance records
$user->attendancePoints;        // Violation points
$user->leaveCredits;            // Leave balances
$user->leaveRequests;           // Leave submissions
$user->notifications;           // User notifications
$user->reviewedLeaveRequests;   // Leaves reviewed by user
$user->deletedBy;               // User who deleted
$user->deletionConfirmedBy;     // User who confirmed deletion
```

## Related Documentation

- [Authorization System](../authorization/README.md) - RBAC details
- [Notification System](../NOTIFICATION_SYSTEM.md) - Notifications
- [Leave Management](../leave/README.md) - Leave system
- [API Routes](../api/ROUTES.md) - Complete routes reference

---

**Implementation Date:** November 2025  
**Last Updated:** December 2025  
**Status:** ✅ Complete and Production Ready
