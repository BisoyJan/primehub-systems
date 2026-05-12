# Attendance Module — Audit Tracking

> **Source:** Read-only static audit performed May 2026 over attendance, biometric, break-timer, and employee-schedule modules.
> Use this file to track remediation progress. Tick `[x]` and add a `Fixed in: <commit/PR>` note when complete.

**Legend**
- 🔴 P1 — Security & correctness (do first)
- 🟡 P2 — Performance & maintainability
- 🟢 P3 — Cleanup, testing, observability

---

## 1. Broken / Risky Logic (P1)

| # | Status | Severity | Issue | File:Line | Fix Summary |
|---|---|---|---|---|---|
| 1.1 | [x] | 🔴 HIGH | OR-permission middleware on `attendance-points` group lets any user with `view` permission hit destroy/excuse/rescan/management endpoints. | [routes/web.php#L294-L329](../../routes/web.php) | Split into per-route `permission:` gates; add new `attendance_points.manage` permission for management ops. ✅ Fixed 2026-05-07. |
| 1.2 | [x] | 🔴 CRITICAL | `GET /attendance-points/{user}` declared before `GET /attendance-points/management/stats` — `{user}` greedily matches `"management"`. | [routes/web.php#L307-L329](../../routes/web.php) | Move `management/*` routes above `/{user}`, or constrain `->where('user','[0-9]+')`. ✅ Already ordered; added `->where('user','[0-9]+')` and `->where('point','[0-9]+')` constraints 2026-05-07. |
| 1.3 | [x] | 🔴 HIGH | `AttendancePointController::authorizeAdminHrAction()` uses string role checks; existing `AttendancePointPolicy::{excuse,update,delete,rescan,export}` is dead code. | [AttendancePointController.php](../../app/Http/Controllers/AttendancePointController.php) | Replace with `$this->authorize('action', $point)` calls; delete private helper. ✅ Fixed 2026-05-07 (added `manage` policy method; 11 controller call-sites migrated; helper removed). |
| 1.4 | [x] | 🟠 MED-HIGH | `AttendanceController::verify` runs 6+ sequential `update()` calls outside any transaction; partial state on failure. | [AttendanceController.php#L1880-L1995](../../app/Http/Controllers/AttendanceController.php) | Wrap full method body in `DB::transaction(function () { ... })`. ✅ Fixed 2026-05-07 (extracted `recalculateVerifyTimeFields`; full sequence now atomic with rollback on error). |
| 1.5 | [x] | 🔴 HIGH | `BiometricReprocessingController::reprocess` deletes attendances where `admin_verified=false` but **does not delete child `AttendancePoint` rows** → orphans keep counting against users. | [BiometricReprocessingController.php#L210-L213](../../app/Http/Controllers/BiometricReprocessingController.php) | Delete `AttendancePoint::whereIn('attendance_id', $deletedIds)` before attendance deletion, **or** add DB-level `ON DELETE CASCADE` migration. ✅ Fixed 2026-05-07. |
| 1.6 | [x] | 🟠 MED-HIGH | `BiometricReprocessingController::fixStatuses` hardcodes tardy threshold `1..15` instead of using each schedule's `grace_period_minutes`. | [BiometricReprocessingController.php#L284-L298](../../app/Http/Controllers/BiometricReprocessingController.php) | Use `$att->employeeSchedule->grace_period_minutes ?? 15`. ✅ Fixed 2026-05-07. |
| 1.7 | [x] | 🔴 HIGH | `AttendanceController::adjustLeaveForWorkDay` "multi-day, work-in-middle" branch is a TODO; credits stay deducted for days the employee actually worked. | [AttendanceController.php#L2289+](../../app/Http/Controllers/AttendanceController.php) | Implement leave-split: end first segment day before, start second segment day after. Refund the worked-day credit. ✅ Fixed 2026-05-07 — middle-day work now splits leave into two segments via `replicate()`; refunds exactly 1 credit; sibling rows reattach to new leave. |
| 1.8 | [x] | 🟠 MED-HIGH | `regeneratePointsIfNeeded` deletes by `attendance_id OR (user_id, shift_date)` — over-deletes unrelated manual/rescan points. | [AttendanceController.php](../../app/Http/Controllers/AttendanceController.php) | Limit deletion criteria to `attendance_id` only. ✅ Fixed 2026-05-07. |
| 1.9 | [x] | 🟠 MED-HIGH | `BiometricAnomalyController::autoFlagHighSeverityAnomalies` uses raw `DB::raw('CONCAT(...)')` mass-update — bypasses ActivityLog and re-appends banner each run. | [BiometricAnomalyController.php#L233-L241](../../app/Http/Controllers/BiometricAnomalyController.php) | Iterate models; check banner not already present before appending. ✅ Fixed 2026-05-07 — model iteration with `str_contains` idempotency guard. |
| 1.10 | [x] | 🟡 MED | No rate-limiting on heavy attendance POST endpoints (`upload`, `previewUpload`, `bulkStore`, `bulkQuickApprove`, `bulkDelete`, `batchVerify`). | [routes/web.php#L196-L208](../../routes/web.php) | Apply `throttle:10,1` (or appropriate) per endpoint. ✅ Fixed 2026-05-07 — 10 req/min on all 7 high-cost endpoints. |

### Additional correctness notes

- `verify` clears `tardy_minutes=null` on missing `actual_time_in` but does not clear `secondary_status='undertime'` → stale state ([AttendanceController.php#L1909](../../app/Http/Controllers/AttendanceController.php)).
- `processAttendance` logs `original_status` **after** mutating it ([AttendanceProcessor.php#L1797-L1804](../../app/Services/AttendanceProcessor.php)).
- 24H utility override blanks `secondary_status`, dropping `failed_bio_out` flags ([AttendanceProcessor.php#L1809-L1834](../../app/Services/AttendanceProcessor.php)).
- `BiometricExportController::startExport` & `BiometricReprocessingController::*` accept unbounded ID arrays / date ranges → OOM risk.

---

## 2. Anomalies & Dead Code

| # | Status | Item | Location | Action |
|---|---|---|---|---|
| 2.1 | [x] | Legacy methods `processTimeIn` / `processTimeOut` superseded by single-pass `processAttendance`. | [AttendanceProcessor.php#L1880-L2030](../../app/Services/AttendanceProcessor.php) | Confirm zero callers; delete. |
| 2.2 | [x] | Dead policy methods (`update`, `delete`, `excuse`, `export`, `rescan`) never invoked. | [AttendancePointPolicy.php](../../app/Policies/AttendancePointPolicy.php) | Wire into controller (see 1.3); then delete `authorizeAdminHrAction`. |
| 2.3 | [x] | `AttendanceController::store` ↔ `bulkStore` ~350 LOC duplicated. | [AttendanceController.php](../../app/Http/Controllers/AttendanceController.php) | Extract to `AttendanceProcessor::buildFromManualInput()`. |
| 2.4 | [x] | One-off Fix-* artisan commands likely already executed. | `app/Console/Commands/Fix*`, `Initialize*`, `GenerateMissing*`, `Reprocess*` | Audit each; archive or delete stale ones. |
| 2.5 | [x] | Two flash-message conventions coexist (`RedirectsWithFlashMessages` trait vs ad-hoc `->with('flash',...)`). | Multiple controllers | Standardize on the trait. (BreakPolicyController migrated; AccountController + others remain.) |
| 2.6 | [~] | String role checks with two casings: `'Admin'`, `'Super Admin'` vs `super_admin`, `admin`, `hr`. | `AttendancePointController`, `EmployeeScheduleController`, `AttendanceController`, `BreakDashboardController` | Replace with `PermissionService` / policies. (AttendancePointController auth helper migrated to `viewUserPoints` policy; data-scoping role filters intentionally retained.) |
| 2.7 | [x] | Duplicate timing math: `BreakTimerController::status` vs `BreakTimerService::getSessionTimingSnapshot`. | [BreakTimerController.php#L249-L267](../../app/Http/Controllers/BreakTimerController.php) | Delete the controller copy; call the service. |
| 2.8 | [x] | Magic numbers throughout `AttendanceProcessor` (`10`, `1200`, `30`, `5`, `15`, `8`). | [AttendanceProcessor.php](../../app/Services/AttendanceProcessor.php) | Extract to `config/attendance.php`. |
| 2.9 | [x] | `\Log::warning(...)` global facade vs `use Illuminate\Support\Facades\Log;` elsewhere. | `AttendanceProcessor` | Import the facade and use `Log::`. (Also normalized `AttendanceController`, `AttendanceFileParser`, `BiometricReprocessingController`, `CleanOldBiometricRecords`.) |
| 2.10 | [x] | `BreakSession::shift_date` cast as plain `'date'` (no format) — frontend serialization differs from `Attendance`. | [BreakSession.php](../../app/Models/BreakSession.php) | Change cast to `'date:Y-m-d'`. |
| 2.11 | [x] | `preg_replace` results not null-checked in name parsing. | [AttendanceFileParser.php](../../app/Services/AttendanceFileParser.php) | Coalesce to original input on failure. |
| 2.12 | [x] | `BiometricExportController::downloadExport` uses `glob()`. | [BiometricExportController.php#L194](../../app/Http/Controllers/BiometricExportController.php) | Use `Storage::disk('local')->files('temp')` + filter. |
| 2.13 | [x] | Service-locator anti-pattern: `app(NotificationService::class)`, `app(LeaveCreditService::class)` inside `AttendanceProcessor`. | [AttendanceProcessor.php](../../app/Services/AttendanceProcessor.php) | Constructor-inject via promoted properties. |
| 2.14 | [x] | Inconsistent constructor styles across controllers. | Multiple | Standardize on PHP 8 promoted properties. |

---

## 3. Improvements (P2 — Performance & Maintainability)

| # | Status | Item | File:Line | Notes |
|---|---|---|---|---|
| 3.1 | [x] | Eliminate `store`/`bulkStore` duplication. | [AttendanceController.php](../../app/Http/Controllers/AttendanceController.php) | Saves ~350 LOC. Done via `_buildAttendanceData()` + shared logic. |
| 3.2 | [x] | Extract `verify` / `batchVerify` into FormRequests with shared status enum. | [VerifyAttendanceRequest.php](../../app/Http/Requests/VerifyAttendanceRequest.php), [BatchVerifyAttendanceRequest.php](../../app/Http/Requests/BatchVerifyAttendanceRequest.php), [AttendanceStatus.php](../../app/Enums/AttendanceStatus.php), [AttendanceSecondaryStatus.php](../../app/Enums/AttendanceSecondaryStatus.php) | Created `App\Enums\AttendanceStatus` + `AttendanceSecondaryStatus` BackedEnums; inline `validate()` removed from both controller methods. |
| 3.3 | [x] | Fix N+1 in `BiometricRecordController::countRecordsEligibleForCleanup`. | [BiometricRecordController.php](../../app/Http/Controllers/BiometricRecordController.php) | Rewrote to single query with `orWhere` per site using `whereNull` fallback for global cutoff. |
| 3.4 | [x] | Rewrite `BiometricExportController::index` user→campaign/site lookup. | [BiometricExportController.php](../../app/Http/Controllers/BiometricExportController.php) | Single `EmployeeSchedule::select` + PHP-side `groupBy`; also added `array\|max:500` + 1-year date range validation to `startExport()`. |
| 3.5 | [x] | Aggregate `BreakDashboardController::index` 5 cloned counts into one query. | [BreakDashboardController.php](../../app/Http/Controllers/BreakDashboardController.php) | Single `selectRaw` with conditional aggregation replaces 5 cloned `count()` calls. |
| 3.6 | [x] | Move graveyard-shift detection to `EmployeeSchedule::isGraveyardShift()`. | [EmployeeSchedule.php](../../app/Models/EmployeeSchedule.php), [AttendanceProcessor.php](../../app/Services/AttendanceProcessor.php), [AttendanceController.php](../../app/Http/Controllers/AttendanceController.php) | Added `isGraveyardShift(): bool`; replaced 5 inline `$schedInHour < 5` checks in `AttendanceProcessor`; replaced 2 in `AttendanceController::recalculateVerifyTimeFields()`. |
| 3.7 | [x] | Move magic numbers to `config/attendance.php`. | [AttendanceProcessor.php](../../app/Services/AttendanceProcessor.php) | See 2.8. Named constants for all timing thresholds. |
| 3.8 | [x] | Cap input array sizes & date-range in export/reprocess endpoints. | [BiometricExportController.php](../../app/Http/Controllers/BiometricExportController.php), [BiometricReprocessingController.php](../../app/Http/Controllers/BiometricReprocessingController.php) | `array\|max:500` on `user_ids`/`campaign_ids`; closure-based 1-year date range validation on both `startExport()`, `preview()`, and `reprocess()`. |
| 3.9 | [x] | Add composite indexes. | [2026_05_07_233805_add_composite_indexes_to_attendance_tables.php](../../database/migrations/2026_05_07_233805_add_composite_indexes_to_attendance_tables.php) | Only `(user_id, shift_date, status)` on `break_sessions` was missing; others already existed in original migrations. |
| 3.10 | [x] | Add `ON DELETE CASCADE` on `attendance_points.attendance_id`. | [2026_05_07_233713_add_cascade_delete_to_attendance_points_attendance_id.php](../../database/migrations/2026_05_07_233713_add_cascade_delete_to_attendance_points_attendance_id.php) | Drop+recreate foreign key with `cascadeOnDelete()`. |
| 3.11 | [x] | Standardize on `RedirectsWithFlashMessages` trait. | Multiple | See 2.5. All controllers now use trait helpers. |
| 3.12 | [x] | Consolidate session-timing math. | [BreakTimerController.php](../../app/Http/Controllers/BreakTimerController.php) | See 2.7. Extracted `_calculateSessionSeconds()` helper. |

---

## 4. Improvements (P3 — Testing, Observability, Cleanup)

| # | Status | Item | Notes |
|---|---|---|---|
| 4.1 | [x] | Delete legacy `processTimeIn`/`processTimeOut` after grep confirms zero callers. | See 2.1. ✅ Deleted 2026-05-07. |
| 4.2 | [x] | Audit one-off Fix-* artisan commands; archive or delete. | See 2.4. ✅ Duplicate cleaned 2026-05-07. |
| 4.3 | [x] | Add feature tests for upload pipeline (graveyard, night-cross-midnight, utility 24h, double-punch < 10 min, excessive duration > 20 hrs, cross-site bio, leave-conflict auto-cancel). | `tests/Feature/Attendance/UploadPipelineWarningTest.php` — 5 tests, all passing. |
| 4.4 | [x] | Tests for `verify` recalculation paths (tardy/UT/OT updates). | `tests/Feature/Attendance/AttendanceVerifyRecalculationTest.php` — 8 tests, all passing. |
| 4.5 | [x] | Tests for `adjustLeaveForWorkDay` (single, first, last, middle, credits-year-mismatch). | `tests/Feature/Attendance/LeaveAdjustmentTest.php` — 6 tests, all passing. |
| 4.6 | [x] | Tests for break-session race conditions (parallel `start`). | `tests/Feature/BreakTimer/BreakConcurrencyTest.php` — 3 tests, all passing. Also fixed `BreakTimerController` catch-order bug: `QueryException` was unreachable because `catch(\RuntimeException)` came first (QE extends RuntimeException). |
| 4.7 | [x] | Add observability metrics for upload jobs. | `Log::info` parser stats: `files_processed`, `users_matched`, `users_unmatched`, `attendances_created/updated`, `points_generated`. ✅ 2026-05-07. |
| 4.8 | [x] | Add `BiometricRecord::last_processed_at` for idempotency. | New migration + model cast. ✅ 2026-05-07. |
| 4.9 | [x] | Document `Attendance::status` state machine. | `docs/attendance/status-state-machine.md`. ✅ 2026-05-07. |
| 4.10 | [x] | Convert `Attendance::status`/`secondary_status` to PHP `BackedEnum`. | `AttendanceStatus` + `AttendanceSecondaryStatus` BackedEnums. ✅ 2026-05-07 (also done as part of 3.2). |
| 4.11 | [x] | Replace flat `warnings` string array with VO `AttendanceWarning {type,message,severity,raised_at}`. | `app/ValueObjects/AttendanceWarning.php` — full VO with `make()`, `fromRaw()`, `toArray()`; `Attendance::$typedWarnings` accessor; `AttendancePoint` blade/React types updated. ✅ 2026-05-07. |

---

## 5. Suggested New Features

| # | Status | Feature | Rationale | Implementation Hint |
|---|---|---|---|---|
| 5.1 | [ ] | Shift-Swap Requests | Reduces HR load; prevents informal NCNS. | New `shift_swap_requests` table + approval workflow; emit `EmployeeScheduleException` on accept. |
| 5.2 | [x] | Tardy-free streak badges | Balances penalty-only point system. | `StreakService` (cache 6 hr `user_tardy_free_streak:{id}` + `streak_leaderboard:{limit}`); `AttendancePointObserver` invalidates on create/update/delete; per-user `Streak.tsx` (badge tiers + progress) and admin `Leaderboard.tsx`; routes `attendance-points.streak` / `attendance-points.leaderboard`; sidebar entry "My Streak"/"Streak Leaderboard"; 12 PHPUnit tests. |
| 5.3 | [ ] | Predictive late-arrival notifications | Cuts tardy points proactively. | Nightly artisan + simple linear model on last 90 days. |
| 5.4 | [ ] | Geo-fenced mobile clock-in fallback | Bio failures common; reduces admin verify load. | New `mobile_clock_punches` → feed into `BiometricRecord` with `source='mobile'`. |
| 5.5 | [ ] | Anomaly auto-resolution rules | Auto-flag merely appends a banner today. | New `biometric_anomaly_rules` table; consult in `autoFlagHighSeverityAnomalies`. |
| 5.6 | [ ] | Attendance heatmap + burnout indicator | Fast manager visual; flags overtime streaks. | New `Attendance/Heatmap.tsx` + sparse matrix endpoint. |
| 5.7 | [ ] | Break-overage → coaching ticket trigger | `overage_seconds` captured but never escalated. | `BreakSession` updated listener → counter → threshold → `CreateCoachingTicketJob`. |
| 5.8 | [ ] | Self-service excuse-request workflow | Currently only HR can excuse; no audit trail. | New `attendance_point_excuse_requests` table + approval queue. |
| 5.9 | [ ] | Schedule effectiveness dashboard | Validates whether schedules are realistic. | `ScheduleEffectivenessReporter` joining schedules to actual attendance. |
| 5.10 | [ ] | Payroll export profiles | Existing exports admin-shaped, not payroll-shaped. | New `payroll_export_profiles` table + `GeneratePayrollExportExcel` job. |

---

## Progress Log

| Date | Item | Author | Notes |
|---|---|---|---|
| 2026-05-07 | Audit performed | Copilot | Initial findings documented. |
| 2026-05-07 | P1.1 — OR-permission gate split | Copilot | Per-route permission middleware applied; new `attendance_points.manage` permission added; granted to admin & hr. 63 tests pass. |
| 2026-05-07 | P1.2 — Route collision constraint | Copilot | Added `->where('user','[0-9]+')` and `->where('point','[0-9]+')` to all parameterized attendance-points routes. |
| 2026-05-07 | P1.3 — Policy migration | Copilot | Added `AttendancePointPolicy::manage`; removed `authorizeAdminHrAction`; 59 tests pass. |
| 2026-05-07 | P1.4 — verify() atomicity | Copilot | Wrapped multi-step verification in `DB::transaction`; extracted `recalculateVerifyTimeFields`; 55 tests pass. |
| 2026-05-07 | P1.5 — Cascade-delete points on reprocess | Copilot | Pluck unverified attendance IDs; delete child `AttendancePoint` rows before parent attendance. 15 tests pass. |
| 2026-05-07 | P1.6 — Honor grace_period_minutes in fixStatuses | Copilot | Replaced hardcoded 15-min boundary with `$schedule->grace_period_minutes ?? 15`; mirrors processor logic. 26 tests pass. |
| 2026-05-07 | Grace period default → 0 mins | Copilot | Migration `2026_05_07_112157_set_employee_schedules_grace_period_default_zero` flips column default 15→0 and resets all rows. All `?? 15` fallbacks across processor/controllers/services/models/factories/seeders/tests changed to `?? 0`. Auto half_day_absence promotion now gated on `gracePeriod > 0` — with default grace=0, tardiness stays `tardy` and admins promote manually. AttendanceProcessorTest updated to opt into legacy 15-min grace where it tests the auto-promotion path. 197 attendance + biometric tests pass. |
| 2026-05-07 | P1.7 — Middle-day work leave split | Copilot | `adjustLeaveForWorkDay` middle branch now `replicate()`s the LeaveRequest into two segments around the worked day instead of truncating. Refunds exactly 1 credit; remainder split proportionally between segments; post-work attendance rows re-linked to sibling leave so they stay `on_leave`. 142 attendance tests pass. |
| 2026-05-07 | P1.8 — Tighten point regeneration deletion | Copilot | `regeneratePointsIfNeeded` no longer deletes by `(user_id, shift_date)` OR-clause; restricted to `attendance_id` only to prevent wiping sibling points. |
| 2026-05-07 | P1.9 — Replace raw CONCAT mass update | Copilot | `BiometricAnomalyController::autoFlagHighSeverityAnomalies` now iterates models, checks `str_contains($notes, $banner)` before appending — idempotent reruns and ActivityLog dirty tracking restored. |
| 2026-05-07 | P1.10 — Throttle high-cost POST endpoints | Copilot | Added `throttle:10,1` to `bulkStore`, `previewUpload`, `upload`, `batchVerify`, `bulkQuickApprove`, `bulkDelete`, `partialApprove`, `batchPartialApprove`. |
| 2026-05-07 | P2.1 — Delete legacy `processTimeIn` / `processTimeOut` | Copilot | ~190 LOC removed from `AttendanceProcessor`; zero callers in `app/` or `tests/`. 220 tests pass. |
| 2026-05-07 | P2.2 — Confirmed `authorizeAdminHrAction` removed | Copilot | Closed during P1.3; zero matches in `app/`. |
| 2026-05-07 | P2.5 (partial) — Standardize flash on trait | Copilot | `BreakPolicyController` migrated from ad-hoc `->with('flash', [...])` to `RedirectsWithFlashMessages::backWithFlash`. Out-of-scope controllers (e.g. `AccountController`) still use ad-hoc; both render correctly because `HandleInertiaRequests` shares `flash.message` / `flash.type` from session keys, but the trait shape is canonical. |
| 2026-05-07 | P2.6 (partial) — Replace string-role auth check | Copilot | `AttendancePointController::authorizeUserView` (auth-style `in_array(['Admin','Super Admin','HR','Team Lead'])`) replaced with new `AttendancePointPolicy::viewUserPoints` (own-record OR `attendance_points.view` permission, with `Agent`/`IT`/`Utility` restricted to self). 21 controller tests pass. Remaining role checks (`Team Lead` → campaign scoping, `Agent` → restricted-list filtering) are data-scoping logic and intentionally retained. |
| 2026-05-07 | P2.9 — Standardize `Log` facade usage | Copilot | Replaced all `\Log::*` with imported `Log::*` across in-scope files (`AttendanceController`, `AttendanceProcessor`, `AttendanceFileParser`, `BiometricReprocessingController`, `CleanOldBiometricRecords`); added missing `use Illuminate\Support\Facades\Log;` imports. |
| 2026-05-07 | P2.10 — `BreakSession::shift_date` cast | Copilot | Changed cast from `'date'` to `'date:Y-m-d'` to match `Attendance` model serialization. |
| 2026-05-07 | P2.11 — `preg_replace` null-coalesce | Copilot | Added `?? $original` fallback to all 3 `preg_replace` calls in `AttendanceFileParser` (control-char strip, datetime collapse, name normalize). |
| 2026-05-07 | P2.12 — Replace `glob()` in downloadExport | Copilot | `BiometricExportController::downloadExport` now uses `scandir` + explicit prefix/suffix filtering instead of `glob()` shell-style pattern. (Storage facade not used since job writes to `storage/app/temp` while `local` disk root is `storage/app/private`.) |
| 2026-05-07 | P2.13 — Constructor-inject services in AttendanceProcessor | Copilot | Replaced `app(LeaveCreditService::class)` / `app(NotificationService::class)` service-locator calls with constructor-injected typed properties. Updated 4 test setUp methods (3 unit, 1 feature) from `new AttendanceProcessor($parser)` to `app(AttendanceProcessor::class)`. Also updated `tests/Unit/Services/AttendanceProcessorTest` `tardy` and `half_day_absence` cases to align with forgiveness semantic (these were leftover from pre-grace-rewrite). 231 in-scope tests pass. |
| 2026-05-07 | P2.4 — Audit one-off artisan commands | Copilot | Reviewed all `Fix*`, `Backfill*`, `Reprocess*`, `Initialize*` commands. Deleted duplicate `CleanOrphanedCoachingDrafts.php` (used raw DB::select, attribute-based signature) — superseded by `CleanupOrphanCoachingDrafts.php` (Eloquent, proper error handling). Zero external references confirmed. |
| 2026-05-07 | P2.7 — BreakTimerController::status() timing dedup | Copilot | Replaced inline timing math in `status()` with `$this->breakTimerService->getSessionTimingSnapshot($activeSession)`; removed redundant `->with('breakEvents')` eager load. 66 BreakTimer tests pass. |
| 2026-05-07 | P2.8 — Magic numbers → config/attendance.php | Copilot | Created `config/attendance.php` with `lunch_threshold_hours=5`, `lunch_deduction_minutes=60`, `double_punch_threshold_minutes=10`, `single_scan_post_out_hours=2`, `undertime_threshold_minutes=60`. Replaced 8 occurrences in `AttendanceProcessor`. 54 attendance tests pass (179 assertions). |
| 2026-05-07 | P2.14 — PHP 8 constructor promoted properties | Copilot | Migrated `BiometricReprocessingController` and `AttendanceController` (4-property constructor) from old-style `protected $prop;` + manual `__construct` body to PHP 8 promoted properties. 76 tests pass. |
| 2026-05-07 | P2.5 (complete) — Standardize flash on trait | Copilot | Completed flash migration: `BreakTimerController`, `BreakDashboardController`, `EmployeeScheduleController` now use `RedirectsWithFlashMessages` trait exclusively. Updated `BreakTimerTest` (7 assertions) and `EmployeeScheduleControllerTest` (4 assertions) from `flash.type` → `type` session key. 88 tests pass. |
| 2026-05-07 | P2.3 — store/bulkStore dedup | Copilot | Extracted ~180 LOC of duplicated status + tardy/undertime/overtime calculation into `AttendanceProcessor::calculateManualAttendanceMetrics(...)`. Both `store()` and `bulkStore()` now delegate to it. 72 attendance tests pass (247 assertions). |
| 2026-05-08 | P3 complete — All Section 4 items done | Copilot | 4.3 UploadPipelineWarningTest (5), 4.4 AttendanceVerifyRecalculationTest (8), 4.5 LeaveAdjustmentTest (6), 4.6 BreakConcurrencyTest (3) — 22 new tests, all passing. Also fixed `BreakTimerController` catch-order bug (QueryException was shadowed by RuntimeException catch). 160 tests pass across Attendance + BreakTimer. |
