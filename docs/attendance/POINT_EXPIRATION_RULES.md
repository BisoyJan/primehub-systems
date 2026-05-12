# Attendance Point Expiration Rules

## Overview
Attendance points automatically expire based on two different mechanisms: Standard Roll Off (SRO) and Good Behavior Roll Off (GBRO). This system encourages good attendance while maintaining accountability.

---

## Expiration Types

### 1. Standard Roll Off (SRO)
**Automatic expiration after a set period from the violation date**

#### Rules:
- **Standard Violations**: 6 months expiration
  - Tardy (0.25 points)
  - Undertime (0.25 points)  
  - Half-Day Absence (0.50 points)
  - **Advised Absence (1.00 point)** — Employee gave prior notice (manually entered with checkbox); treated as standard violation

- **NCNS and FTN**: 1 year expiration  
  - No Call, No Show - NCNS (1.00 point) — No prior notice; strictest penalty
  - Failed to Notify - FTN (1.00 point) — Employee was told to come but didn't show AND didn't call; treated same as NCNS

#### How It Works:
```
Violation Date: Jan 1, 2025
└─ Standard Violation → Expires: Jul 1, 2025 (6 months)
└─ NCNS/FTN → Expires: Jan 1, 2026 (12 months) ← both same
```

#### Characteristics:
- ✅ Fully automatic
- ✅ Based solely on violation date
- ✅ No user action required
- ✅ Runs daily via scheduled command

---

### 2. Good Behavior Roll Off (GBRO)
**Reward for sustained good attendance behavior**

#### Rules:
- **Eligibility**: No violations for **60 consecutive days**
- **Benefit**: Last **2 violation points** are automatically removed
- **Exclusions**: **NCNS and FTN** points are **NOT eligible** for GBRO (both have 1-year expiration); only **Advised Absence** (manually entered with checkbox) **IS eligible**

#### Example Scenario:
```
Employee Violations:
├─ Nov 4:  0.25 pts (Tardy)
├─ Nov 5:  0.25 pts (Tardy)
└─ Nov 6:  0.50 pts (Half-Day Absence)

After 60 days of perfect attendance:
├─ Nov 4:  0.25 pts (Remains - oldest)
├─ Nov 5:  0.25 pts (REMOVED via GBRO) ✨
└─ Nov 6:  0.50 pts (REMOVED via GBRO) ✨

Result: 0.50 points removed, 0.25 remaining
```

#### How It Works:
1. System identifies users with no violations in last 60 days
2. Finds the **2 most recent** points that are:
   - Active (not expired, not excused)
   - Eligible for GBRO (not NCNS/FTN)
3. Marks those points as expired with type "GBRO"
4. Employee gets fresh start while maintaining some accountability

#### Characteristics:
- ✅ Rewards consistent good behavior
- ✅ Automatic after 60 days clean
- ⛔ Cannot remove NCNS or FTN points (both have 1-year expiration, not GBRO eligible)
- ✅ Advised Absence (manual checkbox) IS eligible for GBRO
- ✅ Removes most recent 2 points only
- ✅ Can be applied multiple times (60 days each)

---

## Detailed Point Information

### Violation Details
Each point now includes comprehensive details:

#### NCNS (No Call, No Show)
```
Type: Whole Day Absence
Points: 1.00
Expiration: 1 year from violation date
GBRO Eligible: No
Details: "No Call, No Show (NCNS): Employee did not report 
for work and did not provide prior notice. Scheduled: 07:00 - 
17:00. No biometric scans recorded."
```

#### FTN (Failed to Notify)
```
Type: Whole Day Absence
Points: 1.00
Expiration: 1 year from violation date  ← same as NCNS
GBRO Eligible: No
Details: "Failed to Notify (FTN): Employee did not report for 
work despite being advised. Scheduled: 07:00 - 17:00. No 
biometric scans recorded."
```

> **FTN vs Advised Absence**: FTN occurs when the employee was told to come to work
> (or was expected) but didn't show AND didn't call — detected from biometric records
> (`ncns` status + `is_advised=true`). Treated identically to NCNS: 1 year, NOT GBRO eligible.
> Advised Absence (manually entered with the checkbox) means the employee proactively
> notified management — this IS GBRO eligible with 6-month expiration.

#### Half-Day Absence
```
Type: Half-Day Absence
Points: 0.50
Expiration: 6 months from violation date
GBRO Eligible: Yes
Details: "Half-Day Absence: Arrived 45 minutes late (more than 
15 minutes). Scheduled: 07:00, Actual: 07:45."
```

#### Tardy
```
Type: Tardy
Points: 0.25
Expiration: 6 months from violation date
GBRO Eligible: Yes
Details: "Tardy: Arrived 12 minutes late. Scheduled time in: 
07:00, Actual time in: 07:12."
```

#### Undertime
```
Type: Undertime
Points: 0.25
Expiration: 6 months from violation date
GBRO Eligible: Yes
Details: "Undertime: Left 90 minutes early (more than 1 hour 
before scheduled end). Scheduled: 17:00, Actual: 15:30."
```

---

## Database Schema

### New Fields in `attendance_points`

```sql
-- Expiration tracking
expires_at           DATE           -- Calculated expiration date
expiration_type      ENUM           -- 'sro', 'gbro', 'none'
is_expired           BOOLEAN        -- Whether point has expired
expired_at           DATE           -- When it was marked expired

-- Violation details
violation_details    TEXT           -- Human-readable description
tardy_minutes        INTEGER        -- Minutes late (for tardy)
undertime_minutes    INTEGER        -- Minutes left early

-- GBRO tracking
eligible_for_gbro    BOOLEAN        -- Can this be removed by GBRO?
gbro_applied_at      DATE           -- When GBRO was applied
gbro_batch_id        VARCHAR(255)   -- Batch processing ID
```

---

## Automated Processing

### Artisan Command
```bash
# Run expiration processing
php artisan points:process-expirations

# Dry run (see what would happen without changes)
php artisan points:process-expirations --dry-run

# Skip notifications to employees
php artisan points:process-expirations --no-notify

# Force GBRO processing even if already ran today
php artisan points:process-expirations --force
```

**Same-Day Protection:**
The command includes a safeguard that prevents multiple GBRO cycles from running on the same day. This prevents cascading expirations where Pair 0 expires, then Pair 1 immediately becomes eligible and expires in the same run. Use `--force` to bypass this check if needed.

### Scheduled Task (✅ Configured)
Automatically configured in `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // Process attendance point expirations (SRO and GBRO) - runs daily at 3:00 AM
    $schedule->command('points:process-expirations')
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->onOneServer();
}
```

**Schedule Details:**
- **Frequency:** Daily at 3:00 AM
- **Prevents:** Overlapping executions with `withoutOverlapping()`
- **Multi-server:** Only runs on one server with `onOneServer()`
- **Output:** Console output with summary table showing:
  - SRO expirations processed
  - GBRO expirations processed
  - Users affected
  - Processing timestamp

**To enable automatic execution:**
- Ensure Laravel scheduler is running via cron:
  ```bash
  * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
  ```
- Or on Windows Task Scheduler, run every minute:
  ```bash
  php artisan schedule:run
  ```

### What the Command Does:

#### SRO Processing:
1. Finds all non-expired, non-excused points
2. Checks if `expires_at` <= today
3. Marks matching points as expired with type "sro"

#### GBRO Processing:
1. Gets all users with active points
2. For each user:
   - Finds most recent violation date
   - Calculates days since last violation
   - If ≥ 60 days:
     - Gets 2 most recent GBRO-eligible points
     - Marks them expired with type "gbro"
     - Records GBRO batch ID for tracking

---

## Frontend Display

### Point Status Badges

**Active Point:**
```
[🔴 Active] 0.50 pts | Expires in 45 days
```

**Expired via SRO:**
```
[⚪ Expired] 0.50 pts | Expired via SRO on Jun 1, 2025
```

**Expired via GBRO:**
```
[⚪ Expired] 0.25 pts | Expired via GBRO (Good Behavior) on May 15, 2025
```

**Excused Point:**
```
[🟢 Excused] 1.00 pts | Excused by Admin on May 1, 2025
```

### Violation Details Modal
**New Feature:** Clean, uncluttered table display with "View Details" buttons

Instead of showing full violation text in the table (which caused clutter), each point with violation details now has a "View Details" button that opens a comprehensive dialog showing:

#### Dialog Contents:
- **Employee name** and **violation date**
- **Point type** badge (color-coded)
- **Points assigned** (bold, highlighted)
- **Full violation description** in a readable highlighted box
- **Tardy duration** (minutes late, if applicable)
- **Undertime duration** (minutes left early, if applicable)
- **Expiration information:**
  - Expiration date with countdown ("X days remaining")
  - Current status badge (Active/Excused/Expired SRO/Expired GBRO)
  - Expired date (if already expired)
- **Excuse information** (if point was excused):
  - Excuse reason
  - Who excused it
  - When it was excused

#### Desktop Table View:
- **8 columns:** Employee, Date, Type, Points, Status, Violation Details, Expires, Actions
- **Violation Details column:** Shows "View Details" button (no text clutter)
- **Expires column:** Shows expiration date with countdown or expired date
- **Visual distinction:** Expired rows have reduced opacity
- **Smart actions:** Cannot excuse or unexcuse expired points

#### Mobile Card View:
- Responsive card layout for small screens
- Violation details shown as a button with truncated preview
- Full details accessible via tap
- All expiration information displayed compactly
- Status badges adapt to mobile size

---

## Business Logic Examples

### Example 1: Standard Violations
```
Employee: John Doe

Jan 15, 2025 - Tardy (12 min)
├─ Points: 0.25
├─ Expires: Jul 15, 2025 (6 months)
├─ GBRO Eligible: Yes
└─ Status: Active

Feb 1, 2025 - NCNS
├─ Points: 1.00
├─ Expires: Feb 1, 2026 (1 year)
├─ GBRO Eligible: No
└─ Status: Active

Total Active Points: 1.25
```

### Example 2: GBRO Application
```
Employee: Jane Smith

Oct 1, 2024 - Tardy
├─ Points: 0.25
├─ Status: Active

Oct 5, 2024 - Undertime
├─ Points: 0.25
├─ Status: Active

Oct 10, 2024 - Half-Day Absence
├─ Points: 0.50
├─ Status: Active

60 days of perfect attendance...

Dec 15, 2024 - GBRO Triggered!
├─ Oct 10 point (0.50) → Expired via GBRO ✨
├─ Oct 5 point (0.25) → Expired via GBRO ✨
└─ Oct 1 point (0.25) → Remains active

New Total: 0.25 points (was 1.00)
```

### Example 3: Mixed Scenario
```
Employee: Mike Johnson

May 1, 2025 - NCNS (Not advised)
├─ Points: 1.00
├─ Expires: May 1, 2026
├─ GBRO Eligible: No

May 15, 2025 - Tardy
├─ Points: 0.25
├─ Expires: Nov 15, 2025
├─ GBRO Eligible: Yes

June 1, 2025 - Undertime
├─ Points: 0.25
├─ Expires: Dec 1, 2025
├─ GBRO Eligible: Yes

After 60 days clean (Aug 1, 2025):
├─ NCNS (1.00) → Remains (not GBRO eligible)
├─ Undertime (0.25) → Expired via GBRO ✨
├─ Tardy (0.25) → Expired via GBRO ✨

Final Total: 1.00 point (NCNS only)
```

---

## Statistics & Reporting

### Updated Stats Include:
- **Total Active Points** - Non-expired, non-excused
- **Excused Points** - Waived by management
- **Expired Points** - Removed by SRO or GBRO
  - SRO Count
  - GBRO Count
- **Points by Type** - Breakdown by violation type
- **Expiring Soon** - Points expiring in next 30 days

### Filters Available:
- Active points only
- Expired points only
- Excused points only
- By expiration type (SRO/GBRO)
- By date range
- By employee
- By violation type

---

## Benefits

### For Employees:
- ✅ Clear path to reduce points through good behavior
- ✅ Automatic cleanup of old violations
- ✅ Incentive to maintain perfect attendance
- ✅ Transparency on point status and expiration

### For Management:
- ✅ Automated point management
- ✅ Fair and consistent application of rules
- ✅ Detailed violation history
- ✅ Audit trail for all expirations
- ✅ Reduced administrative overhead

### For System:
- ✅ Self-maintaining point system
- ✅ Scalable for large workforce
- ✅ Complete data retention for compliance
- ✅ Comprehensive logging and tracking

---

## Implementation Checklist

### ✅ Completed Features
- [x] Database migration with new fields (expires_at, expiration_type, violation_details, etc.)
- [x] Updated AttendancePoint model with expiration logic methods
- [x] Expiration calculation logic (6 months standard, 1 year NCNS/FTN)
- [x] Updated AttendanceProcessor to auto-generate expiration dates and violation details
- [x] Updated AttendancePointController with expiration handling
- [x] Artisan command for processing expirations (SRO and GBRO)
- [x] Scheduled task configuration (daily at 3:00 AM)
- [x] Frontend UI updates for expiration display:
  - [x] Desktop table with Violation Details and Expires columns
  - [x] Mobile responsive card layout
  - [x] Violation Details dialog (clean, comprehensive)
  - [x] Status badges (Active/Excused/Expired SRO/Expired GBRO)
  - [x] Expiration countdown display
  - [x] Visual distinction for expired points
  - [x] Smart action menus (prevent actions on expired points)
- [x] Index page (all attendance points list)
- [x] Show page (individual user points detail)
- [x] Statistics cards showing expired points

### 🔄 Future Enhancements
- [ ] Admin dashboard for expiration monitoring and analytics
- [ ] Email notifications for upcoming expirations (30-day warning)
- [ ] Reports showing expiration trends over time
- [ ] GBRO eligibility preview for employees
- [ ] Bulk expiration processing reports
- [ ] Export expired points history

---

**Last Updated:** November 13, 2025  
**Status:** ✅ **FULLY IMPLEMENTED** - Backend & Frontend Complete  
**Production Ready:** Yes ✅
