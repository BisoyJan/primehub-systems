# PrimeHub Systems - Full Changelog (January 9, 2026)

This document provides a comprehensive breakdown of all module updates and changes based on the latest GitHub diff.

---

## Table of Contents

1. [Leave Management Module](#1-leave-management-module)
2. [Leave Credits Module](#2-leave-credits-module)
3. [Leave Calendar Module](#3-leave-calendar-module)
4. [Retention Policies Module](#4-retention-policies-module)
5. [Computer Specifications Module](#5-computer-specifications-module)
6. [Notifications Module](#6-notifications-module)
7. [Error Handling & UI Components](#7-error-handling--ui-components)
8. [Database Migrations](#8-database-migrations)
9. [Scheduled Tasks](#9-scheduled-tasks)
10. [Backend Routes](#10-backend-routes)
11. [Data Fixes & Scripts](#11-data-fixes--scripts)

---

## 1. Leave Management Module

### Files Changed:
- [resources/js/pages/FormRequest/Leave/Create.tsx](resources/js/pages/FormRequest/Leave/Create.tsx)
- [resources/js/pages/FormRequest/Leave/Edit.tsx](resources/js/pages/FormRequest/Leave/Edit.tsx)
- [resources/js/pages/FormRequest/Leave/Show.tsx](resources/js/pages/FormRequest/Leave/Show.tsx)
- [resources/js/pages/FormRequest/Leave/Index.tsx](resources/js/pages/FormRequest/Leave/Index.tsx)

### New Features:

#### 1.1 Future Credits Calculation
**Purpose:** Calculate leave credits that will accrue before the leave start date for better credit planning.

**Changes:**
- Added `calculateFutureCredits()` function that computes projected credits based on monthly rate
- New state variable `futureCredits` to track projected credits
- UI displays "Future Credits" column in the credits summary card (purple color)
- Available balance now includes: `balance - pending + futureCredits`
- Information box explaining future credits when applicable

**Implementation Details:**
```typescript
// Calculates months between current date and leave start date
// Multiplies by monthly_rate to get projected credits
const calculateFutureCredits = (startDate: string): number => {
    if (!startDate || !creditsSummary.is_eligible) return 0;
    // ... calculation logic
    return monthsToAccrue * creditsSummary.monthly_rate;
};
```

#### 1.2 Campaign Leave Conflicts Detection
**Purpose:** Warn users when colleagues from the same campaign have overlapping leave requests (First-Come-First-Serve policy for VL and UPTO).

**Changes:**
- New interface `CampaignConflict` for tracking conflicts
- API endpoint `/api/check-campaign-conflicts` called via fetch
- Purple alert box displaying conflicting requests with:
  - Employee name
  - Leave type badge
  - Date range
  - Status (approved/pending)
  - Submission date
- Debounced API call (500ms) to prevent excessive requests

**UI Display:**
- Shows only for VL (Vacation Leave) and UPTO (Unpaid Time Off) types
- Grid layout with responsive design
- Hover effects on conflict cards

#### 1.3 30-Day Absence Window Warning
**Purpose:** Alert employees that VL requests within 30 days of their last absence may require additional review.

**Changes:**
- New prop `lastAbsenceDate` passed from backend
- State variable `absenceWindowInfo` for warning message
- Orange alert box with `Info` icon
- Calculates window end date using `addDays(absenceDate, 30)`
- Message includes last absence date and next eligible date

#### 1.4 Partial Denial (Partial Approval) Feature
**Purpose:** Allow approvers to approve only specific dates from a multi-day leave request.

**Changes in Show.tsx:**
- New dialog `showPartialDenyDialog` with date selection
- Checkbox-based date selection interface
- `selectedApprovedDates` state for tracking selections
- `handlePartialDeny()` function to process partial approvals
- New route `leavePartialDenyRoute`
- Displays approved vs denied dates summary
- Required denial reason (minimum 10 characters)

**New UI Elements:**
- "Partial Deny" button (orange) for multi-day requests
- Date selection grid with checkboxes
- Real-time count of approved/denied days
- Partial denial info alert on approved requests

**Database Fields Added:**
- `has_partial_denial` (boolean)
- `approved_days` (decimal 5,2)
- `denied_dates` relationship

#### 1.5 Attendance Points Display Enhancements
**Purpose:** Better visibility of attendance violations during leave request review.

**Changes:**
- Attendance violations now only shown if points >= 6 (threshold for concern)
- New badge display for high points (destructive variant)
- "View Details" button opening detailed dialog
- Dialog shows:
  - Total points at request time
  - Individual violations with shift dates
  - Point type (formatted for readability)
  - Expiration dates (SRO and GBRO)
  - Current status (active/excused/expired)
- Visual distinction for points that changed status after request

**Helper Function:**
```typescript
const formatPointType = (type: string) => {
    const typeMap: Record<string, string> = {
        'whole_day_absence': 'Whole Day Absence',
        'half_day_absence': 'Half Day Absence',
        'undertime': 'Undertime',
        'undertime_more_than_hour': 'Undertime (>1 Hour)',
        'tardy': 'Tardy',
    };
    return typeMap[type] || type;
};
```

#### 1.6 SL Credit Tracking
**Purpose:** Track whether SL (Sick Leave) credits were applied and reason if not.

**Changes:**
- New fields: `sl_credits_applied`, `sl_no_credit_reason`
- Alert display when SL credits not deducted
- Blue info alert with reason explanation

#### 1.7 Earlier Conflicts Warning (Show Page)
**Purpose:** Display earlier leave requests that may take priority under First-Come-First-Serve policy.

**Changes:**
- New prop `earlierConflicts: EarlierConflict[]`
- Orange alert showing conflicting requests submitted earlier
- Overlapping dates highlighted
- Badges for leave type and status
- Submission timestamps

### Index Page Changes:

#### 1.8 Employee Search Improvements
**Changes:**
- Now uses `allEmployees` prop from backend instead of extracting from current page data
- Limits filtered results to 50 entries for performance
- Clears search query when popover closes via useEffect

---

## 2. Leave Credits Module

### Files Changed:
- [resources/js/pages/FormRequest/Leave/Credits/Index.tsx](resources/js/pages/FormRequest/Leave/Credits/Index.tsx)
- [resources/js/pages/FormRequest/Leave/Credits/Show.tsx](resources/js/pages/FormRequest/Leave/Credits/Show.tsx)

### New Features:

#### 2.1 Carryover Credits Display
**Purpose:** Show year-end carryover credits for cash conversion tracking.

**New Interface:**
```typescript
interface CarryoverData {
    credits: number;
    to_year: number;
    is_processed: boolean;
    cash_converted: boolean;
}
```

**Index Page Changes:**
- New "Carryover (Cash)" column in table
- Tooltip showing carryover details:
  - Target year
  - Processing status (Projected/Pending/Converted)
- Color-coded badges:
  - Green: Cash converted
  - Amber: Pending conversion
  - Blue: Projected (not yet processed)
- Mobile card view includes carryover section

**Show Page Changes:**
- New `CarryoverSummary` interface with detailed fields
- Dedicated card section for carryover display
- Shows:
  - Balance from previous year
  - Carryover amount (max 4 credits)
  - Forfeited credits
  - Processing status with icons
- Alert explaining carryover rules:
  - Carryover credits are for cash conversion only
  - Cannot be used for leave applications
  - Maximum of 4 credits per year

#### 2.2 UI Improvements
**Changes:**
- Added Tooltip components for better information display
- Banknote icon for carryover indicators
- Status icons (CheckCircle, AlertCircle)
- Improved popover handling (clears search on close)

---

## 3. Leave Calendar Module

### Files Changed:
- [resources/js/pages/FormRequest/Leave/Calendar.tsx](resources/js/pages/FormRequest/Leave/Calendar.tsx)

### New Features:

#### 3.1 Status-Based Color Coding
**Purpose:** Visually distinguish approved vs pending leaves on the calendar.

**Changes:**
- New `status` filter in PageProps interface
- Status filter dropdown (All/Approved/Pending)
- Status color mapping:
  ```typescript
  const statusColors = {
      'approved': { bg: 'bg-green-500', text: 'text-white', border: 'border-green-600' },
      'pending': { bg: 'bg-yellow-500', text: 'text-white', border: 'border-yellow-600' },
  };
  ```

**Calendar Day Styling:**
- Green background: Approved leaves only
- Yellow background: Pending leaves only
- Gradient (green to yellow): Both approved and pending
- Tooltip shows count breakdown: "X approved, Y pending"

**Legend Updates:**
- New legend items: Approved (green), Pending (yellow), Both (gradient)
- Removed generic "On leave" amber indicator

#### 3.2 Leave List Enhancements
**Changes:**
- Card title changed from "All Leaves" to "Leave Requests"
- Description shows breakdown: "X approved, Y pending in view"
- Each leave card now shows status badge (Approved/Pending)
- Double badge display: Leave type + Status

---

## 4. Retention Policies Module

### Files Changed:
- [resources/js/pages/FormRequest/RetentionPolicies.tsx](resources/js/pages/FormRequest/RetentionPolicies.tsx)

### New Features:

#### 4.1 Leave Credit Retention Support
**Purpose:** Allow retention policies to apply to leave credit records.

**Changes:**
- Extended `form_type` enum to include `'leave_credit'`
- New select option: "Leave Credits Only"
- Cyan badge for leave credit policies

#### 4.2 Record Statistics Dashboard
**Purpose:** Show overview of records by age for capacity planning.

**New Interfaces:**
```typescript
interface AgeRange {
    range: string;
    count: number;
}

interface FormTypeStats {
    label: string;
    total: number;
    byAge: AgeRange[];
}

interface RetentionStats {
    leave_request: FormTypeStats;
    it_concern: FormTypeStats;
    medication_request: FormTypeStats;
    leave_credit: FormTypeStats;
}
```

**UI Changes:**
- New statistics section with 4 cards
- Each card shows:
  - Icon (FileText, Laptop, Pill, Calendar)
  - Total count
  - Breakdown by age range
- Bar chart icon in section header

#### 4.3 Policy Preview Feature
**Purpose:** See how many records would be affected before enforcement.

**New Interface:**
```typescript
interface PreviewData {
    policy: { id, name, retention_months, applies_to_type, form_type };
    cutoff_date: string;
    preview: Array<{ form_type, label, count, oldest_date, newest_date }>;
    total_affected: number;
}
```

**Changes:**
- New "Preview" button (Eye icon) for each policy
- Preview dialog showing:
  - Policy details
  - Cutoff date
  - Records by form type with counts
  - Date ranges for affected records
- Loading state with spinner
- Destructive alert if records would be deleted

---

## 5. Computer Specifications Module

### Files Changed:
- [resources/js/pages/Computer/DiskSpecs/Index.tsx](resources/js/pages/Computer/DiskSpecs/Index.tsx)
- [resources/js/pages/Computer/MonitorSpecs/Index.tsx](resources/js/pages/Computer/MonitorSpecs/Index.tsx)
- [resources/js/pages/Computer/RamSpecs/Index.tsx](resources/js/pages/Computer/RamSpecs/Index.tsx)

### Changes:

#### 5.1 Unused Import Cleanup
**Purpose:** Code cleanup to remove unused imports.

**Removed:**
```typescript
// Removed from all three files:
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
```

---

## 6. Notifications Module

### Files Changed:
- [resources/js/pages/Notifications/Index.tsx](resources/js/pages/Notifications/Index.tsx)
- [resources/js/pages/Notifications/Send.tsx](resources/js/pages/Notifications/Send.tsx)

### Changes:

#### 6.1 API Request Headers
**Purpose:** Improve API request reliability and CSRF handling.

**Added Headers:**
```typescript
headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',  // NEW
    'X-CSRF-TOKEN': ...,
    'X-Requested-With': 'XMLHttpRequest',  // NEW
}
```

#### 6.2 Role Count Display
**Changes in Send.tsx:**
- Role interface now includes `count` property
- Uses backend-provided count instead of filtering: `role.count` instead of `users.filter(...).length`

---

## 7. Error Handling & UI Components

### New Files:
- [resources/js/pages/Errors/Error.tsx](resources/js/pages/Errors/Error.tsx)
- [resources/js/components/header-date-time.tsx](resources/js/components/header-date-time.tsx)

### 7.1 Custom Error Page

**Purpose:** Provide a beautiful, interactive error page with particle effects.

**Features:**
- Canvas-based particle animation
- Mouse/touch interaction with particles
- Particle connections based on proximity
- Status-specific configurations:
  - 403: Access Denied (ShieldX icon)
  - 404: Lost in Space (AlertTriangle icon)
  - 500: Server Error (ServerCrash icon)
  - 503: Under Maintenance (Lock icon)
- Rotating inspirational quotes
- Gradient background with purple theme
- Navigation buttons: Go Back, Home, Try Again (500 only)
- PRIMEHUB branding with floating animation

**Quote Rotation:**
```typescript
const quotes = [
    'Not all who wander are lost, but this page definitely is.',
    'The best journeys sometimes take unexpected detours.',
    // ...
];
```

### 7.2 Header Date/Time Component

**Purpose:** Display current date and time in the application header.

**Features:**
- Real-time clock (updates every second)
- Hidden on dashboard page
- Shows weekday, month, day, year
- 12-hour time format
- Desktop only (hidden on mobile via `hidden md:flex`)

---

## 8. Database Migrations

### New Migration Files:

#### 8.1 Leave Request Enhancements
**File:** `2026_01_07_000001_add_leave_request_enhancements.php`

**New Columns on `leave_requests` table:**
| Column | Type | Description |
|--------|------|-------------|
| `sl_credits_applied` | boolean, nullable | Whether SL credits were deducted |
| `sl_no_credit_reason` | string, nullable | Reason if credits not applied |
| `has_partial_denial` | boolean, default false | Whether request has partial denial |
| `approved_days` | decimal(5,2), nullable | Number of days approved |

#### 8.2 Biometric Retention Policy Record Types
**File:** `2026_01_07_000615_add_record_type_to_biometric_retention_policies_table.php`

**New Column:**
| Column | Type | Values | Description |
|--------|------|--------|-------------|
| `record_type` | enum | 'all', 'biometric_record', 'attendance_point' | Target record type |

**New Index:** `brp_active_type_priority_idx`

#### 8.3 Form Request Retention Policy Extension
**File:** `2026_01_07_002151_add_leave_credit_to_form_request_retention_policies_form_type.php`

**Changes:**
- Extended `form_type` enum to include `'leave_credit'`

#### 8.4 Leave Credit Carryovers Table
**File:** `2026_01_08_233520_add_carryover_credits_to_leave_credits_table.php`

**New Table: `leave_credit_carryovers`**
| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | foreignId | Reference to users |
| `credits_from_previous_year` | decimal(8,2) | Total unused credits |
| `carryover_credits` | decimal(8,2) | Credits carried (max 4) |
| `forfeited_credits` | decimal(8,2) | Credits beyond max |
| `from_year` | year | Source year |
| `to_year` | year | Target year |
| `cash_converted` | boolean | Conversion status |
| `cash_converted_at` | date, nullable | Conversion date |
| `processed_by` | foreignId, nullable | Processor reference |
| `notes` | text, nullable | Additional notes |

**Constraint:** Unique on `(user_id, from_year, to_year)`

---

## 9. Scheduled Tasks

### File Changed:
- [routes/console.php](routes/console.php)

### New Scheduled Command:

#### 9.1 Leave Credit Carryover Processing
**Command:** `leave:process-carryover`

**Schedule:** January 1st at 12:00 AM (yearly)

**Purpose:** Process year-end leave credit carryovers for cash conversion.

**Details:**
- Runs with `--year` parameter set to previous year
- Uses `withoutOverlapping()` for safety
- Uses `onOneServer()` for multi-server deployments

**Console Command Class:** `ProcessLeaveCreditsCarryover`
- Supports `--year`, `--user`, `--dry-run` options
- Maximum carryover: 4 credits
- Displays detailed table output
- Progress bar for batch processing

---

## 10. Backend Routes

### File Changed:
- [routes/web.php](routes/web.php)

### New Routes:

#### 10.1 Leave Request Routes
| Route | Method | Controller Method | Purpose |
|-------|--------|-------------------|---------|
| `/api/check-campaign-conflicts` | POST | `checkCampaignConflicts` | Check for overlapping leaves |
| `/{leaveRequest}/partial-deny` | POST | `partialDeny` | Partially approve leave |

#### 10.2 Retention Policy Routes
| Route | Method | Controller | Purpose |
|-------|--------|------------|---------|
| `/biometric/retention-policies/{policy}/preview` | GET | `BiometricRetentionPolicyController@preview` | Preview affected records |
| `/form-requests/retention-policies/{policy}/preview` | GET | `FormRequestRetentionPolicyController@preview` | Preview affected records |

---

## 11. Data Fixes & Scripts

### New Files:
- [scripts/fix_attendance_points_at_request.php](scripts/fix_attendance_points_at_request.php)

### Purpose:
Fix historical data inconsistency in `attendance_points_at_request` field.

### Logic:
A point is considered "active at request time" if:
1. The `shift_date` was before the request submission
2. AND either:
   - Still active today
   - OR was excused AFTER submission
   - OR was expired AFTER submission

### Usage:
```bash
# Dry run (see what would change):
php artisan tinker scripts/fix_attendance_points_at_request.php

# Apply changes:
echo. > scripts/.apply_flag && php artisan tinker scripts/fix_attendance_points_at_request.php
```

### Output:
- Summary of records needing changes
- Detailed breakdown per leave request
- Points detail with status

---

## New Models

### LeaveCreditCarryover
**File:** [app/Models/LeaveCreditCarryover.php](app/Models/LeaveCreditCarryover.php)

**Constants:**
- `MAX_CARRYOVER_CREDITS = 4`

**Relationships:**
- `user()` - BelongsTo User
- `processedBy()` - BelongsTo User

**Scopes:**
- `forUser($userId)`
- `fromYear($year)`
- `toYear($year)`
- `pendingCashConversion()`
- `cashConverted()`

**Static Methods:**
- `getForUserAndYear($userId, $fromYear)`
- `getTotalCarryoverToYear($userId, $toYear)`

### LeaveRequestDeniedDate
**File:** [app/Models/LeaveRequestDeniedDate.php](app/Models/LeaveRequestDeniedDate.php)

**Purpose:** Track individually denied dates in partial denial scenarios.

**Fields:**
- `leave_request_id`
- `denied_date`
- `denial_reason`
- `denied_by`

**Relationships:**
- `leaveRequest()` - BelongsTo LeaveRequest
- `denier()` - BelongsTo User

---

## Summary Statistics

| Category | Count |
|----------|-------|
| Files Modified | 18 |
| Files Added | 8 |
| New Database Tables | 2 |
| New Columns Added | ~8 |
| New API Routes | 4 |
| New Scheduled Tasks | 1 |
| New Models | 2 |
| New Components | 2 |

---

## Migration Commands

To apply all changes, run:
```bash
php artisan migrate
```

To rollback:
```bash
php artisan migrate:rollback --step=4
```

---

*Document generated: January 9, 2026*
