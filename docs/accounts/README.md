# User Account & Activity Management

Comprehensive documentation for user account management, roles, approval system, and activity logging.

---

## 🚀 Quick Links

- **[QUICKSTART.md](QUICKSTART.md)** - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - Technical overview

---

## 📄 Documents

### [ACCOUNTS.md](ACCOUNTS.md)
**User Account Management**

Complete documentation for user account CRUD operations and management.

### [ROLES_PERMISSIONS.md](../authorization/README.md)
**Role-Based Access Control**

Documentation for the RBAC system with 7 roles and 60+ permissions.

### [ACTIVITY_LOGS.md](ACTIVITY_LOGS.md)
**Activity Logging System**

Documentation for system-wide activity logging and audit trails.

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
├── approved_at (timestamp, nullable)
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
- **Profile Management**: Users can update their profile
- **Password Management**: Secure password change
- **Two-Factor Auth**: Optional 2FA via Laravel Fortify

### Routes
```
GET    /accounts                   - List all accounts
GET    /accounts/create            - Create form
POST   /accounts                   - Store new account
GET    /accounts/{id}/edit         - Edit form
PUT    /accounts/{id}              - Update account
DELETE /accounts/{id}              - Delete account
POST   /accounts/{id}/approve      - Approve account
POST   /accounts/{id}/unapprove    - Unapprove account
```

### Permissions
| Permission | Description |
|------------|-------------|
| `accounts.view` | View user accounts |
| `accounts.create` | Create user accounts |
| `accounts.edit` | Edit user accounts |
| `accounts.delete` | Delete user accounts |

### Approval Workflow
1. User registers via public registration form
2. User sees "Pending Approval" page
3. Admin/Super Admin reviews and approves
4. User gains access to system

```
Registration → Pending Approval → Admin Review → Approved
```

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
- **Account Settings**: Update profile information
- **Password Change**: Change account password
- **Two-Factor Authentication**: Enable/disable 2FA

### Routes
```
GET    /settings                   - Settings page
PUT    /settings/profile           - Update profile
PUT    /settings/password          - Update password
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
- `approved` - Require admin approval

---

## 🎓 Key Files

### Models
- `app/Models/User.php` - User model with relationships

### Controllers
- `app/Http/Controllers/AccountController.php` - Account management
- `app/Http/Controllers/ActivityLogController.php` - Activity logs
- `app/Http/Controllers/Settings/ProfileController.php` - Profile settings
- `app/Http/Controllers/Settings/PasswordController.php` - Password settings

### Middleware
- `app/Http/Middleware/CheckApproved.php` - Check user approval

### Services
- `app/Services/PermissionService.php` - Permission checking

### Frontend Pages
- `resources/js/pages/Account/` - Account management pages
- `resources/js/pages/Admin/ActivityLogs/` - Activity log viewer
- `resources/js/pages/settings/` - Settings pages
- `resources/js/pages/auth/` - Authentication pages

---

## 📱 User Relationships

### One-to-Many
- `employeeSchedules` - User's work schedules
- `attendances` - Attendance records
- `attendancePoints` - Attendance violation points
- `leaveCredits` - Leave credit balances
- `leaveRequests` - Leave request submissions
- `notifications` - User notifications

### Through User
- `reviewedLeaveRequests` - Leave requests reviewed by user

---

## 🔗 Related Documentation

- [Authorization](../authorization/README.md) - RBAC system
- [Notification System](../NOTIFICATION_SYSTEM.md) - Notifications
- [Attendance](../attendance/README.md) - Attendance tracking

---

*Last updated: November 28, 2025*
