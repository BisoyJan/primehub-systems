# Attendance Status State Machine

> Source: `app/Models/Attendance.php`, `app/Services/AttendanceProcessor.php`, `app/Http/Controllers/AttendanceController.php`
> Last updated: 2026-05-07

---

## Primary Status Values

| Status | Description | Set By | Badge Color |
|--------|-------------|--------|-------------|
| `on_time` | Employee clocked in within grace window | Processor / verify | Green |
| `tardy` | Clock-in after grace window, < 50% of shift | Processor / verify | Yellow |
| `half_day_absence` | Clock-in more than halfway through shift | Processor / verify | Orange |
| `advised_absence` | Absent, but absence was pre-authorized | Admin verify | Blue |
| `ncns` | No call, no show — absent with no record | Processor (absent detection) / verify | Red |
| `undertime` | Left more than 0 but ≤ 60 min early | Processor / verify | Orange |
| `undertime_more_than_hour` | Left more than 60 min early | Processor / verify | Red |
| `failed_bio_in` | Biometric time-in missing | Processor / verify | Purple |
| `failed_bio_out` | Biometric time-out missing | Processor / verify | Purple |
| `present_no_bio` | Confirmed present but no bio record | Admin verify | Gray |
| `non_work_day` | Biometric scan on a scheduled day-off | Processor | Gray |
| `on_leave` | Approved leave covers this date | Processor (leave check) / verify | Blue |
| `needs_manual_review` | Processor detected ambiguous scan pattern | Processor | Amber |

---

## Secondary Status Values

`secondary_status` captures an *additional* violation alongside the primary status.

| Value | Meaning |
|-------|---------|
| `undertime` | Primary is tardy/on_time but employee also left ≤ 60 min early |
| `undertime_more_than_hour` | Primary is tardy/on_time but employee also left > 60 min early |
| `failed_bio_out` | Primary is on_time/tardy but time-out scan is missing |

---

## State Transitions

### Auto-assigned by `AttendanceProcessor` (upload pipeline)

```
Bio record ingested
        │
        ├─→ Approved leave found ──────────────────→ on_leave
        │
        ├─→ No schedule ──────────────────────────→ needs_manual_review
        │
        ├─→ Schedule found, non-work day ─────────→ non_work_day
        │
        ├─→ Schedule found, work day
        │         │
        │         ├─→ No time-in scan ─────────────→ failed_bio_in
        │         │
        │         ├─→ No time-out scan
        │         │         ├─→ single scan ───────→ failed_bio_in
        │         │         └─→ partial ───────────→ failed_bio_out (secondary)
        │         │
        │         ├─→ Double punch (<10 min gap) ──→ warnings added, time-out cleared
        │         │
        │         ├─→ Extreme scan pattern ─────────→ needs_manual_review + warnings
        │         │
        │         ├─→ Utility 24h shift
        │         │         ├─→ ≥ 8 hrs worked ───→ on_time
        │         │         └─→ < 8 hrs worked ───→ undertime
        │         │
        │         └─→ Normal shift
        │                   ├─→ within grace ──────→ on_time
        │                   ├─→ > grace, < 50% ───→ tardy
        │                   ├─→ > 50% of shift ───→ half_day_absence
        │                   └─→ left early
        │                             ├─→ ≤ 60 min → undertime (secondary)
        │                             └─→ > 60 min → undertime_more_than_hour (secondary)
        │
        └─→ No bio record found for employee ─────→ ncns
```

### After `detectAbsentEmployees`

Employees with an active schedule on a shift date but **zero biometric records** receive an `ncns` attendance record.

### Manual via `AttendanceController::verify`

Admin can change `status` to **any** of the 12 settable values (all except `needs_manual_review` which is system-only). Verification also recalculates:

- `tardy_minutes` from `actual_time_in` vs `scheduled_time_in`
- `undertime_minutes` from `actual_time_out` vs `scheduled_time_out`
- `overtime_minutes` from `actual_time_out` vs `scheduled_time_out`
- `secondary_status` based on combined time-in/time-out deltas

### Batch via `AttendanceController::batchVerify`

Sets the same status on multiple attendance records in one operation. Fields changed: `status`, `secondary_status`, `verification_notes`, `overtime_approved`, `is_set_home`.

---

## Points Generation

Points are **not** generated automatically during upload. They are generated only when an admin calls `generatePoints` or `bulkGeneratePoints` after verification. Point generation is gated on `admin_verified = true`.

### Status → Point type mapping (defined in `GbroCalculationService`)

| Status | Point Generated |
|--------|----------------|
| `tardy` | Tardy point |
| `ncns` | NCNS point |
| `undertime` / `undertime_more_than_hour` | Undertime point |
| `half_day_absence` / `advised_absence` | Absence point |
| `failed_bio_in` / `failed_bio_out` | Bio-failure point |
| `on_time` / `on_leave` / `non_work_day` | No point |

---

## Related Enums

- `App\Enums\AttendanceStatus` — BackedEnum of all settable primary status values
- `App\Enums\AttendanceSecondaryStatus` — BackedEnum of secondary status values

> **Note (4.10):** Applying `AttendanceStatus` as an Eloquent cast on `Attendance::$status` requires migrating ~100 string comparisons throughout the codebase. Pending a dedicated migration sprint.
