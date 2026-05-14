# GBRO Anomaly System

Automatic detector + repair safety net for GBRO/SRO drift on attendance points.

## What it does

The system continuously catches data anomalies introduced by edge cases in attendance writes (admin edits, excuse/unexcuse, scheduled processing, manual point creation). When drift is detected it logs every finding to `gbro_anomaly_logs` and (unless dry-run) repairs it by composing existing infrastructure — `AttendancePointMaintenanceService::fixAnomalies()` + `GbroCalculationService::cascadeRecalculateGbro()`.

## Anomaly types

| Type | Meaning | Auto-fix |
|------|---------|----------|
| `STALE_PENDING_GBRO` | `gbro_expires_at <= today` but row is not expired and not excused | Mark expired with `expiration_type=gbro`, `expired_at=now`, `gbro_applied_at=gbro_expires_at` |
| `STALE_PENDING_SRO` | `expires_at <= today` but row is not expired and not excused | Maintenance expires with SRO |
| `ORPHAN_GBRO_DATE` | `eligible_for_gbro=false` but `gbro_expires_at` is set (e.g. NCNS) | Maintenance clears `gbro_expires_at` |
| `EXCUSED_HAS_GBRO_DATE` | Excused row still carries a `gbro_expires_at` | Maintenance clears `gbro_expires_at` |
| `GBRO_ELIGIBILITY_MISMATCH` | `eligible_for_gbro` flag conflicts with point status (3-case rule from `fixAnomalies`) | Maintenance flips the flag, then per-user cascade re-elects top-2 |
| `EXPIRES_AT_OVERFLOW` | `expires_at` was set with naive `addMonths(6)`/`addYear()` and overflowed past month-end | Maintenance recomputes with `addMonthsNoOverflow` / `addYearNoOverflow` |

## Triggers

Every audit run is tagged with a trigger so you can filter the dashboard by source:

| Trigger | Where it fires |
|---------|----------------|
| `unexcuse` | After `AttendancePointController::unexcuse()` cascades |
| `excuse` | After `AttendancePointController::excuse()` cascades |
| `manual_write` | After `AttendanceWriteService::regeneratePoints()` (admin-edited attendance) |
| `manual_point_create` | After `AttendancePointCreationService::createManualPoint()` |
| `manual_point_update` | After `AttendancePointCreationService::updateManualPoint()` |
| `manual_point_delete` | After `AttendancePointCreationService::deleteManualPoint()` |
| `scheduled` | Daily 8:23 AM cron (`points:audit-gbro`) |
| `manual_run` | Admin clicked "Run audit & repair" / "Dry-run audit" on the dashboard |

All hooks wrap the audit call in `try/catch` + `Log::error` — an audit failure can never break the user-facing operation.

## Components

### Migration & Model
- [`gbro_anomaly_logs` table](../../database/migrations/2026_05_14_234359_create_gbro_anomaly_logs_table.php) — indexed on `batch_id`, `trigger`, `type`, `repaired`, `(user_id, type)`, `(created_at, repaired)`
- [`App\Models\GbroAnomalyLog`](../../app/Models/GbroAnomalyLog.php) — `user()` and `attendancePoint()` relations; `context` cast to array

### Service — `App\Services\AttendancePoint\GbroAnomalyService`
- `detect(?int $userId = null): Collection` — pure read; runs all 6 detection queries
- `repair(?int $userId = null, string $trigger = 'manual_run', bool $dryRun = false): array` — generates `batch_id = 'audit_<YmdHis>_<rand>'`, persists findings, then (unless dry-run) calls `fixAnomalies()` + per-user cascade + defensive sweep, returns:
  ```
  ['batch_id', 'detected', 'repaired', 'affected_users', 'by_type', 'maintenance']
  ```

### Console command
```bash
php artisan points:audit-gbro                 # run + repair, all users
php artisan points:audit-gbro --dry-run       # detect + log only
php artisan points:audit-gbro --user=42       # scope to one user
```
Pretty table output with per-type counts and maintenance summary. Scheduled daily at 8:23 AM via [`routes/console.php`](../../routes/console.php) — runs after the 8:05 AM `points:process-expirations`.

### Admin dashboard
Navigate to `/attendance-points/management/anomaly-logs` (requires `attendance_points.manage`).

Provides:
- 4 stats cards: Total logged, Unrepaired, Last 24h, Distinct types
- Filters: type, trigger, repaired, user_id, batch_id
- Two action buttons: **Dry-run audit** (detect-only, persists findings) and **Run audit & repair**
- Desktop table + mobile card list with link to the affected user's points page

## How auto-firing works

```
User excuses a point ─► cascadeRecalculateGbro($userId)
                       └─► GbroAnomalyService::repair($userId, 'excuse')
                            ├─ detect() returns 0..N anomalies
                            ├─ persist to gbro_anomaly_logs
                            ├─ fixAnomalies() (if any)
                            ├─ per-affected-user cascade
                            └─ defensive sweep of remaining stale gbro_expires_at
```

Same path for `unexcuse`, `manual_write` (admin-created/edited attendance), and the three `manual_point_*` triggers (admin-created/edited/deleted attendance points). The scheduled command and dashboard buttons go through the exact same `repair()` entry point — the only thing that changes is the `trigger` tag.

## Operational playbook

1. **Daily check** — open the dashboard. If "Last 24h" is consistently > 0, look at the most common `type` to find the upstream bug.
2. **One user is suspect** — run `php artisan points:audit-gbro --user=<id> --dry-run` to inspect, then drop `--dry-run` to fix.
3. **Investigate a single audit run** — copy the `batch_id` from the dashboard and paste it into the Batch ID filter to see all findings from that run.
4. **Already-fixed rows reappear** — every auto-fire repairs immediately, so a row should appear at most once per drift event. Repeated `STALE_PENDING_GBRO` for the same point/user means the upstream write path is recreating the bad state — check `trigger` to find the source.

## Tests

- [`tests/Feature/AttendancePoint/GbroAnomalyServiceTest.php`](../../tests/Feature/AttendancePoint/GbroAnomalyServiceTest.php) — 10 tests covering detection per type, repair persistence, dry-run mode, per-user scoping, and the artisan command (default + `--dry-run`).
- Full attendance suite remains green (146 passed, 1 skipped, 1 pre-existing failure unrelated to this work).
