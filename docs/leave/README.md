# Leave Management System

Comprehensive leave request and leave credits management system with automatic accrual, validation, and approval workflows.

---

## 📄 Documents

### [LEAVE_CREDITS_ACCRUAL.md](LEAVE_CREDITS_ACCRUAL.md) ⭐
**Leave Credits Accrual System**

Detailed documentation of the automatic monthly credit accrual system and backfilling logic.

**Topics Covered:**
- ✅ Monthly accrual algorithm (role-based rates)
- ✅ Backfilling system for existing employees
- ✅ 6-month eligibility calculation
- ✅ Annual reset policy (credits don't carry over)
- ✅ Automated cron job processing
- ✅ Database schema and credit tracking

**Best For:**
- Understanding how credits accumulate
- Learning the backfill algorithm
- Configuring automated accrual
- Troubleshooting credit balance issues

---

### [LEAVE_REQUEST_VALIDATION.md](LEAVE_REQUEST_VALIDATION.md) ⭐
**Leave Request Validation Logic**

Comprehensive guide to the three-layer validation system and all business rules.

**Topics Covered:**
- ✅ Frontend real-time validation (React)
- ✅ Backend form validation (Laravel)
- ✅ Service layer business logic validation
- ✅ Leave type specific rules (VL/SL/BL/SPL/LOA/LDV/UPTO)
- ✅ Attendance points checks
- ✅ Recent absence detection
- ✅ Credits balance verification

**Best For:**
- Understanding validation layers
- Debugging validation errors
- Learning business rules per leave type
- Implementing new validation rules

---

### [LEAVE_APPROVAL_WORKFLOW.md](LEAVE_APPROVAL_WORKFLOW.md) ⭐
**Leave Request Approval Workflow**

Complete workflow documentation from submission through approval/denial to cancellation.

**Topics Covered:**
- ✅ Status state machine (pending/approved/denied/cancelled)
- ✅ Approval process with credit deduction
- ✅ Denial process with required reasons
- ✅ Cancellation with automatic credit restoration
- ✅ Authorization matrix
- ✅ Audit trail and history tracking
- ✅ Database transactions for data integrity

**Best For:**
- Understanding the approval lifecycle
- Learning credit deduction/restoration algorithms
- Implementing workflow customizations
- Debugging status transition issues

---

## Overview

The Leave Management System handles:
- **Leave Credits Accrual**: Monthly automatic credit accumulation based on role
- **Leave Requests**: VL, SL, BL, SPL, LOA, LDV, UPTO with validation
- **Approval Workflow**: HR/Admin approval with review notes
- **Business Rules**: Attendance points, absence checks, advance notice requirements
- **Credit Tracking**: Balance management with deduction and restoration

## Features

### 1. Leave Credits System

**Monthly Accrual Rates:**
- **Managers** (Super Admin, Admin, Team Lead, HR): **1.5 credits/month**
- **Employees** (Agent, IT, Utility): **1.25 credits/month**

**Key Rules:**
- Credits accrue on the last day of each month
- New employees: Credits calculated from hire date to present
- Automatic backfilling for existing employees
- 6-month eligibility: Can't use credits until 6 months after hire date
- Credits visible only after 6 months
- **⚠️ IMPORTANT: Credits reset annually and do NOT carry over to the next year**
- Unused credits from 2025 will expire on December 31, 2025

**Cross-Year Credits:**
- Request in December 2025 for January 2026 → deducts from 2025 credits
- Each year starts fresh with 0 balance
- Credits earned in 2026 will be tracked separately from 2025

### 2. Leave Types

#### Credited Leave Types (Deduct from Balance)
1. **VL - Vacation Leave**
   - Requires: ≤6 attendance points
   - Requires: 2 weeks advance notice
   - Requires: No absence in last 30 days

2. **SL - Sick Leave**
   - No advance notice required (illness is unpredictable)
   - Optional: Medical certificate checkbox
   - Note: Medical cert submitted physically after return

3. **BL - Birthday Leave**
   - Treated as Vacation Leave internally
   - Same rules as VL apply

#### Non-Credited Leave Types (No Credit Deduction)
4. **SPL - Solo Parent Leave**
5. **LOA - Leave of Absence**
6. **LDV - Leave for Doctor's Visit**
7. **UPTO - Unpaid Personal Time Off**

### 3. Validation Rules

**Automatic Rejection Criteria:**
- VL/BL with >6 attendance points → Auto-rejected
- Insufficient leave credits → Validation error
- Start date < 2 weeks from today (for VL/BL only) → Validation error
- Absence in last 30 days (for VL/BL) → Validation error
- Not eligible (< 6 months employed) → Validation error

**Real-Time Frontend Validation:**
- Shows warnings before submission
- Displays current attendance points
- Shows leave credits balance with live calculation
- Calculates days between selected dates
- Displays remaining balance after request

### 4. Approval Workflow

**Roles:**
- **HR/Admin**: Can approve or deny all requests
- **Employees**: Can view own requests and cancel pending/future approved

**Approval Process:**
1. Employee submits leave request
2. Request status: `pending`
3. HR/Admin reviews request
4. Options:
   - **Approve**: Credits deducted, status → `approved`
   - **Deny**: Must provide reason (min 10 chars), status → `denied`

**Cancellation:**
- Employees can cancel:
  - Pending requests (anytime)
  - Approved requests (if start date is in future)
- Credits automatically restored on cancellation

### 5. User Interface

**Create Leave Request Form:**
- Leave credits balance card (Available, This Request, Remaining)
- Real-time validation warnings
- Eligibility status display
- Team Lead email dropdown (8 options)
- Campaign/Department dropdown (includes "Management")
- Days calculation display
- Medical certificate checkbox (for SL)

**Index/List View:**
- Filter by: Status, Type
- Admin: See all requests
- Employee: See only own requests
- Color-coded status badges
- Responsive table design

**Detail/Show View:**
- Complete request information
- Approve/Deny dialogs (HR/Admin)
- Cancel dialog (Employee)
- Review history display
- Credits deduction tracking

## Database Schema

### `users` Table (Updated)
```php
$table->date('hired_date')->nullable();
$table->enum('role', ['Super Admin', 'Admin', 'Team Lead', 'Agent', 'HR', 'IT', 'Utility']);
```

### `leave_credits` Table
```php
$table->id();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->decimal('credits_earned', 8, 2); // Monthly accrual (1.25 or 1.5)
$table->decimal('credits_used', 8, 2);   // Deducted when leave approved
$table->decimal('credits_balance', 8, 2); // Current balance
$table->year('year');                     // Year these credits belong to
$table->unsignedTinyInteger('month');     // Month (1-12)
$table->date('accrued_at');              // Date when credits were added
$table->unique(['user_id', 'year', 'month']);
```

### `leave_requests` Table
```php
$table->id();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->enum('leave_type', ['VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO']);
$table->date('start_date');
$table->date('end_date');
$table->decimal('days_requested', 5, 2);
$table->text('reason');
$table->string('team_lead_email');
$table->string('campaign_department');
$table->boolean('medical_cert_submitted')->default(false);
$table->enum('status', ['pending', 'approved', 'denied', 'cancelled']);
$table->foreignId('reviewed_by')->nullable()->constrained('users');
$table->timestamp('reviewed_at')->nullable();
$table->text('review_notes')->nullable();
$table->decimal('credits_deducted', 5, 2)->nullable();
$table->year('credits_year')->nullable();
$table->decimal('attendance_points_at_request', 5, 2);
$table->boolean('auto_rejected')->default(false);
$table->text('auto_rejection_reason')->nullable();
```

## Backend Implementation

### Models

**`LeaveCredit` Model** (`app/Models/LeaveCredit.php`)
- Relationships: `belongsTo(User)`
- Scopes: `forYear()`, `forUser()`, `forMonth()`
- Static methods: `getTotalBalance()`, `getTotalEarned()`, `getTotalUsed()`

**`LeaveRequest` Model** (`app/Models/LeaveRequest.php`)
- Relationships: `user()`, `reviewer()`
- Constants: `CREDITED_LEAVE_TYPES`, `NON_CREDITED_LEAVE_TYPES`
- Helper methods:
  - `requiresCredits()`: Check if type deducts credits
  - `requiresAttendancePointsCheck()`: Check if points validation needed
  - `requiresTwoWeekNotice()`: Check if 2-week rule applies
  - `canBeCancelled()`: Check if request can be cancelled
- Scopes: `byStatus()`, `byType()`, `forUser()`, `dateRange()`

### Service Layer

**`LeaveCreditService` (`app/Services/LeaveCreditService.php`)**

Core methods:
- `getMonthlyRate(User $user)`: Returns 1.5 or 1.25 based on role
- `isEligible(User $user)`: Check 6-month employment requirement
- `getBalance(User $user, int $year)`: Get current credits balance
- `accrueMonthly(User $user)`: Create monthly credit record
- `backfillCredits(User $user)`: Auto-calculate missing credits from hire date
- `deductCredits(LeaveRequest $request)`: Deduct when approved
- `restoreCredits(LeaveRequest $request)`: Restore when cancelled
- `getAttendancePoints(User $user)`: Get total non-expired points
- `hasRecentAbsence(User $user)`: Check 30-day absence rule
- `validateLeaveRequest(User $user, array $data)`: Complete validation

### Controller

**`LeaveRequestController` (`app/Http/Controllers/LeaveRequestController.php`)**

Routes:
- `GET /leave-requests` → `index()`: List with filters
- `GET /leave-requests/create` → `create()`: Show form (auto-backfills credits)
- `POST /leave-requests` → `store()`: Submit request
- `GET /leave-requests/{id}` → `show()`: View details
- `POST /leave-requests/{id}/approve` → `approve()`: Approve request (HR/Admin)
- `POST /leave-requests/{id}/deny` → `deny()`: Deny request (HR/Admin)
- `POST /leave-requests/{id}/cancel` → `cancel()`: Cancel request (Employee)

## Console Commands

### 1. Monthly Accrual (Automated)
```bash
php artisan leave:accrue-credits [--year=YYYY] [--month=MM]
```

**Scheduled:** Last day of month at 11:00 PM

**What it does:**
- Loops through all users with `hired_date`
- Creates `leave_credits` record for current month
- Skips if already accrued or if month hasn't ended

**Output:**
```
Accruing leave credits for 2025-11...
✓ John Doe: 1.25 credits
✓ Jane Smith: 1.5 credits
⊘ Bob Johnson: Skipped (no hire date or already accrued)

Summary:
  Accrued: 45
  Skipped: 5
```

### 2. Backfill Credits (Manual)
```bash
php artisan leave:backfill-credits [--user=ID]
```

**Use cases:**
- Adding existing employees to system
- Fixing missing accruals
- One-time setup for legacy data

**What it does:**
- Calculates all completed months from hire date to today
- Creates missing `leave_credits` records
- Skips existing records (idempotent)

**Example output:**
```
Backfilling leave credits from hire date to present...
[████████████████████████████] 100%

Backfill Complete!

┌─────────────────────┬───────┐
│ Metric              │ Count │
├─────────────────────┼───────┤
│ Total Users         │ 50    │
│ Users Processed     │ 45    │
│ Users Skipped       │ 5     │
│ Total Months        │ 450   │
└─────────────────────┴───────┘

Sample Results:
  • John Doe: 12.5 credits (hired Jan 01, 2025)
  • Jane Smith: 15.0 credits (hired Jan 01, 2025)
```

## Frontend Implementation

### React Pages

**Location:** `resources/js/pages/Leave/`

1. **`Create.tsx`** - Leave request form
   - Real-time validation warnings
   - Live credits balance calculation
   - Days calculator between dates
   - Team Lead and Campaign dropdowns
   - Medical certificate checkbox

2. **`Index.tsx`** - Leave requests list
   - Filter by status and type
   - Admin vs Employee view
   - Pagination
   - Status badges

3. **`Show.tsx`** - Request details
   - Full request information
   - Approve/Deny actions (HR/Admin)
   - Cancel action (Employee)
   - Review history

### Navigation

**Sidebar:** Attendance section → "Leave Requests" (Plane icon)

**Route:** `/leave-requests`

## Usage Examples

### For Employees

**1. Request Vacation Leave:**
```
1. Navigate to "Leave Requests" in sidebar
2. Click "Request Leave"
3. Select "Vacation Leave (VL)"
4. Choose dates (must be ≥2 weeks from today)
5. Select Team Lead email
6. Select Campaign/Department
7. Enter reason (min 10 characters)
8. System shows:
   - Available credits: 12.5 days
   - This request: -3 days
   - Remaining: 9.5 days
9. Submit request
```

**2. Check Leave Credits:**
- View on "Request Leave" form
- Shows: Available, Used, Remaining balance
- Only visible if employed ≥6 months

**3. Cancel Request:**
- View request in "Leave Requests" list
- Click request to view details
- Click "Cancel Request" button
- Credits automatically restored

### For HR/Admin

**1. Approve Leave:**
```
1. Go to "Leave Requests"
2. Click request to view details
3. Click "Approve" button
4. Optional: Add review notes
5. Confirm approval
6. Credits automatically deducted
```

**2. Deny Leave:**
```
1. View request details
2. Click "Deny" button
3. Enter denial reason (required)
4. Confirm denial
5. Employee notified via status
```

**3. Backfill Credits for New Employee:**
```bash
# Option 1: Automatic (when employee opens leave request form)
# No action needed - happens automatically

# Option 2: Manual (for bulk operations)
php artisan leave:backfill-credits --user=123
```

## Setup Instructions

### 1. Run Migrations
```bash
php artisan migrate
```

This creates:
- `leave_credits` table
- `leave_requests` table
- Updates `users` table with `hired_date` and new roles

### 2. Set Hire Dates for Existing Users
```bash
php artisan tinker

# Set hire date for all users (example)
User::all()->each(fn($u) => $u->update(['hired_date' => '2024-01-01']));

# Or set individually
$user = User::find(1);
$user->update(['hired_date' => '2025-01-15']);
```

### 3. Backfill Leave Credits
```bash
# For all employees
php artisan leave:backfill-credits

# For specific user
php artisan leave:backfill-credits --user=123
```

### 4. Year-End Credits Report
```bash
# Generate report of users with unused credits (run in Nov/Dec)
php artisan leave:year-end-reminder

# For specific year
php artisan leave:year-end-reminder --year=2025
```

This command shows:
- All users with unused leave credits
- Total expiring credits
- Reminder that credits don't carry over

**Best Practice:** Run this command in November and December to remind employees to use their remaining credits before year-end.

### 5. Verify Cron Job
Check `app/Console/Kernel.php`:
```php
$schedule->command('leave:accrue-credits')
    ->monthlyOn(date('t'), '23:00') // Last day of month at 11 PM
    ->withoutOverlapping()
    ->onOneServer();
```

### 5. Test the Feature
```bash
# Build frontend
npm run build

# Or for development
npm run dev
```

Navigate to: `http://your-app.test/leave-requests`

## Business Logic Flow

### Submit Leave Request
```
1. Employee fills form
2. Frontend validation (real-time)
   ├─ Check eligibility (6 months)
   ├─ Check 2-week notice (VL/BL only)
   ├─ Check attendance points (VL/BL)
   ├─ Check recent absence (VL/BL)
   └─ Check credits balance
3. Backend validation (comprehensive)
4. Create leave_request record (status: pending)
5. Store attendance_points_at_request
```

### Approve Request
```
1. HR/Admin clicks "Approve"
2. Update status → 'approved'
3. Record reviewer and timestamp
4. IF credited leave type (VL/SL/BL):
   ├─ Deduct credits from balance
   ├─ Update leave_credits records
   └─ Store credits_deducted amount
5. Optional: Create attendance records (TODO)
```

### Cancel Request
```
1. Employee clicks "Cancel"
2. Check: status = pending OR (approved + future date)
3. IF approved AND credits_deducted:
   └─ Restore credits to balance
4. Update status → 'cancelled'
```

## Troubleshooting

### No Leave Credits Showing
**Problem:** Employee has 0 credits despite being employed for months

**Solution:**
```bash
# Check hire date
php artisan tinker
User::find(ID)->hired_date;

# If null, set it:
User::find(ID)->update(['hired_date' => '2025-01-01']);

# Then backfill:
php artisan leave:backfill-credits --user=ID
```

### Not Eligible Message
**Problem:** "You are not eligible to use leave credits yet"

**Cause:** Less than 6 months from hire date

**Solution:** Wait until 6 months have passed, or adjust hire date if incorrect

### Validation Error: "Insufficient Leave Credits"
**Problem:** Request shows more days than available

**Solution:**
1. Check current balance: View "Request Leave" form
2. Request fewer days
3. OR: Wait for next month's accrual
4. OR: Check if backfill is needed

### Attendance Points Rejection
**Problem:** "You have 7 attendance points (must be ≤6)"

**Solution:**
1. Check attendance points in "Attendance Points" section
2. Wait for points to expire (based on point type)
3. OR: Request different leave type (SPL, LOA, etc.)

## Future Enhancements

Potential features for future development:

- [ ] Email notifications on approval/denial
- [ ] Leave balance dashboard widget
- [ ] Calendar view for team leave visibility
- [ ] Export leave history to Excel
- [ ] Bulk approval interface for HR
- [ ] Leave policy documentation page
- [ ] Mobile app integration
- [ ] Automatic attendance record creation on approval
- [ ] Holiday calendar integration
- [ ] Manager approval layer (before HR)
- [ ] Leave balance forecasting

## Related Documentation

- [Attendance System](../attendance/README.md)
- [Attendance Points](../attendance/AUTOMATIC_POINT_GENERATION.md)
- [Point Expiration Rules](../attendance/POINT_EXPIRATION_RULES.md)
- [Biometric Records](../biometric/README.md)

## Support

For issues or questions:
1. Check this documentation
2. Review business rules section
3. Test with backfill command
4. Check database records directly
5. Review validation error messages
