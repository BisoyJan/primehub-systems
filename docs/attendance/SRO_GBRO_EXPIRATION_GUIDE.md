# SRO & GBRO Expiration Guide

A plain-language explanation of how attendance point expiration works, with real examples.

---

## The Two Expiration Systems

Every attendance point is subject to **one or both** of these mechanisms:

| | SRO (Standard Roll Off) | GBRO (Good Behavior Roll Off) |
|---|---|---|
| **What it is** | Time-based expiration | Behavior-based reward |
| **Triggered by** | Calendar date passing | 60 consecutive clean days |
| **How many points removed** | All points that reach their date | The **2 most recent** eligible points |
| **Who qualifies** | All violations | All violations **except** NCNS/FTN |
| **Runs automatically** | Yes, daily at 8:05 AM | Yes, daily at 8:05 AM |

---

## Part 1 — SRO (Standard Roll Off)

### The Rule

| Violation Type | Points | Expiration |
|---|---|---|
| Tardy | 0.25 | **6 months** from violation date |
| Undertime (≤1 hr) | 0.25 | **6 months** |
| Undertime (>1 hr) | 0.50 | **6 months** |
| Half-Day Absence | 0.50 | **6 months** |
| Advised Absence (manually entered with checkbox) | 1.00 | **6 months** |
| **NCNS** (no prior notice, no show) | 1.00 | **1 year** |
| **FTN** (told to come, didn't show, didn't call) | 1.00 | **1 year** |

> **Important — Month-End Safety:** The system uses Carbon `addMonthsNoOverflow()` to calculate expiration dates. This prevents month-end overflow: `March 31 + 6 months = September 30`, **not** October 1.

### SRO Example

```
Employee: Juan dela Cruz
Violations:
  Jan 15, 2025 — Tardy (0.25 pts)        → expires Jul 15, 2025
  Feb 28, 2025 — Half-Day Absence (0.50) → expires Aug 28, 2025
  Mar 31, 2025 — Advised Absence (1.00)  → expires Sep 30, 2025  ← NoOverflow
  Apr 10, 2025 — NCNS (1.00 pts)         → expires Apr 10, 2026  ← 1 year

On Jul 15, 2025: Tardy point auto-expires. ✓
On Aug 28, 2025: Half-Day point auto-expires. ✓
On Sep 30, 2025: Advised Absence auto-expires. ✓
On Apr 10, 2026: NCNS auto-expires. ✓
```

### What Happens at Expiration

The daily command (`points:process-expirations`) checks every non-excused, non-expired point where `expires_at <= today`. When found:

- `is_expired` → `true`
- `expired_at` → current timestamp
- `expiration_type` → `sro` (or `none` for NCNS/FTN, to distinguish in audit trail)
- Employee receives an in-app notification

---

## Part 2 — GBRO (Good Behavior Roll Off)

### The Rule

If an employee goes **60 consecutive calendar days** with zero violations of any kind, the system automatically expires their **2 most recent GBRO-eligible points**.

**GBRO-eligible points** = all violations except NCNS and FTN (whole-day absences that are not manually advised).

> NCNS and FTN points **cannot** be GBRO-expired. However, they **do reset** the 60-day clean window — the employee must go 60 days without ANY violation (including NCNS/FTN) to earn GBRO.

### The 60-Day Clock

The clock starts from the **most recent event** of either:
- The last violation (any type, including NCNS/FTN), **or**
- The last time GBRO was applied

Whichever is more recent.

### GBRO Example — Basic

```
Employee: Maria Santos
Active violations (all GBRO-eligible):
  Sep 1, 2025 — Tardy (0.25 pts)         gbro_expires_at: Nov 30
  Sep 5, 2025 — Half-Day (0.50 pts)      gbro_expires_at: Nov 30  ← the 2 newest get the date
  Sep 10, 2025 — Tardy (0.25 pts)        [no gbro date — only 2 get it]

Last violation: Sep 10, 2025
60-day clean window: Sep 10 + 60 = Nov 9, 2025

Maria has zero violations between Sep 10 and Nov 9.

On Nov 9, 2025: GBRO fires.
  → Sep 10 Tardy expires (GBRO)   ← 2 most recent
  → Sep 5  Half-Day expires (GBRO) ← 2 most recent

Remaining: Sep 1 Tardy (0.25 pts) — still active.
  New gbro_expires_at for Sep 1: Nov 9 + 60 = Jan 8, 2026
```

### GBRO Example — Clock Reset by New Violation

```
Employee: Pedro Reyes
Active violations:
  Aug 1, 2025  — Tardy (0.25 pts)
  Aug 15, 2025 — Tardy (0.25 pts)

Last violation: Aug 15, 2025
GBRO due: Aug 15 + 60 = Oct 14, 2025

On Sep 20, 2025 — Pedro gets a new Half-Day Absence (0.50 pts)

Clock RESETS. New last violation: Sep 20, 2025
New GBRO due: Sep 20 + 60 = Nov 19, 2025

On Nov 19, 2025: GBRO fires.
  → Sep 20 Half-Day expires (GBRO)
  → Aug 15 Tardy expires (GBRO)

Remaining: Aug 1 Tardy (0.25 pts) — still active.
  New GBRO due: Nov 19 + 60 = Jan 18, 2026
```

### GBRO Example — NCNS Resets the Clock but Is Not Removed

```
Employee: Ana Reyes
Active violations:
  Jul 1, 2025 — Tardy (0.25 pts)         GBRO-eligible ✓
  Jul 15, 2025 — Tardy (0.25 pts)        GBRO-eligible ✓
  Sep 1, 2025 — NCNS (1.00 pts)          GBRO-eligible? NO ✗

GBRO clock is reset by the NCNS on Sep 1.
Last violation: Sep 1, 2025
GBRO due: Sep 1 + 60 = Oct 31, 2025

On Oct 31, 2025: GBRO fires.
  → Jul 15 Tardy expires (GBRO)    ← 2 most recent GBRO-eligible
  → Jul 1  Tardy expires (GBRO)    ← 2 most recent GBRO-eligible

The NCNS (Sep 1) is NOT removed by GBRO — it must wait for 1-year SRO (Sep 1, 2026).
```

### GBRO Example — Multiple GBRO Cycles

```
Employee: Carlo Santos
Timeline:

Jan 5, 2025  — Tardy (0.25)
Jan 10, 2025 — Undertime (0.25)
Jan 20, 2025 — Half-Day (0.50)

── 60 days clean after Jan 20 ──────────────────────────────
Mar 21, 2025: GBRO Cycle 1 fires
  → Jan 20 Half-Day expires (GBRO)
  → Jan 10 Undertime expires (GBRO)
  Remaining: Jan 5 Tardy (0.25)
  Next GBRO: Mar 21 + 60 = May 20, 2025

── No new violations ───────────────────────────────────────
May 20, 2025: GBRO Cycle 2 fires
  → Jan 5 Tardy expires (GBRO)
  Remaining: nothing active

Carlo now has zero active points. ✓
```

### What Happens at GBRO Expiration

For each expired point:
- `is_expired` → `true`
- `expiration_type` → `gbro`
- `gbro_applied_at` → today
- `gbro_batch_id` → batch identifier for audit
- Remaining points get a new `gbro_expires_at` = current GBRO date + 60
- Employee receives an in-app notification

---

## Part 3 — How They Interact

SRO and GBRO are **independent** — a point can only be expired once, by whichever fires first.

### Race Condition Example

```
Violation: Feb 1, 2025 — Tardy (0.25 pts)
  SRO due: Aug 1, 2025
  GBRO due: Apr 2, 2025 (employee stayed clean 60 days)

Apr 2: GBRO fires → Tardy expired (GBRO). ✓
Aug 1: SRO check runs — point already expired, skipped. ✓
```

```
Violation: Feb 1, 2025 — Tardy (0.25 pts)
Employee gets another violation Mar 1 (GBRO clock reset)
  SRO due: Aug 1, 2025
  GBRO due: Apr 30, 2025

Apr 30: GBRO fires → Tardy expired (GBRO). ✓
```

```
Violation: Jan 1, 2025 — NCNS (1.00 pts)
  SRO due: Jan 1, 2026
  GBRO: Not eligible.

Jan 1, 2026: SRO fires → NCNS expired (none). ✓
```

---

## Part 4 — Excused Points

Excused points are completely outside both systems:

- **SRO**: never run against excused points (`is_excused = true` skipped)
- **GBRO**: excused points do not count toward the 2-point removal — but their **dates are still used** to calculate the 60-day window. An excused point on Sep 5 still shifts the reference date.
- **Manual Fix** (`Fix Anomalies`): also skips excused points

---

## Part 5 — The `gbro_expires_at` Column

This is a **prediction date** displayed in the UI — it shows when GBRO *will* fire if no new violations occur.

| Situation | `gbro_expires_at` |
|---|---|
| The 2 most recent GBRO-eligible active points | Set to `reference_date + 60` |
| All other GBRO-eligible points (3rd, 4th, etc.) | `NULL` |
| Non-GBRO-eligible points (NCNS/FTN) | Always `NULL` |
| After GBRO fires | Winner points: `gbro_expires_at` = actual date; Remaining: updated to `last_gbro + 60` |

---

## Part 6 — Daily Automation

```
Every day at 8:05 AM:
php artisan points:process-expirations

  Step 1 — SRO:
    SELECT * FROM attendance_points
    WHERE is_expired = false
      AND is_excused = false
      AND expires_at <= today()
    → Each found: markAsExpired('sro')

  Step 2 — GBRO:
    For each user with active GBRO-eligible points:
      Find first 2 points where gbro_expires_at <= today
      If found: expire them, recalculate remaining points' gbro_expires_at
      If no gbro_expires_at set yet: calculate and set the prediction date
```

**Same-Day Guard:** GBRO will not fire twice for the same user on the same day. Use `--force` flag to bypass.

---

## Part 7 — Manual Tools

### Artisan Commands

```bash
# Run both SRO and GBRO processing (what the cron runs)
php artisan points:process-expirations

# Preview without changes
php artisan points:process-expirations --dry-run

# Bypass same-day guard
php artisan points:process-expirations --force

# Fix all data anomalies (overdue SRO, stale GBRO dates, month-end overflow)
php artisan points:fix-anomalies

# Preview anomaly fixes
php artisan points:fix-anomalies --dry-run
```

### UI Manage Dropdown (Admin / IT / Super Admin)

Both the **Attendance Points Index** (`/attendance-points`) and **User Show** (`/attendance-points/{user}`) pages have a **Manage** dropdown with these relevant actions:

| Action | What It Does |
|---|---|
| **Expire All Pending Points** | Force-expire all overdue SRO points for the selected user(s) immediately |
| **Initialize GBRO Dates** | Calculate and set `gbro_expires_at` for points that don't have it yet |
| **Fix GBRO Dates** | Recalculate `gbro_expires_at` based on current violation history |
| **Recalculate GBRO Dates** | Full cascade recalculation — resets and re-simulates entire GBRO timeline |
| **Fix Anomalies** | Fixes all data issues: overdue SRO, stale GBRO dates, month-end overflow (runs globally for all employees) |
| **Full Cleanup** | Remove duplicates + expire all overdue SRO + clear stale GBRO dates |
