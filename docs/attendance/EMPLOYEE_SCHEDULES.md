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

| Shift Type | Label | Default Times | Crosses Midnight |
|------------|-------|---------------|------------------|
| `morning_shift` | 🌅 Morning Shift | 05:00 - 14:00 | No |
| `afternoon_shift` | 🌤️ Afternoon Shift | 14:00 - 23:00 | No |
| `night_shift` | 🌙 Night Shift | 22:00 - 07:00 | Yes |
| `graveyard_shift` | 🌃 Graveyard Shift | 00:00 - 09:00 | Yes |
| `utility_24h` | 🔄 24H Utility | Any | Varies |

### Recommended Time Ranges

These are flexible ranges for UI validation guidance. Custom times within these ranges are valid:

| Shift Type | Time In Range | Time Out Range |
|------------|---------------|----------------|
| Morning | 04:00-09:00 (4AM-9AM) | 12:00-17:00 (12PM-5PM) |
| Afternoon | 11:00-16:00 (11AM-4PM) | 19:00-00:00 (7PM-12AM) |
| Night | 18:00-23:00 (6PM-11PM) | 04:00-10:00 (4AM-10AM next day) |
| Graveyard | 22:00-02:00 (10PM-2AM) | 05:00-11:00 (5AM-11AM) |
| 24H Utility | No restrictions | No restrictions |

**Note:** Ranges may overlap between shift types. This is intentional to allow flexibility.

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
- **Auto-fill times** - Selecting a shift type auto-fills default times
- **Time range warning** - Shows informational warning if times are outside recommended range (doesn't block submission)
- **Help dialog** - Click ❓ icon to see recommended time ranges
- **12h/24h format** - Respects user's time format preference

### Time Range Validation

The frontend validates times against flexible ranges:

```typescript
const SHIFT_TIME_RANGES = {
    morning_shift: { timeInMin: 4, timeInMax: 9, timeOutMin: 12, timeOutMax: 17 },
    afternoon_shift: { timeInMin: 11, timeInMax: 16, timeOutMin: 19, timeOutMax: 24 },
    night_shift: { timeInMin: 18, timeInMax: 23, timeOutMin: 4, timeOutMax: 10 },
    graveyard_shift: { timeInMin: 22, timeInMax: 26, timeOutMin: 5, timeOutMax: 11 },
};
```

**Important:** This is UI guidance only. Any valid time can be saved and will be processed correctly by the attendance algorithm.

---

## 📊 Database Schema

```sql
CREATE TABLE employee_schedules (
    id BIGINT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    campaign_id BIGINT NULL,
    site_id BIGINT NULL,
    shift_type ENUM('morning_shift', 'afternoon_shift', 'night_shift', 'graveyard_shift', 'utility_24h'),
    scheduled_time_in TIME NOT NULL,
    scheduled_time_out TIME NOT NULL,
    work_days JSON NOT NULL,  -- e.g., ["monday", "tuesday", "wednesday", "thursday", "friday"]
    grace_period_minutes INTEGER DEFAULT 15,
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
