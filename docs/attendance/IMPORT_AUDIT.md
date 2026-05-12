# Attendance Import Module — Full Audit

**Scope:** `AttendanceController` (import/previewUpload/checkForDuplicateRecords/upload), `AttendanceFileParser`, `AttendanceProcessor` (2,893 lines, 37 methods)
**Date:** 2026-05-11
**Status:** Audit complete — no fixes applied yet

---

## Table of Contents

1. [Bugs](#bugs)
   - [Critical](#critical)
   - [Functional](#functional)
   - [Edge Cases](#edge-cases)
2. [Improvements](#improvements)
3. [Time-In / Time-Out Scenarios](#time-in--time-out-scenarios)

---

## Bugs

### Critical

#### B1 — Single DB transaction wraps entire upload
**File:** `app/Services/AttendanceProcessor.php` → `processUpload()` L191
**Description:** One `DB::beginTransaction()` is opened before the employee loop and only committed after `detectAbsentEmployees()`. A single exception in any employee's `processAttendance()` call rolls back **every** processed employee, all `BiometricRecord` inserts, and the `last_processed_at` idempotency stamp. For a 5,000-row file with 200 employees, one bad record nukes the entire batch.
**Impact:** Data loss on large files; retry re-processes the whole file.
**Fix:** Wrap each `processEmployeeRecords()` call in its own `DB::transaction()`. Catch per-employee exceptions and push to `$stats['errors']` instead of bubbling up.

---

#### B2 — `checkForDuplicateRecords` is name-blind
**File:** `app/Http/Controllers/AttendanceController.php` → `checkForDuplicateRecords()` L1140–1156
**Description:** The method builds an `$employeeDates` collection of `normalized_name_date` keys from the file, but then throws it away. The subsequent query returns **all** existing attendance rows in the date range regardless of employee. Any upload previewed when other employees have attendance records shows spurious "duplicate" warnings.
**Impact:** False positives on every preview — users lose trust in the duplicate warning.
**Fix:** Filter `$existingAttendances` to only those whose `user.last_name` matches a name in `$employeeDates`, or join on the employee-date tuple.

---

#### B3 — `validateFileDates` uses legacy `shift_date` column
**File:** `app/Services/AttendanceProcessor.php` → `processUpload()` L235
**Description:** Called as `$this->validateFileDates($records, Carbon::parse($upload->shift_date))`. The `shift_date` column is kept "for backward compatibility" and always equals `date_from`. Multi-day uploads therefore validate all records against day 1 only, generating noisy or incorrect date mismatch warnings.
**Impact:** Date warning spam on multi-day uploads; real date anomalies may be drowned out.
**Fix:** Pass the full `date_from..date_to` range to `validateFileDates` instead of a single date.

---

#### B4 — N+1 storm in `detectAbsentEmployees`
**File:** `app/Services/AttendanceProcessor.php` → `detectAbsentEmployees()` L2431
**Description:** Loads all active-scheduled users via `User::whereHas(...)`. Then for each user × each date in the file, it separately queries `checkApprovedLeave()` (one query) and `Attendance::where(...)->first()` (another query). With 300 employees × 7 dates = 2,100+ individual queries — all running inside the still-open master transaction (see B1), creating long row locks.
**Impact:** Upload hangs or times out on large files; database contention under concurrent use.
**Fix:**
- Pre-load approved leaves for all users × date-set in one query, key by `[user_id, date]`.
- Pre-load existing attendances for all users × date-set in one query.
- Scope the user query to only employees who work on the days found in the file (SQL-level `worksOnDay` equivalent).

---

#### B5 — Filename collision on concurrent uploads
**File:** `app/Http/Controllers/AttendanceController.php` → `upload()` L1186
**Description:** `$filename = time().'_'.$file->getClientOriginalName()`. Two simultaneous uploads in the same second with identical filenames overwrite the same file path in storage.
**Impact:** One upload's file content replaced by another; silently processes wrong data.
**Fix:** Use `Str::uuid()` prefix instead of `time()`.

---

### Functional

#### B6 — Header skip is unconditional
**File:** `app/Services/AttendanceFileParser.php` → `parseContent()`
**Description:** `->skip(1)` drops line 1 of every file regardless of whether it is a header. Files exported without a header row (some biometric device formats) silently lose their first scan record.
**Impact:** First employee record of header-less files is dropped every time.
**Fix:** Peek at line 1 — if the first whitespace-delimited token is non-numeric (i.e., a column title like "Name"), skip it; otherwise parse it.

---

#### B7 — Malformed datetime rows silently coerced
**File:** `app/Services/AttendanceFileParser.php` → `parseLine()`
**Description:** The datetime field is stripped to `[\d\-\s:]` and then `substr(0, 19)`. A row containing `2025/11/05 08:00` becomes `2025 11 05 08:00`—still 19 characters—which `Carbon::parse` may interpret as a garbage date (`2025-01-01` etc.). Bad rows are not rejected; they are silently inserted with wrong dates.
**Impact:** Wrong attendance dates entered into the database with no indication of the source error.
**Fix:** Reject rows where the cleaned datetime does not match `Y-m-d H:i:s` exactly; push to a `unparseable_lines` stat.

---

#### B8 — `mapStatusToPointType` depends on flags not set by `processAttendance`
**File:** `app/Services/AttendanceProcessor.php` → `generateAttendancePoints()` L2007, `mapStatusToPointType()` L2129
**Description:** Point generation (manual trigger via Manage → Regenerate) reads `is_absent`, `is_tardy`, `is_undertime` boolean flags on the Attendance model. `processAttendance()` sets `status`, `tardy_minutes`, and `undertime_minutes` but does **not** set those boolean flags. The `AttendancePointCreationService` may silently no-op for many records even when `status` is clearly `tardy` or `undertime_more_than_hour`.
**Impact:** Point regeneration reports `records_processed >= 1` but zero actual point rows created for most statuses.
**Fix:** Trace `AttendancePointCreationService` logic; either set the boolean flags in `processAttendance()` or change point generation to read `status` directly.

---

#### B9 — `BiometricRecord::last_processed_at` stamped before commit
**File:** `app/Services/AttendanceProcessor.php` L344
**Description:** The idempotency stamp `BiometricRecord::where('attendance_upload_id',...)->update(['last_processed_at' => now()])` runs inside the master `DB::transaction`. If the commit itself fails (deadlock retry), the stamp is rolled back — which is fine. However if another concurrent process reads `last_processed_at` between the stamp UPDATE and the commit, it sees a "processed" state that hasn't actually been committed.
**Impact:** Low risk currently (idempotency marker is informational only), but becomes a real race condition if skip-already-processed logic is later added.

---

#### B10 — Re-upload of same file duplicates `BiometricRecord` rows
**File:** `app/Services/AttendanceProcessor.php` → `saveBiometricRecords()` L376
**Description:** `saveBiometricRecords` inserts fresh rows on every call without checking for existing records on the same `(employee_name, datetime, biometric_site_id)` composite. Re-uploading the same TXT file creates duplicate raw scan rows and skews `BiometricRecord` reports and counts.
**Impact:** Inaccurate scan history; duplicate rows returned in audit queries.
**Fix:** Change to `BiometricRecord::upsert()` keyed on `[employee_name_normalized, scanned_at, biometric_site_id]`, or use `firstOrCreate`.

---

#### B11 — 24h Utility single-scan leaves `on_time` status despite no time-out
**File:** `app/Services/AttendanceProcessor.php` → `processAttendance()` L1543, L1791
**Description:** The 24h utility first/last-scan override only runs when `$records->count() > 2` AND `first != last`. With a single scan, the branch is skipped. The tardy/undertime clearing at L1791 still runs, so the record ends up with `on_time` status (from the time-in processing above) and no `actual_time_out`—logically inconsistent.
**Impact:** A utility employee with one scan looks fully present with no flags.

---

#### B12 — Single scan at scheduled time-out is ambiguous with no warning
**File:** `app/Services/AttendanceProcessor.php` → `processAttendance()` L1500–1518
**Description:** When `timeInRecord == timeOutRecord` (same scan matched for both), the code calculates `hoursAfterScheduledOut`. A scan exactly at `scheduledTimeOut` gives `hoursAfterScheduledOut = 0`, which is not `> config('attendance.single_scan_post_out_hours')`, so it falls through to the midpoint test and is classified as TIME OUT → `failed_bio_in`. This could equally be a missed clock-out from a prior shift. No `AttendanceWarning` is generated.
**Impact:** Silently misclassifies edge-case single scan; admin has no visibility.

---

#### B13 — Graveyard shift loop-carry bug on non-work-day
**File:** `app/Services/AttendanceProcessor.php` → `groupRecordsByShiftDate()` L869
**Description:** In the graveyard shift block, when `worksOnDay($previousDayName)` returns false, `$shiftDate` is not reassigned. The variable retains the value from the **previous loop iteration**, so the current scan is silently appended to the wrong date's group.
**Impact:** A graveyard scan on a non-work preceding day steals the date from the previous scan in the loop — subtle date mis-grouping that produces wrong attendance records.
**Fix:** Add an `else { $shiftDate = $datetime->format('Y-m-d'); }` fallback so a non-workday scan falls back to its actual calendar date (where `createNonWorkDayAttendance` can handle it).

---

#### B14 — `is_cross_site_bio` in-vs-out mismatch check is unreachable
**File:** `app/Services/AttendanceProcessor.php` → `processAttendance()` L1670
**Description:** The cross-site flag is set true if `$biometricSiteId != $bioInSiteId`. Both values were set from the same `$biometricSiteId` (the upload's site), so they are always equal. The in-vs-out site comparison only makes sense when a future feature records per-scan site IDs independently.
**Impact:** Dead code — not a runtime bug, but misleading logic that will confuse future developers.

---

### Edge Cases

#### B15 — 20-hour excessive-duration cap is hardcoded
**File:** `app/Services/AttendanceProcessor.php` → `processAttendance()` L1474
**Description:** `if ($duration > 1200)` (1200 minutes = 20 hours) is a magic number. All other duration/timing thresholds reference `config('attendance.*')` values. Inconsistent configuration surface.
**Fix:** Add `attendance.max_shift_duration_minutes` to `config/attendance.php`.

---

#### B16 — Pending leave auto-cancel requires only 2 scans
**File:** `app/Services/AttendanceProcessor.php` → `hasSufficientWorkScans()` L1053 / `processShift()` L1027
**Description:** `hasSufficientWorkScans` returns `true` for `count >= 2`. Two accidental habit-punches on a leave day (common for employees on sick leave who briefly visit the office) trigger automatic cancellation of a single-day pending leave with zero HR/admin review.
**Impact:** Leave balances silently decremented or leave cancelled without employee/HR awareness.
**Fix:** Raise the bar to ≥ 3 scans AND a minimum time spread (e.g., ≥ 4 hours between first and last scan) before auto-cancelling.

---

## Improvements

| # | Description | Files | Priority |
|---|-------------|-------|----------|
| I1 | **Per-employee transaction** — wrap each `processEmployeeRecords()` in `DB::transaction()`, catch and accumulate errors, continue processing | `app/Services/AttendanceProcessor.php` → `processUpload()` | High |
| I2 | **Queue large uploads** — dispatch `ProcessAttendanceUpload` job for files above a row threshold; `AttendanceUpload.status` already supports `pending/processing/completed/failed` | `app/Http/Controllers/AttendanceController.php` → `upload()` · new `app/Jobs/ProcessAttendanceUpload.php` | High |
| I3 | **Fix duplicate check** — join on `(normalized_name, shift_date)` tuples from the file; or remove the preview check and rely solely on `firstOrNew` overwrite logic in `processAttendance` | `app/Http/Controllers/AttendanceController.php` → `checkForDuplicateRecords()` | High |
| I4 | **Smart header detection** — peek line 1; if first token is non-numeric treat as header, else parse it | `app/Services/AttendanceFileParser.php` → `parseContent()` | Medium |
| I5 | **Reject malformed datetimes** — enforce strict `Y-m-d H:i:s` parse; add `unparseable_lines` to stats response | `app/Services/AttendanceFileParser.php` → `parseLine()` | Medium |
| I6 | **UUID filename** — replace `time().'_'` with `Str::uuid().'_'` to prevent collision | `app/Http/Controllers/AttendanceController.php` → `upload()` | Medium |
| I7 | **Idempotent `BiometricRecord` saves** — `upsert()` on `[employee_name_normalized, scanned_at, biometric_site_id]` | `app/Services/AttendanceProcessor.php` → `saveBiometricRecords()` | Medium |
| I8 | **Batch `detectAbsentEmployees` queries** — pre-load approved leaves and existing attendances for the full date set in two queries; scope employee query by schedule weekdays in SQL | `app/Services/AttendanceProcessor.php` → `detectAbsentEmployees()` | High |
| I9 | **Config-drive all thresholds** — move hardcoded `1200` (20h max shift), `1` (undertime min minutes), `30` (overtime threshold) to `config/attendance.php` | `app/Services/AttendanceProcessor.php` → `processAttendance()` · `config/attendance.php` | Low |
| I10 | **Raise auto-cancel bar** — require ≥ 3 scans + ≥ 4h first-to-last span for single-day pending leave cancellation | `app/Services/AttendanceProcessor.php` → `hasSufficientWorkScans()` · `processShift()` | Medium |
| I11 | **Remove legacy `shift_date`** — after full migration, drop backward-compat column and update `validateFileDates` to accept `date_from..date_to` range | `app/Services/AttendanceProcessor.php` → `processUpload()` · `validateFileDates()` · `app/Models/AttendanceUpload.php` | Low |
| I12 | **Row-level parse error log** — return `unparseable_lines` count + first N examples in preview and upload response so admins know when their file has formatting issues | `app/Services/AttendanceFileParser.php` → `parseLine()` · `app/Http/Controllers/AttendanceController.php` → `previewUpload()` | Medium |

---

## Time-In / Time-Out Scenarios

> ✓ = logic covers this path correctly  
> ⚠️ = ambiguous or risky (see linked bug)  
> ✗ = not handled / produces wrong result

### Standard Day Shifts (08:00–17:00 unless noted)

| # | Scenario | Scans | Expected Status | Expected Fields | Code Path | Risk |
|---|----------|-------|-----------------|-----------------|-----------|------|
| S01 | Both scans on time | 07:55 IN, 17:02 OUT | `on_time` | no tardy, no undertime | L1700 → `determineTimeInStatus` | ✓ |
| S02 | Tardy within grace (grace=10min) | 08:08 IN, 17:00 OUT | `on_time` | `tardy_minutes=8` recorded, no penalty | L1709 grace check | ✓ |
| S03 | Tardy beyond grace (grace=0) | 08:15 IN, 17:00 OUT | `tardy` | `tardy_minutes=15` | L1700 | ✓ |
| S04 | Undertime (≤60min early) | 08:00 IN, 16:30 OUT | `undertime` | `undertime_minutes=30` | L1738 | ✓ |
| S05 | Undertime > 60min | 08:00 IN, 15:30 OUT | `undertime_more_than_hour` | `undertime_minutes=90` | L1742 | ✓ |
| S06 | Tardy + Undertime | 08:20 IN, 16:30 OUT | `tardy` (primary), `undertime` (secondary) | both minutes set | L1743 secondary_status | ✓ |
| S07 | Overtime (>30min) | 08:00 IN, 18:00 OUT | `on_time`, `overtime_minutes=60` | capped at sched-out if not approved | L1758 + L1899 | ✓ |
| S08 | Overtime (<30min threshold) | 08:00 IN, 17:25 OUT | `on_time` | no overtime_minutes (sub-threshold) | L1761 | ✓ |
| S09 | Early arrival (>2h before sched) | 05:30 IN, 17:00 OUT | `on_time` | effective time-in = sched time-in, not actual | `findTimeInRecord` 2h filter | ✓ |

---

### Missing Bio Scenarios

| # | Scenario | Scans | Expected Status | Code Path | Risk |
|---|----------|-------|-----------------|-----------|------|
| M01 | No scans on scheduled day | none | `ncns` | `detectAbsentEmployees` L2412 | ✓ |
| M02 | Only time-in present | 08:05 IN only | `on_time` or `tardy` (primary) + `failed_bio_out` (secondary) | L1488 sameRecord → keeps IN, clears OUT → L1783 | ✓ |
| M03 | Only time-out present | 17:05 OUT only | `failed_bio_in` | L1488 midpoint → keeps OUT, clears IN → L1780 | ⚠️ B12 |
| M04 | Single scan at scheduled time-out | 17:00 only | `failed_bio_in` (misclassified — could be late IN) | sameRecord + midpoint + `hoursAfterScheduledOut=0` | ⚠️ B12 |
| M05 | Single scan hours past time-out | 19:30 only | `tardy` + `failed_bio_out` | L1502 `single_scan_post_out_hours` config | ✓ |
| M06 | No time-in and no time-out | (both cleared by double-punch or other logic) | `ncns` | L1772 | ✓ |

---

### Multi-Scan / Anomaly Scenarios

| # | Scenario | Scans | Expected Status | Code Path | Risk |
|---|----------|-------|-----------------|-----------|------|
| A01 | Double punch (<10min) | 08:00, 08:03 | warning stored, OUT cleared → `on_time/tardy + failed_bio_out` | L1421 `double_punch_threshold_minutes` | ✓ |
| A02 | 4 scans (break + re-entry) | 08:00, 12:00, 13:00, 17:00 | `on_time`; IN=08:00, OUT=17:00 via smart finder | parser `findTimeOutRecord` closest-to-sched | ✓ |
| A03 | Two scans 10–30min apart | 08:00, 08:25 | treated as IN+OUT (duration=25min); NOT double-punch | threshold default ~10min | ⚠️ silent short shift |
| A04 | Two scans >20h apart | 08:00 day1, 09:00 day2 | excessive-duration warning, OUT cleared | L1474 hardcoded 1200min — B15 | ✓ / ⚠️ B15 |
| A05 | 6 scans — 24h Utility (smoker) | 06:00, 08:30, 10:15, 14:00, 16:30, 18:00 | `on_time` (≥8h), first+last only | L1543 utility_24h branch | ✓ |
| A06 | Single scan — 24h Utility | 08:00 only | inconsistent state: `on_time` with no time-out | L1791 clears tardy/undertime but status already set | ⚠️ B11 |

---

### Leave Overlap Scenarios

| # | Scenario | Scans | Expected Result | Code Path | Risk |
|---|----------|-------|-----------------|-----------|------|
| L01 | Approved leave, no scans | none on leave day | `on_leave` attendance record | `createLeaveAttendance` L1156 | ✓ |
| L02 | Approved leave + ≥2 scans | 08:00, 17:00 on leave day | Attendance created, `admin_verified=false`, `leave_request_id` set, flagged for HR review | `flagLeaveForReview` L953 | ✓ |
| L03 | Approved leave + 1 scan only | 08:00 on leave day | `on_leave` (hasSufficientWorkScans=false, single scan ignored) | L995 | ✓ |
| L04 | Single-day pending leave + ≥2 scans | 08:00, 17:00 | Leave auto-cancelled, attendance processed normally | L1024 auto-cancel | ⚠️ B16 (2 habit scans enough) |
| L05 | Multi-day pending leave + scans on one day | 08:00, 17:00 on day 2 of 3 | `flagPendingLeaveForReview`, no auto-cancel | L1019 multi-day guard | ✓ |
| L06 | No leave, scans on rest day | Sat 08:00, 17:00 (M-F sched) | `createNonWorkDayAttendance` row for audit | L1207 non-work day | ✓ |
| L07 | No leave, scheduled day, no scans but day is on non-work schedule | Sat with M-F sched | No row created | `worksOnDay` guard in `detectAbsentEmployees` | ✓ |

---

### Night / Next-Day Shift Scenarios (22:00–07:00)

| # | Scenario | Scans | Expected Grouping | Expected Status | Code Path | Risk |
|---|----------|-------|-------------------|-----------------|-----------|------|
| N01 | Clean night shift | Mon 22:00 IN, Tue 07:05 OUT | Both → shift_date = Monday | `on_time` | L880 isNextDayShift | ✓ |
| N02 | Early arrival (within 60min before shift) | Mon 21:55 IN, Tue 07:00 OUT | Both → Monday | `on_time` | L887 `minutesBeforeShift` 0-60 tolerance | ✓ |
| N03 | Special evening (≥22:00 sched, 18:00–23:59 scan) | Mon 21:46 IN, Tue 07:10 OUT | Both → Monday | `on_time` | L893 evening special case | ✓ |
| N04 | Scan exactly at midnight | Mon 22:00 IN, Tue 00:00 mid-scan, Tue 07:10 OUT | Mid-scan grouped to Mon (hour<scheduledHour=22) → Mon; smart finder picks closest | `on_time` / `tardy` (depends) | parser smart match | ⚠️ ambiguous |
| N05 | Two night scans, morning time-out missing | Mon 22:00 IN only | `failed_bio_out` | L1783 | ✓ |
| N06 | Tardy on night shift | Mon 22:45 IN, Tue 07:00 OUT | `tardy`, `tardy_minutes=45` | L1700 | ✓ |

---

### Graveyard Shift Scenarios (00:30–09:30, schedule day = Mon, scan day = Tue)

| # | Scenario | Scans | Expected Grouping | Expected Status | Code Path | Risk |
|---|----------|-------|-------------------|-----------------|-----------|------|
| G01 | Late-evening early arrival | Mon 23:15 IN, Tue 09:35 OUT | Both → shift_date = Monday | `on_time` | L832 hour≥20 early arrival | ✓ |
| G02 | On-time arrival | Tue 00:35 IN, Tue 09:30 OUT | Both → shift_date = Monday | `on_time` | L840 midpointHour range | ✓ |
| G03 | Time-out on non-work preceding day | Mon 02:00 (Sun is rest) | `worksOnDay('Sunday')=false` → shiftDate carries from prev iteration | Wrong date assigned | ⚠️ B13 |
| G04 | Graveyard scan same morning as next-day schedule | Tue 03:00 OUT from Mon shift; also Tue 23:30 IN for Tue shift | Two groups correctly split by hour range | Two attendance rows | L832 + L840 grouping | ✓ |

---

### File Format / Parser Scenarios

| # | Scenario | Expected Behavior | Code Path | Risk |
|---|----------|-------------------|-----------|------|
| P01 | File with standard header row | Header skipped, all scans parsed | `parseContent` `->skip(1)` | ✓ |
| P02 | File with no header row | First real scan record dropped | `->skip(1)` unconditional | ✗ B6 |
| P03 | Win-1252 encoded name "Niño" | Converted to UTF-8 correctly | `convertToUtf8` | ✓ |
| P04 | Null bytes in file (some devices) | Stripped before parse | parser null-byte strip | ✓ |
| P05 | Datetime with `/` separator `2025/11/05 08:00` | Coerced to bad value, not rejected | char-strip + substr(0,19) | ✗ B7 |
| P06 | Compound surname disambiguation "Cabarliza A" vs "Cabarliza B" | Correct user matched via `disambiguateMatches` + shift-time scoring | L1902 | ✓ |
| P07 | Employee name not in DB | Added to `unmatched_names_list`; no attendance row created | L284 | ✓ |
| P08 | Re-upload same TXT file | Attendance: `firstOrNew` updates unverified, skips verified. BiometricRecord: **duplicated** | L1469 + L376 | ⚠️ B10 |
| P09 | File with future-dated scans (after `date_to`) | Filtered out by `filterByDateRange`, counted in `skipped_records` | L208 filterByDate | ✓ |
| P10 | File scans outside `date_from..date_to` | Same as P09 — filtered out | L208 | ✓ |
| P11 | File with only 1 scan row (after header skip) | Empty collection; `processUpload` logs 0 records but no crash | ✓ | ✓ |
| P12 | Corrupt datetime (all zeros `0000-00-00 00:00:00`) | Carbon may parse as `0001-01-01`; inserted with wrong date | parseLine no strict validation | ✗ B7 |

---

## Bug Summary

| ID | Severity | Area | Title |
|----|----------|------|-------|
| B1 | Critical | Processor | Single transaction wraps entire upload |
| B2 | Critical | Controller | Duplicate check is name-blind |
| B3 | Critical | Processor | `validateFileDates` uses legacy `shift_date` |
| B4 | Critical | Processor | N+1 storm in `detectAbsentEmployees` |
| B5 | Critical | Controller | Filename collision on concurrent uploads |
| B6 | Functional | Parser | Unconditional header skip drops real data |
| B7 | Functional | Parser | Malformed datetime coerced instead of rejected |
| B8 | Functional | Processor | `mapStatusToPointType` depends on unset boolean flags |
| B9 | Functional | Processor | Idempotency stamp inside transaction (race on failure) |
| B10 | Functional | Processor | Re-upload duplicates `BiometricRecord` rows |
| B11 | Functional | Processor | 24h Utility single scan yields `on_time` with no time-out |
| B12 | Functional | Processor | Single scan at scheduled time-out misclassified silently |
| B13 | Edge | Processor | Graveyard non-work-day fallthrough carries wrong `$shiftDate` |
| B14 | Edge | Processor | `is_cross_site_bio` in-vs-out mismatch unreachable |
| B15 | Edge | Processor | 20h max shift duration hardcoded (not config-driven) |
| B16 | Edge | Processor | Pending leave auto-cancel requires only 2 scans |

**Total: 5 Critical · 7 Functional · 4 Edge**

---

*Generated from audit session 2026-05-11. Next step: fix all bugs and add feature test suite.*
