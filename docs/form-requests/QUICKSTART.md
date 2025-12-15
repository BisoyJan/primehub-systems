# Form Requests System - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Laravel application running
- Database migrated
- Mail configured (for notifications)
- Users with `hired_date` set (for leave eligibility)

## 📋 Leave Requests

### 1. Submit Leave Request

1. Navigate to `/form-requests/leave-requests/create`
2. Select leave type
3. Choose dates
4. View available credits
5. Enter reason
6. Select team lead and campaign
7. Submit

### 2. Approve/Deny Leave

**Team Lead (For Agent Leave Requests):**
1. Navigate to `/form-requests/leave-requests`
2. Click on pending request from your campaign
3. Review details
4. Click "Approve (TL)" or "Deny (TL)"
5. If denying, enter reason

**HR/Admin (All Leave Requests):**
1. Navigate to `/form-requests/leave-requests`
2. Click on pending request
3. Review details
4. Click "Approve" or "Deny"
5. If denying, enter reason

### 3. Cancel Leave Request

1. View your leave request
2. Click "Cancel" (if pending or future approved)
3. Credits automatically restored

### Leave Types Quick Reference

| Type | Credits Used | Notice Required |
|------|--------------|-----------------|
| VL (Vacation) | Yes | 2 weeks |
| SL (Sick) | Yes | None |
| BL (Birthday) | Yes | 2 weeks |
| SPL (Solo Parent) | No | None |
| LOA (Leave of Absence) | No | None |
| LDV (Doctor Visit) | No | None |
| UPTO (Unpaid) | No | None |

## 🔧 IT Concerns

### 1. Submit IT Concern

1. Navigate to `/form-requests/it-concerns/create`
2. Select site and station
3. Choose category:
   - Hardware
   - Software
   - Network/Connectivity
   - Other
4. Select priority
5. Describe the issue
6. Submit

### 2. Resolve IT Concern (IT Staff)

1. Navigate to `/form-requests/it-concerns`
2. Click on pending concern
3. Click "Assign" to take ownership
4. Work on the issue
5. Click "Resolve" and add resolution notes

### Priority Levels

| Priority | Response Time | Color |
|----------|---------------|-------|
| Low | When available | Gray |
| Medium | Same day | Blue |
| High | Within hours | Orange |
| Urgent | Immediate | Red |

## 💊 Medication Requests

### 1. Submit Medication Request

1. Navigate to `/form-requests/medication-requests/create`
2. Select medication type:
   - Declogen
   - Biogesic
   - Mefenamic Acid
   - Kremil-S
   - Cetirizine
   - Saridon
   - Diatabs
3. Enter reason
4. Describe symptoms
5. Agree to medication policy
6. Submit

### 2. Process Medication (HR/Admin)

1. Navigate to `/form-requests/medication-requests`
2. Review pending request
3. Approve or Reject
4. Mark as Dispensed when given

## 📊 Retention Policies

### Configure Data Retention

1. Navigate to `/form-requests/retention-policies`
2. Create new policy:
   - Enter policy name
   - Set retention months
   - Select scope (global or site-specific)
   - Select form type (all, leave_request, it_concern, medication_request)
   - Set priority (higher = takes precedence)
   - Add description
3. Activate policy
4. Old records auto-deleted per schedule

## 🔧 Common Tasks

### Export Leave Credits

1. Navigate to `/form-requests/leave-requests/credits`
2. Click "Export All Credits"
3. Background job generates Excel file
4. Download when ready

### Check Leave Credits

```php
php artisan tinker

$user = User::find(1);
$credits = \App\Models\LeaveCredit::where('user_id', $user->id)
    ->whereYear('year', date('Y'))
    ->sum('credits_balance');
```

### Backfill Leave Credits

```bash
# All employees
php artisan leave:backfill-credits

# Specific employee
php artisan leave:backfill-credits --user=123
```

### View Year-End Credits

```bash
php artisan leave:year-end-reminder
```

## 📋 Quick Reference

### Key URLs

| Page | URL |
|------|-----|
| Leave Requests | `/form-requests/leave-requests` |
| Create Leave | `/form-requests/leave-requests/create` |
| IT Concerns | `/form-requests/it-concerns` |
| Create IT Concern | `/form-requests/it-concerns/create` |
| Medication Requests | `/form-requests/medication-requests` |
| Create Medication | `/form-requests/medication-requests/create` |
| Retention Policies | `/form-requests/retention-policies` |

### Status Colors

| Status | Color | Description |
|--------|-------|-------------|
| Pending | Yellow | Awaiting action |
| Approved | Green | Request approved |
| Denied | Red | Request rejected |
| Cancelled | Gray | Cancelled by user |
| In Progress | Blue | Being worked on |
| Resolved | Green | Issue fixed |

## 🧪 Testing

### Test Leave Submission

```php
php artisan tinker

// Create test leave request
\App\Models\LeaveRequest::create([
    'user_id' => 1,
    'leave_type' => 'VL',
    'start_date' => now()->addWeeks(3),
    'end_date' => now()->addWeeks(3)->addDays(2),
    'days_requested' => 3,
    'reason' => 'Vacation',
    'team_lead_email' => 'lead@example.com',
    'campaign_department' => 'IT',
    'status' => 'pending',
    'attendance_points_at_request' => 0,
]);
```

### Manual Testing Checklist

- [ ] Submit leave request → Created as pending
- [ ] Approve leave → Credits deducted
- [ ] Deny leave → Reason shown
- [ ] Cancel leave → Credits restored
- [ ] Submit IT concern → Shows in queue
- [ ] Resolve IT concern → Resolution saved
- [ ] Submit medication → Policy agreement required
- [ ] Approve medication → Status updated

## 🐛 Troubleshooting

### "Not Eligible" for Leave

**Cause:** Less than 6 months employed

**Check:**
```php
$user->hired_date; // Should be > 6 months ago
```

### "Insufficient Credits"

**Cause:** Not enough leave balance

**Fix:**
```bash
php artisan leave:backfill-credits --user=123
```

### IT Concern Not Visible

**Cause:** Wrong site filter

**Fix:** Clear site filter or check user's site access

### Medication Policy Error

**Cause:** Didn't agree to policy

**Fix:** Must check policy agreement checkbox

### Notifications Not Sending

**Cause:** Mail not configured

**Check:** Verify `.env` mail settings

## 📊 Dashboard Widgets

Form requests appear on dashboard:

| Widget | Description |
|--------|-------------|
| Pending Leave | Leave awaiting approval |
| IT Concerns | Open IT issues |
| Medication Pending | Awaiting approval |

## 🔗 Related Documentation

- [Leave Management](../leave/README.md) - Complete leave docs
- [Notification System](../NOTIFICATION_SYSTEM.md) - Notifications
- [Authorization](../authorization/README.md) - Permissions

---

**Need help?** Check the [full documentation](README.md) or [implementation details](IMPLEMENTATION_SUMMARY.md).

*Last updated: December 15, 2025*
