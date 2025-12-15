# Leave Management System - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Laravel application running
- Database migrated
- Users with `hired_date` set
- Cron job configured for scheduler

### 1. Set Up Employee Hire Dates

Before employees can use leave credits, they need hire dates:

```php
php artisan tinker

// Set hire date for user
$user = User::find(1);
$user->update(['hired_date' => '2025-01-15']);
```

### 2. Backfill Leave Credits

For existing employees, backfill their credits:

```bash
# All employees
php artisan leave:backfill-credits

# Specific employee
php artisan leave:backfill-credits --user=123
```

### 3. Submit a Leave Request

1. Navigate to `/form-requests/leave-requests/create`
2. View your credit balance
3. Select leave type
4. Choose start and end dates
5. Enter reason (min 10 characters)
6. Select team lead email
7. Select campaign/department
8. Submit

### 4. Approve/Deny Leave (HR/Admin)

1. Navigate to `/form-requests/leave-requests`
2. Click on pending request
3. Review:
   - Employee details
   - Leave dates and days
   - Credits balance
   - Attendance points
4. Click "Approve" or "Deny"
5. If denying, enter reason (min 10 chars)

## 🔧 Common Tasks

### Check Employee's Credits

```php
php artisan tinker

$user = User::find(1);
$service = app(\App\Services\LeaveCreditService::class);

echo "Balance: " . $service->getBalance($user, 2025);
echo "Eligible: " . ($service->isEligible($user) ? 'Yes' : 'No');
echo "Points: " . $service->getAttendancePoints($user);
```

### Run Monthly Accrual

```bash
# Manual run
php artisan leave:accrue-credits

# For specific month
php artisan leave:accrue-credits --year=2025 --month=11
```

### Check Year-End Credits

```bash
# Run in November/December
php artisan leave:year-end-reminder
```

### Cancel a Leave Request

As employee:
1. View your leave request
2. Click "Cancel"
3. Credits automatically restored (if approved)

## 📋 Quick Reference

### Leave Types

| Type | Credits | Notice | Points Check |
|------|---------|--------|--------------|
| VL (Vacation) | ✅ Yes | 2 weeks | ✅ ≤6 points |
| SL (Sick) | ✅ Yes | None | ❌ |
| BL (Birthday) | ✅ Yes | 2 weeks | ✅ ≤6 points |
| SPL | ❌ No | None | ❌ |
| LOA | ❌ No | None | ❌ |
| LDV | ❌ No | None | ❌ |
| UPTO | ❌ No | None | ❌ |

### Monthly Credit Rates

| Role | Credits/Month |
|------|---------------|
| Super Admin | 1.5 |
| Admin | 1.5 |
| Team Lead | 1.5 |
| HR | 1.5 |
| Agent | 1.25 |
| IT | 1.25 |
| Utility | 1.25 |

### Status Colors

| Status | Color | Meaning |
|--------|-------|---------|
| Pending | Yellow | Awaiting review |
| Approved | Green | Approved, credits deducted |
| Denied | Red | Rejected |
| Cancelled | Gray | Cancelled by employee |

### Key URLs

| Page | URL |
|------|-----|
| Leave List | `/form-requests/leave-requests` |
| Submit Leave | `/form-requests/leave-requests/create` |
| View Request | `/form-requests/leave-requests/{id}` |

## 🧪 Testing

### Test Credit Calculation

```php
php artisan tinker

$user = User::find(1);
$service = app(\App\Services\LeaveCreditService::class);

// Test monthly rate
$service->getMonthlyRate($user); // 1.25 or 1.5

// Test eligibility (6 months from hire)
$service->isEligible($user); // true/false

// Test full validation
$service->validateLeaveRequest($user, [
    'leave_type' => 'VL',
    'start_date' => now()->addWeeks(3)->toDateString(),
    'end_date' => now()->addWeeks(3)->addDays(2)->toDateString(),
    'days_requested' => 3,
]);
```

### Manual Testing Checklist

- [ ] Employee with <6 months → Shows not eligible
- [ ] Submit VL with >6 points → Auto-rejected
- [ ] Submit VL <2 weeks notice → Validation error
- [ ] Submit with insufficient credits → Error
- [ ] Approve leave → Credits deducted
- [ ] Cancel approved → Credits restored
- [ ] Deny leave → Reason required
- [ ] Monthly accrual runs → Credits added

## 🐛 Troubleshooting

### "Not Eligible" Message

**Cause:** Less than 6 months from hire date

**Check:**
```php
$user->hired_date; // Must be > 6 months ago
Carbon::parse($user->hired_date)->diffInMonths(now());
```

**Fix:** Update hire date or wait for eligibility

### No Credits Showing

**Cause:** Credits not backfilled

**Fix:**
```bash
php artisan leave:backfill-credits --user=123
```

### "Insufficient Credits" Error

**Cause:** Requesting more days than available

**Check:**
```php
$service->getBalance($user, date('Y'));
```

**Fix:** Request fewer days or different leave type

### Auto-Rejected Leave

**Cause:** Failed validation rules

**Check:** `auto_rejection_reason` field on request

Common reasons:
- Too many attendance points (>6)
- Less than 2 weeks notice
- Recent absence in 30 days

### Credits Not Accruing

**Cause:** Cron not running or hire date missing

**Check:**
```bash
# Verify scheduler
php artisan schedule:list

# Check hire date
$user->hired_date;
```

**Fix:**
```bash
# Set up cron
* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1

# Set hire date
$user->update(['hired_date' => '2025-01-01']);
```

## 📊 Credit Balance Widget

The create form shows:
```
┌─────────────────────────────┐
│ Leave Credits Balance       │
├─────────────────────────────┤
│ Available:     12.5 days    │
│ This Request:  - 3.0 days   │
│ Remaining:      9.5 days    │
└─────────────────────────────┘
```

## ⚠️ Important Notes

### Annual Reset
- Credits expire December 31
- They do NOT carry over to next year
- Run `leave:year-end-reminder` to notify employees

### Cross-Year Requests
- Request in Dec 2025 for Jan 2026
- Deducts from 2025 credits
- 2026 starts with 0 balance

### Attendance Points Rule
- VL/BL requires ≤6 points
- Checked at submission time
- Points at request stored for audit

## 🔗 Related Documentation

- [Leave Credits Accrual](LEAVE_CREDITS_ACCRUAL.md)
- [Leave Request Validation](LEAVE_REQUEST_VALIDATION.md)
- [Leave Approval Workflow](LEAVE_APPROVAL_WORKFLOW.md)
- [Attendance Points](../attendance/POINT_EXPIRATION_RULES.md)

---

**Need help?** Check the [full documentation](README.md) or [implementation details](IMPLEMENTATION_SUMMARY.md).

*Last updated: December 15, 2025*
