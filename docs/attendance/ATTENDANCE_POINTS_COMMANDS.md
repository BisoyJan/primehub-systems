# Attendance Points Management Commands

This guide covers all artisan commands and UI management features available for managing attendance points.

## Table of Contents

- [Overview](#overview)
- [UI Management](#ui-management)
- [Commands](#commands)
  - [Process Expirations](#1-process-expirations)
  - [Manage Points](#2-manage-points)
  - [Generate Missing Points](#3-generate-missing-points)
- [Common Workflows](#common-workflows)
- [Scheduled Tasks](#scheduled-tasks)

---

## Overview

Attendance points are automatically generated when attendance records are verified. Points expire based on two mechanisms:

| Expiration Type | Description | Duration |
|-----------------|-------------|----------|
| **SRO** (Standard Roll Off) | Time-based expiration | 6 months (standard) or 1 year (NCNS) |
| **GBRO** (Good Behavior Roll Off) | Reward for 60 days without violations | Removes last 2 eligible points |

### Point Values

| Violation Type | Points |
|----------------|--------|
| Whole Day Absence (NCNS/FTN) | 1.00 |
| Half-Day Absence | 0.50 |
| Undertime (>1 Hour) | 0.50 |
| Undertime (≤1 Hour) | 0.25 |
| Tardy | 0.25 |

---

## UI Management

The Attendance Points page includes a **Manage** dropdown (available to IT and Super Admin roles) for performing bulk management actions directly from the UI.

### Accessing Management Features

1. Navigate to **Attendance > Points**
2. Click the **Manage** button in the top-right corner
3. Select an action from the dropdown or click **View Statistics** for the full dashboard

### Available UI Actions

| Action | Description | Color |
|--------|-------------|-------|
| **View Statistics** | Opens management dashboard showing counts for all issues | - |
| **Regenerate Points** | Create points for verified attendance records missing points | Green |
| **Remove Duplicates** | Delete duplicate entries (same user, date, type) | Yellow |
| **Expire All Pending** | Mark all past-due points as expired | Orange |
| **Reset Expired Points** | Reset expired points to active for reprocessing | Blue |
| **Full Cleanup** | Combines remove duplicates + expire pending | Purple |

### Management Dashboard

The **View Statistics** option shows:
- **Missing Points**: Verified attendance records without corresponding points
- **Duplicate Points**: Same user, date, and type entries
- **Pending Expirations**: Points that should be expired but aren't marked
- **Expired Points**: Points that can be reset for reprocessing

### Regenerate Points with Filters

When selecting **Regenerate Points**, you can optionally filter by:
- **Date Range**: From/To dates to limit which attendance records are processed
- **Employee**: Specific user to regenerate points for

### Important Notes

⚠️ **No Notifications**: All UI management actions are performed silently. Agents will NOT receive any notifications.

This is by design for bulk operations. Use the command line with `--notify` flag if notifications are needed.

---

## Commands

### 1. Process Expirations

**Command:** `php artisan points:process-expirations`

Processes both SRO and GBRO expirations. This runs automatically daily at 3:00 AM.

#### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Preview what would be processed without making changes |
| `--no-notify` | Skip sending notifications to employees |

#### Examples

```bash
# Preview what would be expired
php artisan points:process-expirations --dry-run

# Process expirations and send notifications (default)
php artisan points:process-expirations

# Process expirations WITHOUT sending notifications
php artisan points:process-expirations --no-notify
```

#### Output Example

```
Processing Attendance Point Expirations
========================================

Processing Standard Roll Off (SRO)...
  Found 3 points ready for SRO expiration:
    - John Doe: Tardy (0.25 pts) - 2025-06-15
    - Jane Smith: Half-Day Absence (0.50 pts) - 2025-06-20

Processing Good Behavior Roll Off (GBRO)...
  John Doe: 65 days without violation
    - Expiring: Undertime (0.25 pts) - 2025-10-01
    - Expiring: Tardy (0.25 pts) - 2025-09-15

Summary:
+---------------------------+----------------+
| Type                      | Points Expired |
+---------------------------+----------------+
| SRO (Standard Roll Off)   | 2              |
| GBRO (Good Behavior)      | 2              |
| Total                     | 4              |
+---------------------------+----------------+

✅ Expiration processing complete!
```

---

### 2. Manage Points

**Command:** `php artisan points:manage {action}`

A comprehensive command for managing attendance points with multiple actions.

#### Actions

| Action | Description |
|--------|-------------|
| `regenerate` | Regenerate points from verified attendance records |
| `remove-duplicates` | Remove duplicate points (same user, date, type) |
| `expire-all` | Expire all points that have passed expiration date |
| `reset-expired` | Reset expired points back to active (unexpire) |
| `cleanup` | Full cleanup (remove duplicates + expire pending) |

#### Global Options

| Option | Description |
|--------|-------------|
| `--from=YYYY-MM-DD` | Filter by start date |
| `--to=YYYY-MM-DD` | Filter by end date |
| `--user=ID` | Filter by specific user ID |
| `--notify` | Send notifications (default: OFF) |
| `--dry-run` | Preview changes without applying |
| `--force` | Skip confirmation prompts |

---

#### Action: `regenerate`

Regenerates attendance points from verified attendance records that don't already have points.

```bash
# Preview what would be regenerated
php artisan points:manage regenerate --dry-run

# Regenerate for a date range
php artisan points:manage regenerate --from=2025-01-01 --to=2025-12-31 --force

# Regenerate for a specific user
php artisan points:manage regenerate --user=123 --force
```

---

#### Action: `remove-duplicates`

Removes duplicate attendance points (same user, same date, same violation type). Keeps the oldest entry.

```bash
# Preview duplicates
php artisan points:manage remove-duplicates --dry-run

# Remove duplicates
php artisan points:manage remove-duplicates --force
```

**Output Example:**

```
🧹 Removing Duplicate Attendance Points
=======================================

Found 3 sets of duplicates

  John Doe | 2025-06-27 | half_day_absence (2 entries, keeping ID: 45)
  John Doe | 2025-06-27 | half_day_absence (2 entries, keeping ID: 48)
  Jane Smith | 2025-06-25 | tardy (3 entries, keeping ID: 52)

Summary:
+----------------------+-------+
| Metric               | Count |
+----------------------+-------+
| Duplicate Sets Found | 3     |
| Points Removed       | 4     |
+----------------------+-------+
```

---

#### Action: `expire-all`

Expires all points that have passed their expiration date but haven't been marked as expired yet.

```bash
# Preview pending expirations
php artisan points:manage expire-all --dry-run

# Expire all pending (no notifications)
php artisan points:manage expire-all --force

# Expire all pending WITH notifications
php artisan points:manage expire-all --notify --force
```

---

#### Action: `reset-expired`

Resets expired points back to active status. Useful when you need to reprocess points or fix data issues.

**What it does:**
1. Finds all expired points (optionally filtered by date/user)
2. Resets `is_expired` to `false`
3. Clears expiration-related fields (`expired_at`, `gbro_applied_at`, `gbro_batch_id`)
4. **Recalculates expiration date** from the original shift date

```bash
# Preview what would be reset
php artisan points:manage reset-expired --dry-run

# Reset ALL expired points
php artisan points:manage reset-expired --force

# Reset expired points for a specific user
php artisan points:manage reset-expired --user=123 --force

# Reset expired points within a date range
php artisan points:manage reset-expired --from=2024-01-01 --to=2024-12-31 --force
```

**Output Example:**

```
🔄 Resetting Expired Attendance Points
======================================

Found 5 expired points to reset

  John Doe: 3 points
    - 2024-12-14 | Whole Day Absence | 1.00 pts (was SRO)
    - 2024-10-09 | Half-Day Absence | 0.50 pts (was SRO)
    - 2024-09-26 | Whole Day Absence | 1.00 pts (was SRO)
  Jane Smith: 2 points
    - 2024-08-25 | Tardy | 0.25 pts (was GBRO)
    - 2024-08-20 | Undertime | 0.25 pts (was GBRO)

Do you want to reset these points to active status? (yes/no) [no]:
> yes

Summary:
+-------------------------+-------+
| Metric                  | Count |
+-------------------------+-------+
| Points Reset to Active  | 5     |
+-------------------------+-------+

⚠️  Note: Expiration dates have been recalculated from the original shift dates.
   Points that have already passed their new expiration date will need to be
   re-expired by running: php artisan points:process-expirations --no-notify
```

---

#### Action: `cleanup`

Performs a full cleanup: removes duplicates and expires pending points (without notifications).

```bash
# Preview cleanup
php artisan points:manage cleanup --dry-run

# Run full cleanup
php artisan points:manage cleanup --force
```

---

### 3. Generate Missing Points

**Command:** `php artisan attendance:generate-points`

Generates attendance points for attendance records that have violations but no corresponding points.

#### Options

| Option | Description |
|--------|-------------|
| `--from=YYYY-MM-DD` | Start date (required unless using `--all`) |
| `--to=YYYY-MM-DD` | End date (required unless using `--all`) |
| `--all` | Process all attendance records |

#### Examples

```bash
# Generate for a date range
php artisan attendance:generate-points --from=2025-01-01 --to=2025-12-31

# Generate for all records
php artisan attendance:generate-points --all
```

---

## Common Workflows

### Fix Duplicate Entries

```bash
# 1. Preview duplicates
php artisan points:manage remove-duplicates --dry-run

# 2. Remove duplicates
php artisan points:manage remove-duplicates --force
```

### Reprocess Expired Points

When points were incorrectly expired or need to be recalculated:

```bash
# 1. Reset expired points back to active
php artisan points:manage reset-expired --force

# 2. Remove any duplicates that may exist
php artisan points:manage remove-duplicates --force

# 3. Re-expire points (without sending notifications)
php artisan points:process-expirations --no-notify
```

### Reprocess Points for a Specific User

```bash
# 1. Reset their expired points
php artisan points:manage reset-expired --user=123 --force

# 2. Re-expire if needed
php artisan points:process-expirations --no-notify
```

### Full Data Cleanup

```bash
# Option 1: Use cleanup action
php artisan points:manage cleanup --force

# Option 2: Step by step
php artisan points:manage remove-duplicates --force
php artisan points:manage expire-all --force
```

### Regenerate All Points for a Period

```bash
# Generate missing points for 2025
php artisan attendance:generate-points --from=2025-01-01 --to=2025-12-31
```

---

## Scheduled Tasks

The following task runs automatically (defined in `routes/console.php`):

| Command | Schedule | Description |
|---------|----------|-------------|
| `points:process-expirations` | Daily at 3:00 AM | Process SRO and GBRO expirations |

### Manual Scheduling

To run the scheduler locally for testing:

```bash
# Run scheduler once
php artisan schedule:run

# Run scheduler continuously (for development)
php artisan schedule:work
```

---

## Notifications

When points expire, employees receive notifications (unless `--no-notify` is used):

### SRO Expiration Notification
> "Your Tardy violation from Dec 14, 2024 (0.25 pts) has expired via Standard Roll Off (SRO)."

### GBRO Expiration Notification
> "Your Tardy violation from Nov 01, 2025 (0.25 pts) has expired via Good Behavior Roll Off (GBRO). Congratulations on maintaining good attendance!"

---

## Troubleshooting

### Points Not Expiring

1. Check if the scheduler is running:
   ```bash
   php artisan schedule:list
   ```

2. Run expiration manually:
   ```bash
   php artisan points:process-expirations --dry-run
   ```

### Duplicate Points Exist

1. Preview duplicates:
   ```bash
   php artisan points:manage remove-duplicates --dry-run
   ```

2. Remove them:
   ```bash
   php artisan points:manage remove-duplicates --force
   ```

### Need to Undo Expirations

1. Reset expired points:
   ```bash
   php artisan points:manage reset-expired --force
   ```

2. Re-process if needed:
   ```bash
   php artisan points:process-expirations --no-notify
   ```

---

## Related Documentation

- [Attendance System Implementation](./IMPLEMENTATION_SUMMARY.md)
- [Automatic Point Generation](./AUTOMATIC_POINT_GENERATION.md)
- [Notification System](../notification/NOTIFICATION_SYSTEM.md)
