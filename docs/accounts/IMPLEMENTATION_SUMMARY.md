# User Account System Implementation Summary

## Overview

A comprehensive user account management system with role-based access control, approval workflow, activity logging, and profile management.

## What Was Implemented

### Backend (Laravel)

1. **Database**
   - `users` table with role, approval status, hired_date
   - `activity_log` table (via Spatie)
   - Two-factor authentication fields

2. **Model (`app/Models/User.php`)**
   - Role-based access control methods
   - Permission checking methods
   - Relationships (schedules, attendance, leaves, notifications)
   - Activity logging trait
   - Two-factor authentication support

3. **Controllers**
   - `AccountController.php` - User CRUD and approval
   - `ActivityLogController.php` - Audit trail viewing
   - `Settings/ProfileController.php` - Profile management
   - `Settings/PasswordController.php` - Password management

4. **Middleware**
   - `CheckApproved.php` - Block unapproved users
   - `CheckPermission.php` - Permission verification
   - `CheckRole.php` - Role verification

5. **Services**
   - `PermissionService.php` - Authorization logic

### Frontend (React + TypeScript)

1. **Account Pages** (`resources/js/pages/Account/`)
   - `Index.tsx` - User list with search
   - `Create.tsx` - Add new user
   - `Edit.tsx` - Modify user

2. **Activity Log Pages** (`resources/js/pages/Admin/ActivityLogs/`)
   - `Index.tsx` - Audit trail viewer

3. **Settings Pages** (`resources/js/pages/settings/`)
   - `profile.tsx` - Profile editor
   - `password.tsx` - Password change
   - `appearance.tsx` - UI preferences

4. **Auth Pages** (`resources/js/pages/auth/`)
   - Login, Register, Password Reset
   - Two-Factor Authentication
   - Email Verification

## Key Features

### 1. User Management

| Feature | Description |
|---------|-------------|
| Create | Add new users with role assignment |
| Edit | Modify user details and role |
| Delete | Remove user accounts |
| Search | Find users by name/email |
| List | View all users with pagination |

### 2. Role System

| Role | Description |
|------|-------------|
| super_admin | Full system access |
| admin | Administrative access |
| team_lead | Supervisor access |
| agent | Basic user access |
| hr | HR-focused access |
| it | IT-focused access |
| utility | Minimal access |

### 3. Approval Workflow

```
User Registers → Status: Pending
→ Admin Reviews → Approve/Reject
→ If Approved: Full Access
→ If Rejected: Blocked
```

### 4. Activity Logging

All model changes are logged:
- Created events
- Updated events
- Deleted events
- Before/after values
- User attribution

### 5. Two-Factor Authentication

- TOTP-based (Time-based One-Time Password)
- Recovery codes
- Configurable per user
- Via Laravel Fortify

### 6. Profile Management

- Update name and email
- Change password
- Configure 2FA
- Set time format preference

## Database Schema

```sql
users (
    id,
    first_name, middle_name, last_name,
    email, email_verified_at,
    password,
    role,                      -- enum: super_admin, admin, etc.
    time_format,
    hired_date,
    is_approved,               -- boolean
    approved_at,
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
GET    /accounts              - List users
GET    /accounts/create       - Create form
POST   /accounts              - Store user
GET    /accounts/{id}/edit    - Edit form
PUT    /accounts/{id}         - Update user
DELETE /accounts/{id}         - Delete user
POST   /accounts/{id}/approve - Approve user
POST   /accounts/{id}/unapprove - Unapprove user

# Activity Logs
GET    /activity-logs         - View logs

# Settings
GET    /settings              - Settings page
PUT    /settings/profile      - Update profile
PUT    /settings/password     - Update password

# Authentication
GET    /login                 - Login page
POST   /login                 - Authenticate
POST   /logout                - Logout
GET    /register              - Register page
POST   /register              - Create account
GET    /forgot-password       - Reset request
POST   /forgot-password       - Send reset email
GET    /reset-password/{token} - Reset form
POST   /reset-password        - Update password
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
→ Account created (is_approved = false)
→ User sees "Pending Approval" page
→ Admin receives notification
→ Admin approves user
→ User gains full access
```

### 2. Activity Logging Flow

```
Model change occurs
→ Spatie Activity Log intercepts
→ Records: who, what, when, before/after
→ Stored in activity_log table
→ Viewable in /activity-logs
```

### 3. Permission Check Flow

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
│   │       ├── ProfileController.php
│   │       └── PasswordController.php
│   └── Middleware/
│       ├── CheckApproved.php
│       ├── CheckPermission.php
│       └── CheckRole.php
├── Providers/
│   └── FortifyServiceProvider.php
└── Services/
    └── PermissionService.php
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
│   ├── profile.tsx
│   ├── password.tsx
│   └── appearance.tsx
└── auth/
    ├── login.tsx
    ├── register.tsx
    ├── forgot-password.tsx
    ├── reset-password.tsx
    ├── verify-email.tsx
    ├── confirm-password.tsx
    └── two-factor-challenge.tsx
```

### Configuration
```
config/
├── fortify.php           - Auth features
├── permissions.php       - Roles & permissions
└── activitylog.php       - Logging settings
```

## User Relationships

```php
// One-to-Many
$user->employeeSchedules  // Work schedules
$user->attendances        // Attendance records
$user->attendancePoints   // Violation points
$user->leaveCredits       // Leave balances
$user->leaveRequests      // Leave submissions
$user->notifications      // User notifications

// Through Relationships
$user->reviewedLeaveRequests  // Leaves reviewed by user
```

## Related Documentation

- [Authorization System](../authorization/README.md) - RBAC details
- [Notification System](../NOTIFICATION_SYSTEM.md) - Notifications
- [Leave Management](../leave/README.md) - Leave system

---

**Implementation Date:** November 2025  
**Status:** ✅ Complete and Production Ready
