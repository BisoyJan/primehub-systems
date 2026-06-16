# Employee Schedules Documentation

This document covers the employee schedule system, shift types, time validation, and how schedules integrate with attendance processing.

---

## 📋 Overview

Employee schedules define when employees are expected to work. Each schedule includes:
- **Shift Type** - Category of work shift (morning, afternoon, night, graveyard, 24h utility)
- **Time In/Out** - Exact start and end times
- **Work Days** - Days of the week the employee works
- **Grace Period** - Minutes late before considered tardy
- **Effective Date** - When the schedule starts (hired date)

---

## 🕐 Shift Types

### Available Shift Types

`shift_type` is derived server-side from `scheduled_time_in` (and the `is_utility` flag); admins do not pick it manually.

| Shift Type | Label | Time-In Window | Crosses Midnight |
|------------|-------|----------------|------------------|
| `morning_shift` | 🌅 Morning Shift | 05:00–11:59 | No |
| `afternoon_shift` | 🌤️ Afternoon Shift | 12:00–17:59 | No |
| `night_shift` | 🌙 Night Shift | 18:00–04:59 (absorbs former graveyard window) | Yes |
| `utility_24h` | 🔄 24H Utility | Any (requires the `is_utility` flag) | Varies |

### Derivation Rule

The canonical rule lives in two mirrored places — keep them in sync:

- PHP: `EmployeeScheduleController::deriveShiftType()` — [app/Http/Controllers/EmployeeScheduleController.php](../../app/Http/Controllers/EmployeeScheduleController.php)
- TypeScript: `deriveShiftType()` in [resources/js/lib/shift-type.ts](../../resources/js/lib/shift-type.ts)

```
is_utility = true        → utility_24h
else hour(time_in) ∈ 5..11  → morning_shift
else hour(time_in) ∈ 12..17 → afternoon_shift
else                       → night_shift
```

There is no UI time-range warning anymore — the time itself decides the bucket.

---

## 🔄 How Shift Type Affects Attendance Processing

### Night Shift Detection

The `isNightShift()` method in `EmployeeSchedule.php` determines if attendance records should look for time-out on the **next calendar day**:

```php
public function isNightShift(): bool
{
    return $this->shift_type === 'night_shift' ||
           $this->scheduled_time_in >= '20:00:00';
}
```

### Example: Night Shift Processing

For a Night Shift employee working **22:00 - 07:00**:
- **Clock in**: Dec 15, 2025 at 22:00
- **Clock out**: Dec 16, 2025 at 07:00
- **Shift Date**: Dec 15, 2025 (based on clock-in date)

The algorithm automatically looks for time-out records on Dec 16th.

### What the Backend Uses

The attendance algorithm uses:
1. **`shift_type` field** - To determine if shift crosses midnight
2. **`scheduled_time_in`** - Exact start time for tardiness calculation
3. **`scheduled_time_out`** - Exact end time for undertime/overtime calculation

The UI time range validation is **informational only** - it doesn't affect backend processing.

---

## 🖥️ UI Components

### Create/Edit Schedule Form

Located at:
- `resources/js/pages/Attendance/EmployeeSchedules/Create.tsx`
- `resources/js/pages/Attendance/EmployeeSchedules/Edit.tsx`

Features:
- **Time-first entry** — admins enter Time In / Time Out; `shift_type` is derived live and shown as a read-only "Detected shift" badge.
- **24-Hour Utility toggle** — a `<Switch>` (hidden on first-time setup) sends `is_utility` to the backend; this is the only shift not derivable from a clock.
- **12h/24h format** — respects user's time format preference.

### Frontend Helper

The shared derivation helper lives at `resources/js/lib/shift-type.ts` and mirrors the PHP `deriveShiftType()` exactly:

```typescript
export function deriveShiftType(timeIn: string, isUtility: boolean): ShiftType {
    if (isUtility) return 'utility_24h';
    const hour = parseInt(timeIn.slice(0, 2), 10);
    if (hour >= 5 && hour < 12)  return 'morning_shift';
    if (hour >= 12 && hour < 18) return 'afternoon_shift';
    return 'night_shift'; // 18:00–04:59 (absorbs former graveyard)
}
```

**Important:** The backend re-derives `shift_type` on every `store`/`update` — the frontend value is for display only.

---

## 📊 Database Schema

```sql
CREATE TABLE employee_schedules (
    id BIGINT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    campaign_id BIGINT NULL,
    site_id BIGINT NULL,
    shift_type ENUM('morning_shift', 'afternoon_shift', 'night_shift', 'utility_24h'),
    scheduled_time_in TIME NOT NULL,
    scheduled_time_out TIME NOT NULL,
    work_days JSON NOT NULL,  -- e.g., ["monday", "tuesday", "wednesday", "thursday", "friday"]
    grace_period_minutes INTEGER DEFAULT 0,
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
    FOREIGN KEY (site_id) REFERENCES sites(id)
);
```

---

## 🔗 Related Files

### Backend
- `app/Models/EmployeeSchedule.php` - Eloquent model with `isNightShift()` method
- `app/Http/Controllers/EmployeeScheduleController.php` - CRUD operations
- `app/Services/AttendanceProcessor.php` - Uses schedule for attendance processing

### Frontend
- `resources/js/pages/Attendance/EmployeeSchedules/Index.tsx` - List view
- `resources/js/pages/Attendance/EmployeeSchedules/Create.tsx` - Create form
- `resources/js/pages/Attendance/EmployeeSchedules/Edit.tsx` - Edit form

### Tests
- `tests/Feature/Controllers/Schedules/EmployeeScheduleControllerTest.php`
- `tests/Unit/Models/EmployeeScheduleTest.php`

---

## ❓ FAQ

### Q: Does changing UI time ranges affect attendance processing?
**A:** No. The attendance algorithm uses the actual `scheduled_time_in` and `scheduled_time_out` values stored in the database. UI validation ranges are informational only.

### Q: Can I use custom times outside the recommended range?
**A:** Yes. A warning will appear, but submission is not blocked. The attendance algorithm will process any valid time combination correctly.

### Q: How does the system know if a shift crosses midnight?
**A:** The `isNightShift()` method checks if `shift_type === 'night_shift'` OR `scheduled_time_in >= '20:00:00'`. This determines whether to look for time-out on the next day.

### Q: What happens if an employee has multiple schedules?
**A:** Only one schedule can be active (`is_active = true`) at a time. Activating a new schedule will deactivate previous ones.

---

## 📝 Changelog

### December 2025
- Added flexible time range validation (UI only)
- Updated shift type select options to show both 24h and 12h formats
- Added help dialog with time range guide
- Improved warning messages for time mismatches
