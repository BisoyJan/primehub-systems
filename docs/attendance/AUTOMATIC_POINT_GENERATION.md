# Automatic Attendance Point Generation

## Overview
The system automatically generates attendance points when attendance records are verified by an administrator. Points are created immediately after verification, ensuring accuracy and accountability.

## Implementation

### What Was Changed

#### 1. AttendanceProcessor Service (`app/Services/AttendanceProcessor.php`)
- Added `AttendancePoint` model import
- Added `regeneratePointsForAttendance()` method that runs during verification
- Added `mapStatusToPointType()` helper method

#### 2. AttendanceController (`app/Http/Controllers/AttendanceController.php`)
- Integrated point generation into `verify`, `batchVerify`, and `quickApprove` methods
- Ensures points are only generated for verified records

### How It Works

```
User Uploads Attendance File
    ↓
Parse & Process Attendance Records
    ↓
Create/Update Attendance Table (Unverified)
    ↓
Admin Verifies Record (Review Page)
    ↓
[NEW] Automatically Generate Points ✨
    ↓
Verification Complete
```

### Point Generation Logic

The system creates points for these verified attendance statuses:
- **NCNS** → Whole Day Absence (1.00 point)
- **Half-Day Absence** → Half-Day Absence (0.50 point)
- **Tardy** → Tardy (0.25 point)
- **Undertime** → Undertime (0.25 point)

**Duplicate Prevention:**
- Deletes existing points for the attendance record before regenerating
- Ensures 1-to-1 relationship between attendance violation and point record

### Performance Impact

**Minimal Impact:**
- Point generation happens during individual or batch verification
- Uses efficient database transactions
- Only processes records being verified

### Benefits

✅ **Always Accurate** - Points generated only for verified records
✅ **No Manual Work** - Eliminates need to manually create points
✅ **Real-time Visibility** - Users see points immediately after verification
✅ **Consistent Logic** - Same rules applied consistently
✅ **Audit Trail** - Points linked to specific attendance records

### Manual Rescan Still Available

The manual "Rescan" button remains available for:
- Regenerating points for historical date ranges
- Fixing points after attendance record corrections
- Backfilling points before this feature was implemented

### Code Example

```php
// In AttendanceController::verify()
$attendance->update($updates);

// Regenerate attendance points after verification
AttendancePoint::where('attendance_id', $attendance->id)->delete();

if (in_array($request->status, ['ncns', 'half_day_absence', 'tardy', 'undertime'])) {
    $this->processor->regeneratePointsForAttendance($attendance);
}
```

### Database Operations

For each record verified:
1. Update attendance status
2. Delete existing points for this record
3. If status is a violation, create new point
4. Notify user of status change and points


## Testing

To verify the feature works:

1. Upload an attendance file with some violations
2. Navigate to Attendance Points page
3. Points should appear immediately (no manual rescan needed)
4. Upload another file for same date → No duplicate points created

## Future Enhancements

Possible improvements:
- [ ] Queue-based processing for very large uploads
- [ ] Real-time notifications when points are generated
- [ ] Configurable rules for point values
- [ ] Bulk point excuse capabilities
- [ ] Point expiration/reset policies

---

## Critical Working Day (×2 Multiplier)

Attendance violations occurring on a **Critical Working Day** are doubled. This is applied at both manual point creation and automated point regeneration.

### How It Works

```php
// AttendancePointCreationService.php
$isCriticalDay = $data['is_critical_day'] ?? false;
$multiplier = $isCriticalDay ? 2.00 : 1.00;
$basePoints = AttendancePoint::POINT_VALUES[$pointType];  // e.g. 0.25 for Tardy

AttendancePoint::create([
    'points'          => $basePoints * $multiplier,  // e.g. 0.50 for Tardy on critical day
    'multiplier'      => $multiplier,
    'is_critical_day' => $isCriticalDay,
    ...
]);
```

The same logic applies in `AttendanceProcessor::regeneratePointsForAttendance()` when automated points are generated during verification:

```php
// AttendanceProcessor.php
$multiplier = $attendance->is_critical_day ? 2.00 : 1.00;
```

### Effective Point Values on Critical Days

| Violation | Base Points | Critical Day Points |
|-----------|-------------|--------------------|
| Tardy | 0.25 | 0.50 |
| Undertime | 0.25 | 0.50 |
| Half-Day Absence | 0.50 | 1.00 |
| NCNS (Whole Day) | 1.00 | 2.00 |

### Where `is_critical_day` is set

- **Attendance record** (`attendances.is_critical_day`) — toggled by Admin/HR during verification (via `VerifyAttendanceRequest`, `BatchVerifyAttendanceRequest`).
- **Attendance point** (`attendance_points.is_critical_day`) — copied from the attendance record when points are auto-generated; also settable on manual points via `StoreAttendancePointRequest` / `UpdateAttendancePointRequest`.
- **BulkCreate form** — each entry in `BulkStoreAttendancePointRequest` accepts `entries.*.is_critical_day`.

> Changing `is_critical_day` on an already-verified attendance record triggers automatic point regeneration to reflect the new multiplier.

---

## Related Files

- `app/Services/AttendanceProcessor.php` - Main processing logic + critical day multiplier
- `app/Services/AttendancePoint/AttendancePointCreationService.php` - Manual point creation with multiplier
- `app/Http/Controllers/AttendancePointController.php` - Point management
- `app/Models/AttendancePoint.php` - Point model with POINT_VALUES constant
- `app/Http/Controllers/AttendanceController.php` - Upload + verify handler

---

**Implemented:** November 12, 2025
**Critical Working Day feature:** July 2026
**Performance Impact:** Minimal (~0.5-1 second per upload)
**Status:** ✅ Active in Production
