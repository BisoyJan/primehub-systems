# Form Requests System Implementation Summary

## Overview

A comprehensive form request system for employee submissions including Leave Requests, IT Concerns, and Medication Requests, with approval workflows, retention policies, and notification integration.

## What Was Implemented

### Backend (Laravel)

1. **Database Migrations**
   - `leave_requests` - Leave request submissions
   - `leave_credits` - Leave balance tracking
   - `it_concerns` - IT issue reports
   - `medication_requests` - Medication requests
   - `form_request_retention_policies` - Data retention

2. **Models**
   - `LeaveRequest.php` - Leave with validation logic
   - `LeaveCredit.php` - Credit tracking
   - `ItConcern.php` - IT issues with status
   - `MedicationRequest.php` - Medication with policy
   - `FormRequestRetentionPolicy.php` - Retention rules

3. **Controllers**
   - `LeaveRequestController.php` - Leave CRUD and approval
   - `ItConcernController.php` - IT issue management
   - `MedicationRequestController.php` - Medication workflow
   - `FormRequestRetentionPolicyController.php` - Policy management

4. **Services**
   - `LeaveCreditService.php` - Credit calculation and validation

5. **Mail Classes**
   - `LeaveRequestSubmitted.php`
   - `LeaveRequestStatusUpdated.php`
   - `MedicationRequestSubmitted.php`
   - `MedicationRequestStatusUpdated.php`

### Frontend (React + TypeScript)

1. **Leave Pages** (`resources/js/pages/FormRequest/Leave/`)
   - `Index.tsx` - Leave list with filters
   - `Create.tsx` - Submit leave request
   - `Show.tsx` - View and approve/deny

2. **IT Concern Pages** (`resources/js/pages/FormRequest/ItConcerns/`)
   - `Index.tsx` - Concern list
   - `Create.tsx` - Submit concern
   - `Show.tsx` - View and resolve

3. **Medication Pages** (`resources/js/pages/FormRequest/MedicationRequests/`)
   - `Index.tsx` - Request list
   - `Create.tsx` - Submit request

4. **Retention Policy Page**
   - `RetentionPolicies.tsx` - Manage policies

## Key Features

### 1. Leave Request System

| Leave Type | Credits | Requirements |
|------------|---------|--------------|
| VL (Vacation) | ✅ Deduct | 2 weeks notice, ≤6 points |
| SL (Sick) | ✅ Deduct | No advance notice |
| BL (Birthday) | ✅ Deduct | Same as VL |
| SPL (Solo Parent) | ❌ None | Special leave |
| LOA (Leave of Absence) | ❌ None | Unpaid |
| LDV (Doctor Visit) | ❌ None | Medical |
| UPTO (Unpaid) | ❌ None | Personal |

**Credit Accrual:**
- Managers: 1.5 days/month
- Employees: 1.25 days/month
- Eligibility: After 6 months
- Annual reset: Credits don't carry over

### 2. IT Concern System

| Field | Description |
|-------|-------------|
| Category | Hardware, Software, Network, Account, Other |
| Priority | Low, Medium, High, Urgent |
| Status | Pending → In Progress → Resolved/Cancelled |
| Assignment | Assign to IT staff |

### 3. Medication Request System

| Field | Description |
|-------|-------------|
| Medication Type | Paracetamol, Ibuprofen, Antacid, etc. |
| Reason | Why medication is needed |
| Symptoms | Onset of symptoms |
| Policy | Must agree to medication policy |
| Status | Pending → Approved → Dispensed/Rejected |

### 4. Retention Policies

Configure automatic cleanup:
- Per request type (leave, IT, medication)
- Retention period in months
- Active/inactive toggle
- Automatic enforcement via scheduler

## Database Schema

```sql
-- Leave Requests
leave_requests (
    id, user_id,
    leave_type,              -- enum
    start_date, end_date,
    days_requested,
    reason, team_lead_email, campaign_department,
    medical_cert_submitted,
    status,                  -- pending, approved, denied, cancelled
    reviewed_by, reviewed_at, review_notes,
    credits_deducted, credits_year,
    attendance_points_at_request,
    auto_rejected, auto_rejection_reason,
    timestamps
)

-- Leave Credits
leave_credits (
    id, user_id,
    credits_earned, credits_used, credits_balance,
    year, month, accrued_at,
    timestamps
)

-- IT Concerns
it_concerns (
    id, user_id, site_id,
    station_number, category, description,
    status, priority,
    resolution_notes, resolved_at, resolved_by,
    timestamps
)

-- Medication Requests
medication_requests (
    id, user_id,
    name, medication_type,
    reason, onset_of_symptoms,
    agrees_to_policy,
    status,
    approved_by, approved_at, admin_notes,
    timestamps
)

-- Retention Policies
form_request_retention_policies (
    id, request_type,
    retention_months, is_active,
    description,
    timestamps
)
```

## Routes

```
# Leave Requests
GET    /form-requests/leave-requests           - List
GET    /form-requests/leave-requests/create    - Create form
POST   /form-requests/leave-requests           - Submit
GET    /form-requests/leave-requests/{id}      - View
POST   /form-requests/leave-requests/{id}/approve - Approve
POST   /form-requests/leave-requests/{id}/deny    - Deny
POST   /form-requests/leave-requests/{id}/cancel  - Cancel

# IT Concerns
GET    /form-requests/it-concerns              - List
GET    /form-requests/it-concerns/create       - Create form
POST   /form-requests/it-concerns              - Submit
GET    /form-requests/it-concerns/{id}         - View
POST   /form-requests/it-concerns/{id}/status  - Update status
POST   /form-requests/it-concerns/{id}/assign  - Assign
POST   /form-requests/it-concerns/{id}/resolve - Resolve

# Medication Requests
GET    /form-requests/medication-requests      - List
GET    /form-requests/medication-requests/create - Create form
POST   /form-requests/medication-requests      - Submit
POST   /form-requests/medication-requests/{id}/status - Update status

# Retention Policies
GET    /form-requests/retention-policies       - List
POST   /form-requests/retention-policies       - Create
PUT    /form-requests/retention-policies/{id}  - Update
DELETE /form-requests/retention-policies/{id}  - Delete
```

## Permissions

| Category | Permissions |
|----------|-------------|
| Leave | `leave.{view,create,approve,deny,cancel,view_all}` |
| IT Concerns | `it_concerns.{view,create,edit,delete,assign,resolve}` |
| Medication | `medication_requests.{view,create,update,delete}` |
| Retention | `form_requests.retention` |

## Workflows

### Leave Request Flow

```
Employee submits → Status: Pending
→ System validates (points, credits, dates)
→ If auto-rejected: Status: Denied with reason
→ HR/Admin reviews
→ Approve: Credits deducted, Status: Approved
→ Deny: Reason required, Status: Denied
→ Cancel: Credits restored (if approved), Status: Cancelled
```

### IT Concern Flow

```
Employee submits → Status: Pending
→ IT views in queue
→ Assign to IT staff → Status: In Progress
→ Work on issue
→ Resolve → Status: Resolved with notes
→ Or Cancel → Status: Cancelled
```

### Medication Flow

```
Employee submits → Agrees to policy required
→ Status: Pending
→ HR/Admin reviews
→ Approve → Status: Approved
→ Dispense medication → Status: Dispensed
→ Or Reject → Status: Rejected with reason
```

## Files Reference

### Backend
```
app/
├── Models/
│   ├── LeaveRequest.php
│   ├── LeaveCredit.php
│   ├── ItConcern.php
│   ├── MedicationRequest.php
│   └── FormRequestRetentionPolicy.php
├── Http/Controllers/
│   ├── LeaveRequestController.php
│   ├── ItConcernController.php
│   ├── MedicationRequestController.php
│   └── FormRequestRetentionPolicyController.php
├── Services/
│   └── LeaveCreditService.php
└── Mail/
    ├── LeaveRequestSubmitted.php
    ├── LeaveRequestStatusUpdated.php
    ├── MedicationRequestSubmitted.php
    └── MedicationRequestStatusUpdated.php
```

### Frontend
```
resources/js/pages/FormRequest/
├── Leave/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Show.tsx
├── ItConcerns/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Show.tsx
├── MedicationRequests/
│   ├── Index.tsx
│   └── Create.tsx
└── RetentionPolicies.tsx
```

## Integration Points

### Notification System
- Leave submitted → Notify HR/Admin
- Leave status change → Notify employee
- IT concern submitted → Notify IT department
- Medication status change → Notify employee

### Activity Logging
- All form requests logged
- Status changes tracked
- Approvals/denials recorded

### Email Notifications
- Leave submission confirmation
- Leave status updates
- Medication request updates

## Console Commands

```bash
# Accrue monthly leave credits
php artisan leave:accrue-credits

# Backfill credits for employees
php artisan leave:backfill-credits

# Year-end credits reminder
php artisan leave:year-end-reminder
```

## Related Documentation

- [Leave Management](../leave/README.md) - Complete leave docs
- [Notification System](../NOTIFICATION_SYSTEM.md) - Notifications
- [Authorization](../authorization/README.md) - Permissions

---

**Implementation Date:** November 2025  
**Status:** ✅ Complete and Production Ready
