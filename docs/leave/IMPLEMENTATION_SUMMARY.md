# Leave Management System Implementation Summary

## Overview

A complete leave management system with automatic credit accrual, multiple leave types, validation rules, approval workflows, and credit tracking.

## What Was Implemented

### Backend (Laravel)

1. **Database Migrations**
   - `leave_credits` - Monthly credit tracking per user
   - `leave_requests` - Leave request submissions

2. **Models**
   - `LeaveCredit.php` - Credit tracking with scopes
   - `LeaveRequest.php` - Request with validation helpers

3. **Controllers**
   - `LeaveRequestController.php` - Complete CRUD and workflow

4. **Services**
   - `LeaveCreditService.php` - Core business logic
     - Monthly accrual calculation
     - Eligibility checking
     - Credit deduction/restoration
     - Validation rules

5. **Console Commands**
   - `AccrueLeaveCredits.php` - Monthly automatic accrual
   - `BackfillLeaveCredits.php` - Retroactive credit calculation
   - `YearEndCreditsReminder.php` - Expiring credits alert

6. **Mail Classes**
   - `LeaveRequestSubmitted.php` - Notify HR/Admin
   - `LeaveRequestStatusUpdated.php` - Notify employee

### Frontend (React + TypeScript)

1. **Leave Pages** (`resources/js/pages/FormRequest/Leave/`)
   - `Index.tsx` - Leave list with filters
   - `Create.tsx` - Submit with real-time validation
   - `Show.tsx` - View and approve/deny

2. **Features**
   - Live credit balance display
   - Days calculation
   - Eligibility warnings
   - Attendance points check

## Key Features

### 1. Leave Types

| Type | Code | Credits | Notice |
|------|------|---------|--------|
| Vacation Leave | VL | ✅ Deduct | 2 weeks |
| Sick Leave | SL | ✅ Deduct | None |
| Birthday Leave | BL | ✅ Deduct | 2 weeks |
| Solo Parent Leave | SPL | ❌ None | None |
| Leave of Absence | LOA | ❌ None | None |
| Doctor Visit | LDV | ❌ None | None |
| Unpaid Time Off | UPTO | ❌ None | None |

### 2. Credit Accrual

**Monthly Rates:**
- Managers (Super Admin, Admin, Team Lead, HR): **1.5 credits/month**
- Employees (Agent, IT, Utility): **1.25 credits/month**

**Rules:**
- Accrues on last day of each month
- 6-month eligibility from hire date
- Credits don't carry over (annual reset Dec 31)
- Automatic backfilling for existing employees

### 3. Validation System

**Three-Layer Validation:**
1. **Frontend** - Real-time warnings
2. **Backend Form** - Request validation
3. **Service Layer** - Business logic

**Validation Rules:**
| Rule | Applies To |
|------|------------|
| ≤6 attendance points | VL, BL |
| 2 weeks advance notice | VL, BL |
| No absence in 30 days | VL, BL |
| Sufficient credits | VL, SL, BL |
| 6-month eligibility | All credited types |

### 4. Approval Workflow

```
Submit → Pending
→ Auto-Reject (if fails validation)
→ HR/Admin Review
→ Approve: Credits deducted
→ Deny: Reason required
→ Cancel: Credits restored
```

## Database Schema

```sql
leave_credits (
    id, user_id,
    credits_earned,   -- Monthly accrual (1.25 or 1.5)
    credits_used,     -- Deducted when approved
    credits_balance,  -- Current balance
    year, month,      -- Period
    accrued_at,       -- When accrued
    timestamps,
    UNIQUE(user_id, year, month)
)

leave_requests (
    id, user_id,
    leave_type,       -- enum
    start_date, end_date,
    days_requested,
    reason,
    team_lead_email,
    campaign_department,
    medical_cert_submitted,
    status,           -- pending, approved, denied, cancelled
    reviewed_by, reviewed_at, review_notes,
    credits_deducted, credits_year,
    attendance_points_at_request,
    auto_rejected, auto_rejection_reason,
    timestamps
)
```

## Routes

```
GET    /form-requests/leave-requests           - List
GET    /form-requests/leave-requests/create    - Create form
POST   /form-requests/leave-requests           - Submit
GET    /form-requests/leave-requests/{id}      - View
POST   /form-requests/leave-requests/{id}/approve - Approve
POST   /form-requests/leave-requests/{id}/deny    - Deny
POST   /form-requests/leave-requests/{id}/cancel  - Cancel
```

## Permissions

| Permission | Description |
|------------|-------------|
| `leave.view` | View own leave requests |
| `leave.create` | Create leave requests |
| `leave.approve` | Approve leave requests |
| `leave.deny` | Deny leave requests |
| `leave.cancel` | Cancel leave requests |
| `leave.view_all` | View all leave requests |

## Console Commands

```bash
# Monthly accrual (scheduled last day of month 11 PM)
php artisan leave:accrue-credits

# Backfill for all employees
php artisan leave:backfill-credits

# Backfill for specific user
php artisan leave:backfill-credits --user=123

# Year-end reminder (run in Nov/Dec)
php artisan leave:year-end-reminder
```

## Service Methods

```php
$service = app(LeaveCreditService::class);

// Get monthly accrual rate
$rate = $service->getMonthlyRate($user); // 1.25 or 1.5

// Check eligibility (6 months)
$eligible = $service->isEligible($user);

// Get current balance
$balance = $service->getBalance($user, 2025);

// Accrue monthly credits
$service->accrueMonthly($user);

// Backfill all missing months
$service->backfillCredits($user);

// Deduct on approval
$service->deductCredits($leaveRequest);

// Restore on cancellation
$service->restoreCredits($leaveRequest);

// Get attendance points
$points = $service->getAttendancePoints($user);

// Check recent absence
$hasAbsence = $service->hasRecentAbsence($user);

// Full validation
$result = $service->validateLeaveRequest($user, $data);
```

## Files Reference

### Backend
```
app/
├── Models/
│   ├── LeaveRequest.php
│   └── LeaveCredit.php
├── Http/Controllers/
│   └── LeaveRequestController.php
├── Services/
│   └── LeaveCreditService.php
├── Console/Commands/
│   ├── AccrueLeaveCredits.php
│   ├── BackfillLeaveCredits.php
│   └── YearEndCreditsReminder.php
└── Mail/
    ├── LeaveRequestSubmitted.php
    └── LeaveRequestStatusUpdated.php
```

### Frontend
```
resources/js/pages/FormRequest/Leave/
├── Index.tsx    - Leave list
├── Create.tsx   - Submit form
└── Show.tsx     - View/approve
```

## Scheduled Tasks

```php
// Kernel.php
$schedule->command('leave:accrue-credits')
    ->monthlyOn(date('t'), '23:00');
```

## Integration Points

### Attendance System
- Points check for VL/BL eligibility
- Recent absence detection

### Notification System
- Notify HR/Admin on submission
- Notify employee on status change

### Activity Logging
- All changes logged via Spatie

### Email Notifications
- Submission confirmation
- Status update emails

## Related Documentation

- [Attendance Points](../attendance/POINT_EXPIRATION_RULES.md)
- [Notification System](../NOTIFICATION_SYSTEM.md)
- [Form Requests Overview](../form-requests/README.md)

---

**Implementation Date:** November 2025  
**Status:** ✅ Complete and Production Ready
