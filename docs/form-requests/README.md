# Form Requests System

Comprehensive documentation for employee form request systems including Leave Requests, IT Concerns, and Medication Requests.

---

## 🚀 Quick Links

- **[QUICKSTART.md](QUICKSTART.md)** - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - Technical overview

---

## 📂 Subsystems

### 🏖️ [Leave Management](../leave/README.md)
Complete leave request and credits management system.
- Leave credits accrual (monthly automatic)
- 7 leave types (VL, SL, BL, SPL, LOA, LDV, UPTO)
- Approval workflow with HR/Admin review
- Validation rules and business logic

### 🔧 [IT Concerns](#it-concerns)
IT issue tracking and resolution system.
- Submit IT issues by category
- Priority-based tracking
- Assignment and resolution workflow
- Dashboard integration

### 💊 [Medication Requests](#medication-requests)
First-aid medication request system.
- Request common medications
- Policy agreement requirement
- Approval and dispensing workflow
- Request history tracking

### 📋 [Retention Policies](#retention-policies)
Data retention management for form requests.
- Configurable retention periods
- Automatic cleanup scheduling
- Type-specific policies

---

## 🔧 IT Concerns

### Overview
The IT Concerns system allows employees to report IT issues and track their resolution.

### Database Schema
```
it_concerns
├── id
├── user_id (foreign key → users)
├── site_id (foreign key → sites)
├── station_number
├── category (enum)
├── description (text)
├── status (enum: pending, in_progress, resolved, cancelled)
├── priority (enum: low, medium, high, urgent)
├── resolution_notes (text, nullable)
├── resolved_at (timestamp, nullable)
├── resolved_by (foreign key → users, nullable)
└── timestamps
```

### Categories
- **Hardware** - Physical equipment issues
- **Software** - Application/OS problems
- **Network** - Connectivity issues
- **Account** - Login/access problems
- **Other** - Miscellaneous issues

### Priority Levels
| Priority | Color | Description |
|----------|-------|-------------|
| Low | Gray | Non-urgent, can wait |
| Medium | Blue | Normal priority |
| High | Orange | Needs attention soon |
| Urgent | Red | Critical, immediate action needed |

### Status Workflow
```
pending → in_progress → resolved
    ↓
 cancelled
```

### Routes
```
GET    /form-requests/it-concerns           - List concerns
GET    /form-requests/it-concerns/create    - Create form
POST   /form-requests/it-concerns           - Submit concern
GET    /form-requests/it-concerns/{id}      - View concern
GET    /form-requests/it-concerns/{id}/edit - Edit form
PUT    /form-requests/it-concerns/{id}      - Update concern
DELETE /form-requests/it-concerns/{id}      - Delete concern
POST   /form-requests/it-concerns/{id}/status - Update status
POST   /form-requests/it-concerns/{id}/assign - Assign to IT staff
POST   /form-requests/it-concerns/{id}/resolve - Mark resolved
POST   /form-requests/it-concerns/{id}/cancel - Cancel concern
```

### Permissions
| Permission | Description |
|------------|-------------|
| `it_concerns.view` | View IT concerns |
| `it_concerns.create` | Create IT concerns |
| `it_concerns.edit` | Edit IT concerns |
| `it_concerns.delete` | Delete IT concerns |
| `it_concerns.assign` | Assign concerns to IT staff |
| `it_concerns.resolve` | Mark concerns as resolved |

### Dashboard Integration
- **Pending Count** - IT concerns awaiting action
- **In Progress Count** - Concerns being worked on
- **Resolved Count** - Completed concerns
- **Trends Chart** - Monthly concern trends
- **By Site Breakdown** - Concerns grouped by location

---

## 💊 Medication Requests

### Overview
The Medication Requests system allows employees to request first-aid medications.

### Database Schema
```
medication_requests
├── id
├── user_id (foreign key → users)
├── name (medication name)
├── medication_type (enum)
├── reason (text)
├── onset_of_symptoms (string)
├── agrees_to_policy (boolean)
├── status (enum: pending, approved, dispensed, rejected)
├── approved_by (foreign key → users, nullable)
├── approved_at (timestamp, nullable)
├── admin_notes (text, nullable)
└── timestamps
```

### Medication Types
- **Paracetamol** - Pain/fever relief
- **Ibuprofen** - Anti-inflammatory
- **Antacid** - Stomach relief
- **Antihistamine** - Allergy relief
- **Other** - Other medications

### Status Workflow
```
pending → approved → dispensed
    ↓
 rejected
```

### Routes
```
GET    /form-requests/medication-requests           - List requests
GET    /form-requests/medication-requests/create    - Create form
GET    /form-requests/medication-requests/check-pending/{userId} - Check pending
POST   /form-requests/medication-requests           - Submit request
GET    /form-requests/medication-requests/{id}      - View request
POST   /form-requests/medication-requests/{id}/status - Update status
DELETE /form-requests/medication-requests/{id}/cancel - Cancel request
DELETE /form-requests/medication-requests/{id}      - Delete request
```

### Permissions
| Permission | Description |
|------------|-------------|
| `medication_requests.view` | View medication requests |
| `medication_requests.create` | Create medication requests |
| `medication_requests.update` | Update request status |
| `medication_requests.delete` | Delete medication requests |

### Business Rules
- Employees must agree to medication policy
- One pending request per employee at a time
- Admin approval required before dispensing
- Reason and symptoms required

---

## 📋 Retention Policies

### Overview
Manages automatic cleanup of old form request records.

### Database Schema
```
form_request_retention_policies
├── id
├── request_type (enum: leave_request, it_concern, medication_request)
├── retention_months (integer)
├── is_active (boolean)
├── description (text, nullable)
└── timestamps
```

### Routes
```
GET    /form-requests/retention-policies           - List policies
POST   /form-requests/retention-policies           - Create policy
PUT    /form-requests/retention-policies/{id}      - Update policy
DELETE /form-requests/retention-policies/{id}      - Delete policy
POST   /form-requests/retention-policies/{id}/toggle - Toggle active
```

### Permissions
| Permission | Description |
|------------|-------------|
| `form_requests.retention` | Manage retention policies |

---

## 🔔 Notifications

Form requests integrate with the notification system:

### Leave Requests
- **Submitted**: Notify HR/Admin of new request
- **Approved**: Notify employee of approval
- **Denied**: Notify employee of denial
- **Cancelled**: Notify HR/Admin of cancellation

### IT Concerns
- **Submitted**: Notify IT department
- **Assigned**: Notify assigned IT staff
- **Resolved**: Notify employee of resolution
- **Status Change**: Notify relevant parties

### Medication Requests
- **Submitted**: Notify HR/Admin
- **Approved**: Notify employee
- **Dispensed**: Record in system
- **Rejected**: Notify employee

---

## 📧 Email Notifications

### Leave Requests
- `LeaveRequestSubmitted` - Email to HR/Admin on submission
- `LeaveRequestStatusUpdated` - Email to employee on status change

### Medication Requests
- `MedicationRequestSubmitted` - Email to HR/Admin
- `MedicationRequestStatusUpdated` - Email to employee

### Mail Templates
- `app/Mail/LeaveRequestSubmitted.php`
- `app/Mail/LeaveRequestStatusUpdated.php`
- `app/Mail/MedicationRequestSubmitted.php`
- `app/Mail/MedicationRequestStatusUpdated.php`

---

## 🎓 Key Files

### Models
- `app/Models/LeaveRequest.php`
- `app/Models/LeaveCredit.php`
- `app/Models/ItConcern.php`
- `app/Models/MedicationRequest.php`
- `app/Models/FormRequestRetentionPolicy.php`

### Controllers
- `app/Http/Controllers/LeaveRequestController.php`
- `app/Http/Controllers/ItConcernController.php`
- `app/Http/Controllers/MedicationRequestController.php`
- `app/Http/Controllers/FormRequestRetentionPolicyController.php`

### Services
- `app/Services/LeaveCreditService.php`

### Frontend Pages
- `resources/js/pages/FormRequest/Leave/` - Leave request pages
- `resources/js/pages/FormRequest/ItConcerns/` - IT concerns pages
- `resources/js/pages/FormRequest/MedicationRequests/` - Medication pages
- `resources/js/pages/FormRequest/RetentionPolicies.tsx` - Policies page

---

## 📊 Activity Logging

All form requests use Spatie Activity Log for audit trails:

```php
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
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

### Logged Events
- Created
- Updated
- Deleted
- Status changes
- Approvals/Denials

### Viewing Activity Logs
- Navigate to `/activity-logs`
- Filter by model type, user, date range
- View detailed change history

---

## 🔗 Related Documentation

- [Leave Management](../leave/README.md) - Complete leave system docs
- [Notification System](../NOTIFICATION_SYSTEM.md) - Notifications docs
- [Authorization](../authorization/README.md) - Permissions system

---

*Last updated: November 28, 2025*
