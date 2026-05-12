# Attendance Point Expiration System - Implementation Summary

## 🎯 Overview

A fully automated attendance point expiration system that implements **Standard Roll Off (SRO)** and **Good Behavior Roll Off (GBRO)** rules to manage employee attendance accountability fairly while encouraging good behavior.

**Status:** ✅ **PRODUCTION READY** - Fully implemented backend and frontend

---

## ✨ Key Features

### 1. Standard Roll Off (SRO)
**Automatic expiration based on time elapsed since violation**

- **Standard violations** (Tardy, Undertime, Half-Day, Advised Absence) expire after **6 months**
- **FTN / NCNS violations** expire after **1 year** — FTN and NCNS are the same: `is_advised=false`, not GBRO-eligible
- Fully automatic, runs daily at 8:05 AM
- No user intervention required

### 2. Good Behavior Roll Off (GBRO)
**Reward system for sustained good attendance**

- After **60 consecutive days** without violations of any kind
- Automatically expires the **last 2 eligible points**
- **FTN/NCNS points are NOT eligible** for GBRO removal (1-year SRO only)
- **FTN, NCNS, and excused violations still reset the 60-day clock** even though they cannot be removed
- Encourages and rewards good attendance behavior

### 3. Comprehensive Violation Details
**Each point includes detailed information:**

- Full violation description (e.g., "Tardy: Arrived 12 minutes late. Scheduled: 07:00, Actual: 07:12")
- Tardy/undertime duration in minutes
- Scheduled vs actual times
- NCNS/FTN indicators
- Auto-generated during attendance processing

### 4. Clean Frontend UI
**User-friendly interface with dialog-based details:**

- "View Details" buttons instead of cluttered table text
- Comprehensive violation details dialog
- Expiration date with countdown ("X days remaining")
- Status badges: Active / Excused / Expired (SRO) / Expired (GBRO)
- Visual distinction for expired points (reduced opacity)
- Mobile-responsive design

---

## 🔧 Technical Implementation

### Backend Components

**Database Fields Added:**
```sql
-- Expiration tracking
expires_at           DATE           -- Calculated expiration date
expiration_type      ENUM('sro', 'gbro', 'none')  -- 'none' for FTN/NCNS until 1yr SRO fires
is_expired           BOOLEAN
expired_at           DATETIME                       -- full timestamp, not DATE

-- Violation details
violation_details    TEXT
tardy_minutes        INTEGER
undertime_minutes    INTEGER

-- GBRO tracking
eligible_for_gbro    BOOLEAN
gbro_expires_at      DATE          -- predicted date GBRO will fire for this point
gbro_applied_at      DATE
gbro_batch_id        VARCHAR(255)
```

**Key Files Modified:**
- ✅ `app/Models/AttendancePoint.php` - Added expiration logic methods
- ✅ `app/Services/AttendanceProcessor.php` - Auto-generates violation details and expiration dates
- ✅ `app/Http/Controllers/AttendancePointController.php` - Includes expiration in API responses
- ✅ `app/Console/Commands/ProcessPointExpirations.php` - Daily automated processing
- ✅ `routes/console.php` - Scheduled task configuration (Laravel 12)

**Artisan Command:**
```bash
# Manual execution
php artisan points:process-expirations
php artisan points:process-expirations --dry-run
php artisan points:process-expirations --force   # bypass same-day guard

# Automatic execution (configured)
# Runs daily at 8:05 AM via routes/console.php
```

### Frontend Components

**Pages Updated:**
- ✅ `resources/js/pages/Attendance/Points/Index.tsx` - Points list with expiration display
- ✅ `resources/js/pages/Attendance/Points/Show.tsx` - User detail with expiration info

**UI Features:**
- Clean table layout with "View Details" buttons
- Comprehensive violation details dialog
- Expiration countdown display
- Status badges (color-coded)
- Mobile-responsive cards
- Smart action menus (prevent operations on expired points)

---

## 📊 User Benefits

### For Employees
- ✅ Clear visibility into point status and expiration
- ✅ Automatic point removal through good behavior
- ✅ Fair and transparent expiration rules
- ✅ Incentive to maintain perfect attendance

### For Management
- ✅ Fully automated point management
- ✅ Reduced administrative overhead
- ✅ Fair and consistent application of rules
- ✅ Detailed violation tracking and audit trail
- ✅ Real-time statistics and reporting

### For System
- ✅ Self-maintaining database
- ✅ Scalable for large workforce
- ✅ Complete data retention for compliance
- ✅ Comprehensive logging

---

## 🎨 UI Screenshots Description

### Attendance Points List (Index)
**Desktop View:**
- 8-column table: Employee | Date | Type | Points | Status | Violation Details | Expires | Actions
- "View Details" button in Violation Details column
- Expiration date with countdown in Expires column
- Status badges show Active/Excused/Expired (SRO)/Expired (GBRO)
- Expired rows have reduced opacity
- Action dropdown prevents excuse/unexcuse for expired points

**Mobile View:**
- Responsive card layout
- Status badge at top-right
- Violation details button with preview
- Expiration information with countdown
- Smart action buttons (View, Excuse/Remove)

### Violation Details Dialog
**Opens when clicking "View Details" button:**
- Employee name and violation date
- Point type badge (color-coded)
- Points assigned (highlighted)
- Full violation description in highlighted box
- Tardy/undertime duration (if applicable)
- Expiration date with countdown
- Status badge (Active/Excused/Expired)
- Expired date (if already expired)
- Excuse information (if point was excused)

### User Points Detail (Show)
**Individual user attendance point history:**
- Same table layout as Index page
- Statistics card shows: Total Active / Excused / Expired
- Filtered to single user
- Same violation details dialog
- All expiration information displayed

---

## 📈 Statistics & Reporting

**Dashboard Cards Display:**
- Total Active Points
- Excused Points
- Expired Points (with count)
- Points by violation type

**Available Filters:**
- Active/Excused/Expired status
- Expiration type (SRO/GBRO)
- Date range
- Employee
- Violation type

---

## 🔄 Automated Processing

**Scheduled Task Details:**
```php
// routes/console.php (Laravel 12)
Schedule::command('points:process-expirations')
    ->dailyAt('08:05')
    ->withoutOverlapping()
    ->onOneServer();
```

**Processing Flow:**

**1. SRO Processing:**
   - Find all non-expired, non-excused points
   - Check if `expires_at` <= today
   - Mark as expired with type 'sro'
   - Set `expired_at` to current date

**2. GBRO Processing:**
   - Get all users with active GBRO-eligible points
   - For each user (same-day guard prevents double-processing):
     - Find most recent violation date across **all violation types** (NCNS, FTN, excused — everything)
     - Calculate `gbro_expires_at` = reference date + 60 days and store per-point
     - If `gbro_expires_at` has been reached:
       - Get 2 most recent non-excused, GBRO-eligible points
       - Mark as expired with type 'gbro'
       - Assign batch ID for tracking
       - Set `gbro_applied_at`
       - Reset clock for remaining points

**Output:**
Console output with per-user GBRO expiration messages and a final SRO/GBRO count summary.

---

## 💡 Business Logic Examples

### Example 1: GBRO Success
```
Employee: Sarah Johnson

Points before GBRO:
├─ Oct 1  - Tardy (0.25) ← oldest
├─ Oct 5  - Undertime (0.25)
├─ Oct 10 - Half-Day (0.50) ← most recent eligible
└─ Total: 1.00 points

After 60 clean days (Dec 10):
├─ Oct 10 (0.50) → Expired via GBRO ✨
├─ Oct 5  (0.25) → Expired via GBRO ✨
├─ Oct 1  (0.25) → Remains active
└─ New Total: 0.25 points
```

### Example 2: NCNS Resets the GBRO Clock (Not GBRO Eligible)
```
Employee: Mike Chen

Points:
├─ Nov 1 - NCNS (1.00) ← NOT eligible for GBRO, but resets the clock
├─ Nov 5 - Tardy (0.25)
└─ Nov 8 - Undertime (0.25)

GBRO clock starts from Nov 8 (most recent violation).
If a new NCNS occurs Nov 30, clock resets to Nov 30 even
though NCNS cannot be GBRO-removed.

After 60 clean days from most recent violation:
├─ NCNS stays (not GBRO eligible)
├─ Nov 8 Undertime → Expired via GBRO ✨
├─ Nov 5 Tardy → Expired via GBRO ✨
└─ Final: 1.00 (NCNS only)
```

### Example 3: Mixed Expirations
```
Employee: Lisa Rodriguez

Timeline:
├─ May 1  - Tardy (0.25)
│           Expires: Nov 1 (6 months) ← SRO
├─ May 15 - NCNS (1.00)
│           Expires: May 15, 2026 (1 year)
├─ June 1 - Undertime (0.25)
│           Expires: Dec 1 (6 months)

Result on Nov 1:
├─ May 1 Tardy → Expired via SRO ✨
├─ NCNS remains (long expiration)
├─ June 1 Undertime remains
└─ Total: 1.25 points

If 60 days clean after Nov 1:
├─ NCNS remains (not GBRO eligible)
├─ June 1 Undertime → Expired via GBRO ✨
└─ Final: 1.00 (NCNS only)
```

---

## 📝 Migration Details

**Migration File:** `2025_11_13_001305_add_expiration_fields_to_attendance_points_table.php`

**Fields Added:**
- Expiration tracking (4 fields)
- Violation details (3 fields)
- GBRO tracking (3 fields)

**Total:** 10 new columns to `attendance_points` table

**Backward Compatible:** ✅ All existing points continue to work

---

## 🚀 Deployment Checklist

- [x] Run database migration
- [x] Verify scheduled task is configured
- [x] Enable Laravel scheduler (cron job)
- [x] Test dry-run command
- [x] Verify frontend display
- [x] Check statistics calculations
- [x] Monitor first automated run
- [x] Train users on new UI features

---

## 📚 Documentation

**Main Documentation:**
- [POINT_EXPIRATION_RULES.md](POINT_EXPIRATION_RULES.md) - Complete expiration rules guide
- [README.md](README.md) - Attendance system overview

**Related:**
- [AUTOMATIC_POINT_GENERATION.md](AUTOMATIC_POINT_GENERATION.md) - Point generation rules
- [ATTENDANCE_GROUPING_LOGIC.md](ATTENDANCE_GROUPING_LOGIC.md) - Shift detection algorithm

---

## 🎉 Summary

This comprehensive implementation provides a **fully automated, fair, and transparent** attendance point expiration system that:

✅ Automatically expires old violations (SRO)  
✅ Rewards good behavior (GBRO)  
✅ Provides detailed violation information  
✅ Offers clean, user-friendly UI  
✅ Runs completely automatically  
✅ Scales to large workforce  
✅ Maintains complete audit trail  

**Production Ready:** Yes ✅  
**Last Updated:** May 13, 2026  
**Version:** 1.1.0

---

*For detailed technical documentation, see [POINT_EXPIRATION_RULES.md](POINT_EXPIRATION_RULES.md)*
