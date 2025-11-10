# Attendance Record Grouping Logic

## Overview
The system uses a **universal algorithm** to group biometric records by shift date and find time in/out records, based on the employee's schedule. The algorithm supports **ALL 48 possible 9-hour shift patterns** (00:00-09:00 through 23:30-08:30).

## Schedule Pattern Classification

### Pattern Analysis (All 48 Combinations)
Out of 48 possible 9-hour shift patterns:
- **25 patterns** are SAME DAY shifts (01:00-10:00 through 14:30-23:30)
- **23 patterns** are NEXT DAY shifts (00:00-09:00 through 00:30-09:30, and 15:00-00:00 through 23:30-08:30)

The algorithm automatically detects which type based on: `time_out <= time_in` OR special graveyard shift logic.

## Core Algorithm

### Step 1: Determine if shift spans to next day
```
isNextDayShift = scheduled_time_out <= scheduled_time_in 
                 OR (scheduled_time_in >= 00:00 AND scheduled_time_in < 05:00)
```

**Special Case: Graveyard Shifts (00:00-04:59 start times)**
- Shifts starting between 00:00-04:59 (e.g., 00:00-09:00, 01:00-10:00) are treated as NEXT DAY shifts
- These represent shifts where employees scan late evening (20:00-23:59) as time in
- The 00:00 start time is a placeholder for "after midnight on previous evening"
- Example: 00:00-09:00 means scan ~22:00-23:59 on Day 1, leave ~09:00 on Day 2

### Step 2: Group records based on shift type

#### SAME DAY SHIFTS (`isNextDayShift = false`)
All records are grouped to their **actual date**.

**Examples (25 patterns):**
- **01:00-10:00**: 10:00 > 01:00 AND not in 00:00-04:59 range = Same day
- **07:00-16:00**: 16:00 > 07:00 = Same day
- **14:30-23:30**: 23:30 > 14:30 = Same day

**Grouping Logic:**
- Record at any hour on Jan 15 → Jan 15 group
- All records for one shift are on the same date

#### NEXT DAY SHIFTS (`isNextDayShift = true`)
Records **before** scheduled_time_in hour go to **previous day**.
Records **at or after** scheduled_time_in hour go to **current day**.

**Special handling for graveyard shifts (00:00-04:59 start times):**
- Records from 20:00-23:59 = Time in for CURRENT day
- Records from 00:00 to scheduled_out time = Time out from PREVIOUS day

**Examples (23 patterns):**

- **00:00-09:00** (Special graveyard): 
  - Record at 22:28 on Jan 15 (hour 22 >= 20) → Jan 15 group (time in)
  - Record at 09:00 on Jan 16 (hour 9 < 9 out time) → Jan 15 group (time out) ✓
  
- **15:00-00:00**: 00:00 <= 15:00 = Next day
  - Record at 15:02 on Jan 15 (hour 15 >= 15) → Jan 15 group
  - Record at 00:01 on Jan 16 (hour 0 < 15) → Jan 15 group ✓

- **22:00-07:00**: 07:00 <= 22:00 = Next day
  - Record at 22:05 on Jan 15 (hour 22 >= 22) → Jan 15 group
  - Record at 07:02 on Jan 16 (hour 7 < 22) → Jan 15 group ✓

- **23:30-08:30**: 08:30 <= 23:30 = Next day
  - Record at 23:32 on Jan 15 (hour 23 >= 23) → Jan 15 group
  - Record at 08:28 on Jan 16 (hour 8 < 23) → Jan 15 group ✓

### Step 3: Find Time In Record

#### For SAME DAY Shifts (30 patterns)
Use `findTimeInRecord()` - Finds **earliest record on shift date** regardless of hour.

**Why:** All records are on same date, so earliest = time in, latest = time out.

**Benefits:**
- ✅ Works even if employee clocks in very late
- ✅ No hour range restrictions
- ✅ Simple and reliable

**Example:** Schedule 01:00-10:00
- Record at 01:02 → Found ✓
- Record at 05:00 (very late) → Still found ✓
- Record at 09:00 (extremely late) → Still found ✓

#### For NEXT DAY Shifts (18 patterns)
Use `findTimeInRecordByTimeRange()` - Finds record in **specific hour range** based on shift start time.

**Why:** Records span two dates. Hour ranges prevent confusing time in (day 1 evening) with time out (day 2 morning).

**Hour Ranges:**
- Start 00:00-04:59 → Search 0-4
- Start 05:00-11:59 → Search 5-11
- Start 12:00-17:59 → Search 12-17
- Start 18:00-23:59 → Search 18-23

**Example:** Schedule 22:00-07:00
- Jan 15 22:05 (hour 22 in range 18-23) → Time in found ✓
- Jan 16 07:02 (hour 7 NOT in range 18-23) → Skipped (this is time out)

### Step 4: Find Time Out Record
Use `findTimeOutRecord()` - Finds **latest record on expected date**.

For SAME DAY: Look on shift date
For NEXT DAY: Look on shift date + 1 day

## All Schedule Patterns Supported (48 Total)

### SAME DAY Shifts (25 patterns)
Time out occurs on same day as time in. All records on one date.

| Start → End | Pattern Type |
|-------------|--------------|
| 01:00 → 10:00 through 04:30 → 13:30 | Graveyard (not 00:00-04:59) |
| 05:00 → 14:00 through 11:30 → 20:30 | Morning |
| 12:00 → 21:00 through 14:30 → 23:30 | Afternoon |

**Logic:** `findTimeInRecord()` - earliest on date = time in

### NEXT DAY Shifts (23 patterns)
Time out occurs on next day. Records span two dates.

| Start → End | Pattern Type | Hour Range | Special Logic |
|-------------|--------------|------------|---------------|
| 00:00 → 09:00 through 04:30 → 13:30 | Graveyard | 20-23 for in | 20:00-23:59 = time in |
| 15:00 → 00:00 through 17:30 → 02:30 | Afternoon+ | 12-17 | Standard next-day |
| 18:00 → 03:00 through 19:30 → 04:30 | Evening+ | 18-23 | Standard next-day |
| 20:00 → 05:00 through 23:30 → 08:30 | Night | 18-23 | Standard next-day |

**Logic:** For 00:00-04:59 starts: Special graveyard handling (20:00+ = time in, morning = time out from yesterday)
**Logic:** For others: `findTimeInRecordByTimeRange()` - hour-specific to separate in/out

## Complete Schedule Coverage

✅ **00:00-09:00** (Graveyard, NEXT DAY - employees scan 20:00-23:59 as time in)
✅ **01:00-10:00** (Graveyard, SAME DAY if not in special range)  
✅ **07:00-16:00** (Morning, SAME DAY)
✅ **15:00-00:00** (Afternoon, NEXT DAY)
✅ **22:00-07:00** (Night, NEXT DAY)
✅ **23:30-08:30** (Night, NEXT DAY)
✅ **All 48 patterns** between 00:00 and 23:30 start times

## Benefits of This Approach

1. **Universal**: Works for ALL 48 possible 9-hour shift patterns
2. **Adaptive**: Uses different strategies for same-day vs next-day shifts
3. **Accurate**: Based on actual schedule, not time guessing
4. **Flexible**: Same-day shifts handle any clock-in time
5. **Intelligent**: Next-day shifts use hour ranges to prevent confusion
6. **Maintainable**: Simple 2-part logic (same day vs next day)
7. **Future-proof**: Automatically handles any new schedule patterns

## Edge Cases Handled

- ✅ **Special graveyard shifts (00:00-04:59)** - Employees scan 20:00-23:59 as time in, 00:00-09:00 as time out
- ✅ Early morning shifts (05:00-14:00)
- ✅ Standard day shifts (07:00-16:00, 08:00-17:00)
- ✅ Afternoon shifts ending at midnight (15:00-00:00)
- ✅ Evening shifts spanning to morning (18:00-03:00)
- ✅ Night shifts with late time outs (22:00-07:00, 23:30-08:30)
- ✅ Employees clocking in very late (still finds time in)
- ✅ 30-minute interval schedules (00:30, 01:30, etc.)
- ✅ All possible combinations of start and end times

## Code Location

- **Record Grouping**: `app/Services/AttendanceProcessor.php` → `groupRecordsByShiftDate()`
- **Next Day Detection**: `app/Services/AttendanceProcessor.php` → `isNextDayShift()`
- **Time In Detection**: `app/Services/AttendanceProcessor.php` → `processAttendance()`
- **Time Out Detection**: `app/Services/AttendanceProcessor.php` → `processAttendance()`
- **File Parsing**: `app/Services/AttendanceFileParser.php`
