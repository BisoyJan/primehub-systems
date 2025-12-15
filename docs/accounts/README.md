# User Account & Activity Management

Comprehensive documentation for user account management, roles, approval system, and activity logging.

---

## 🚀 Quick Links

- **[QUICKSTART.md](QUICKSTART.md)** - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - Technical overview

---

## 📄 Related Documents

### [ROLES_PERMISSIONS](../authorization/README.md)
**Role-Based Access Control**

Documentation for the RBAC system with 7 roles and 60+ permissions.

### [Activity Logs](../api/ROUTES.md#activity-logs)
**Activity Logging System**

System-wide activity logging using Spatie Activity Log for audit trails.

---

## 🏗️ Architecture

### Database Schema

```
users
├── id
├── first_name
├── middle_name (nullable)
├── last_name
├── email (unique)
├── email_verified_at (timestamp, nullable)
├── password (hashed)
├── role (enum)
├── time_format (string)
├── hired_date (date, nullable)
├── is_approved (boolean, default: false)
├── is_active (boolean, default: true)
├── approved_at (timestamp, nullable)
├── deleted_at (timestamp, nullable)         -- Soft delete marker
├── deleted_by (foreignId, nullable)         -- User who initiated deletion
├── deletion_confirmed_at (timestamp, nullable) -- When deletion was confirmed
├── deletion_confirmed_by (foreignId, nullable) -- User who confirmed deletion
├── remember_token
├── two_factor_secret (nullable)
├── two_factor_recovery_codes (nullable)
├── two_factor_confirmed_at (nullable)
└── timestamps
```

### User Roles
| Role | Description |
|------|-------------|
| `super_admin` | Super Admin - Full system access |
| `admin` | Admin - Administrative access |
| `team_lead` | Team Lead - Supervisor access |
| `agent` | Agent - Basic user access |
| `hr` | HR - HR-focused access |
| `it` | IT - IT-focused access |
| `utility` | Utility - Minimal access |

---

## 👤 Account Management

### Features
- **CRUD Operations**: Create, view, edit, delete user accounts
- **Role Assignment**: Assign roles to users
- **Approval System**: New users require admin approval
- **Two-Stage Deletion**: Delete → Confirm Delete workflow
- **Account Reactivation**: Self-service reactivation for pending-deletion accounts
- **Employee Status**: Active/inactive status separate from approval
- **Bulk Operations**: Bulk approve/unapprove multiple accounts
- **Profile Management**: Users can update their profile
- **Password Management**: Secure password change
- **Two-Factor Auth**: Optional 2FA via Laravel Fortify
- **Email Notifications**: Access revoked notification emails

### Routes
```
# Account Management
GET    /accounts                      - List all accounts
GET    /accounts/create               - Create form
POST   /accounts                      - Store new account
GET    /accounts/{id}/edit            - Edit form
PUT    /accounts/{id}                 - Update account
DELETE /accounts/{id}                 - Soft delete (pending deletion)
POST   /accounts/{id}/approve         - Approve account
POST   /accounts/{id}/unapprove       - Unapprove account (with optional email)
POST   /accounts/{id}/toggle-active   - Toggle employee active status
POST   /accounts/bulk-approve         - Bulk approve accounts
POST   /accounts/bulk-unapprove       - Bulk unapprove accounts
POST   /accounts/{id}/confirm-delete  - Confirm account deletion
POST   /accounts/{id}/restore         - Restore deleted account
DELETE /accounts/{id}/force-delete    - Permanently delete account

# Account Reactivation (Guest Routes)
GET    /account/reactivate            - Reactivation page
POST   /account/reactivate            - Process reactivation
```

### Permissions
| Permission | Description |
|------------|-------------|
| `accounts.view` | View user accounts |
| `accounts.create` | Create user accounts |
| `accounts.edit` | Edit, approve, unapprove, toggle active, restore |
| `accounts.delete` | Delete and force-delete accounts |

### Account Lifecycle

```
┌─────────────────────────────────────────────────────────────────┐
│                     ACCOUNT LIFECYCLE                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   Registration ──► Pending Approval ──► Approved                │
│                          │                  │                    │
│                          │                  ▼                    │
│                          │             Unapproved               │
│                          │                  │                    │
│                          ▼                  ▼                    │
│                   ┌─────────────────────────────┐               │
│                   │     Pending Deletion        │               │
│                   │   (User can reactivate)     │               │
│                   └─────────────┬───────────────┘               │
│                                 │                                │
│            ┌────────────────────┼────────────────────┐          │
│            ▼                    ▼                    ▼          │
│     Self-Reactivate      Admin Restore      Confirm Delete      │
│     (via /account/       (Restore)         (Permanent)          │
│      reactivate)                                                 │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Account Statuses

| Status | Badge | `is_approved` | `deleted_at` | `deletion_confirmed_at` |
|--------|-------|---------------|--------------|-------------------------|
| Approved | Green | `true` | `null` | `null` |
| Pending Approval | Yellow | `false` | `null` | `null` |
| Pending Deletion | Orange | - | Set | `null` |
| Deleted (Confirmed) | Red | - | Set | Set |

### Employee Active Status

Separate from approval status:
- **Active** (`is_active = true`): Employee can work normally
- **Inactive** (`is_active = false`): Employee schedules deactivated
- Toggle via `/accounts/{id}/toggle-active`
- Auto-deactivated when unapproved or deletion confirmed

---

## 📊 Activity Logging

### Overview
The system uses **Spatie Activity Log** for comprehensive audit trails.

### Features
- **Model Changes**: Track all model create/update/delete events
- **User Attribution**: Log which user made changes
- **Property Tracking**: Store before/after values
- **Batch Operations**: Group related changes
- **Filtering**: Filter by model, user, event type

### Routes
```
GET    /activity-logs              - View activity logs
```

### Permissions
| Permission | Description |
|------------|-------------|
| `activity_logs.view` | View activity logs |

### Logged Models
All major models include activity logging:
- User accounts
- PC specifications
- Stations
- Hardware specs
- Leave requests
- Attendance records
- IT concerns
- Medication requests
- And more...

### Configuration
```php
// config/activitylog.php
return [
    'enabled' => true,
    'delete_records_older_than_days' => 365,
    'default_log_name' => 'default',
];
```

### Usage in Models
```php
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
```

---

## ⚙️ Settings

### User Settings
- **Account Settings**: Update profile information (name, email)
- **Password Change**: Change account password
- **Preferences**: Time format and other preferences
- **Appearance**: Theme settings (light/dark mode)
- **Two-Factor Authentication**: Enable/disable 2FA

### Routes
```
GET    /settings                   - Redirect to /settings/account
GET    /settings/account           - Account/profile settings
PATCH  /settings/account           - Update profile
GET    /settings/password          - Password settings
PUT    /settings/password          - Update password
GET    /settings/preferences       - User preferences
PATCH  /settings/preferences       - Update preferences
GET    /settings/appearance        - Appearance settings
GET    /settings/two-factor        - Two-factor authentication
```

### Permissions
| Permission | Description |
|------------|-------------|
| `settings.view` | Access settings |
| `settings.account` | Manage account settings |
| `settings.password` | Change password |

---

## 🔐 Authentication

### Laravel Fortify Features
- **Registration**: Public user registration
- **Login**: Email/password authentication
- **Email Verification**: Verify email addresses
- **Password Reset**: Reset forgotten passwords
- **Two-Factor Authentication**: TOTP-based 2FA

### Configuration
```php
// config/fortify.php
return [
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
```

### Middleware
- `auth` - Require authentication
- `verified` - Require email verification
- `approved` - Require admin approval (via `EnsureUserIsApproved.php`)

---

## 📧 Email Notifications

### Access Revoked Email
When unapproving a user, an optional email notification can be sent:

```php
// EmployeeAccessRevoked Mailable
// Sent when admin unapproves a user account
Mail::to($user)->send(new EmployeeAccessRevoked($user));
```

---

## 🎓 Key Files

### Models
- `app/Models/User.php` - User model with relationships and soft delete methods

### Controllers
- `app/Http/Controllers/AccountController.php` - Account management (CRUD, approval, deletion)
- `app/Http/Controllers/ActivityLogController.php` - Activity logs
- `app/Http/Controllers/Settings/AccountController.php` - Profile settings
- `app/Http/Controllers/Settings/PasswordController.php` - Password settings
- `app/Http/Controllers/Settings/PreferencesController.php` - User preferences

### Policies
- `app/Policies/UserPolicy.php` - User authorization rules

### Middleware
- `app/Http/Middleware/EnsureUserIsApproved.php` - Check user approval

### Mail
- `app/Mail/EmployeeAccessRevoked.php` - Access revoked notification

### Services
- `app/Services/PermissionService.php` - Permission checking
- `app/Services/NotificationService.php` - In-app notifications

### Frontend Pages
- `resources/js/pages/Account/` - Account management pages (Index, Create, Edit)
- `resources/js/pages/Admin/ActivityLogs/` - Activity log viewer
- `resources/js/pages/settings/` - Settings pages (account, password, preferences, appearance, two-factor)
- `resources/js/pages/auth/` - Authentication pages (including pending-approval, account-deleted)

---

## 📱 User Relationships

### One-to-Many
- `employeeSchedules` - User's work schedules
- `activeSchedule` - Currently active schedule
- `attendances` - Attendance records
- `attendancePoints` - Attendance violation points
- `leaveCredits` - Leave credit balances
- `leaveRequests` - Leave request submissions
- `notifications` - User notifications

### Through User
- `reviewedLeaveRequests` - Leave requests reviewed by user
- `deletedBy` - User who deleted this account
- `deletionConfirmedBy` - User who confirmed deletion

### User Model Methods
```php
// Soft delete status checks
$user->isSoftDeleted();        // Is deleted_at set?
$user->isDeletionPending();    // Deleted but not confirmed
$user->isDeletionConfirmed();  // Deletion confirmed

// Scopes
User::active()->get();          // Only active employees
User::onlyDeleted()->get();     // Only soft-deleted users
User::pendingDeletion()->get(); // Only pending deletion
```

---

## 🔗 Related Documentation

- [Authorization](../authorization/README.md) - RBAC system
- [Notification System](../NOTIFICATION_SYSTEM.md) - Notifications
- [Attendance](../attendance/README.md) - Attendance tracking
- [API Routes](../api/ROUTES.md) - Complete routes reference

---

*Last updated: December 2025*
