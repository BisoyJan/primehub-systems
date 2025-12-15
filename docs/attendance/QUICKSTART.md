# Attendance System - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Laravel application running
- Database migrated
- Users with `hired_date` set
- Employee schedules configured

### 1. Configure Employee Schedules

Before processing attendance, each employee needs a schedule:

```php
// Create via Tinker
php artisan tinker

// Create schedule for user
EmployeeSchedule::create([
    'user_id' => 1,
    'site_id' => 1,
    'scheduled_time_in' => '09:00:00',
    'scheduled_time_out' => '18:00:00',
    'grace_period_minutes' => 15,
    'is_active' => true
]);
```

Or navigate to `/employee-schedules` in the application.

### 2. Import Attendance File

1. Navigate to `/attendance/import`
2. Upload biometric TXT file
3. Select the shift date
4. Select the site
5. Click "Process"

**File Format Expected:**
```
1    John Doe             2025-11-28 09:00:00
1    John Doe             2025-11-28 18:00:00
2    Jane Smith           2025-11-28 08:55:00
2    Jane Smith           2025-11-28 18:05:00
```

### 3. Review Attendance Records

Navigate to `/attendance` to see processed records:

- **on_time** (green) - Within grace period
- **late** (yellow) - After grace period
- **absent** (red) - No time in
- **failed_bio_out** (orange) - Missing time out

### 4. Verify Records

HR/Admin can verify attendance records:

1. Click on a record to view details
2. Click "Verify" to confirm accuracy
3. Add remarks if needed

### 5. Manage Attendance Points

View and manage points at `/attendance-points`:

- See total points per employee
- View violation history
- Excuse points if needed
- Track expiration dates

## 🔧 Common Tasks

### Import Multiple Days

```bash
# Upload each day's file separately
# System handles cross-day shifts automatically
```

### Check Point Expiration Status

```php
// Via Tinker
$user = User::find(1);
$points = AttendancePoint::where('user_id', $user->id)
    ->whereNull('expired_at')
    ->get();

foreach ($points as $point) {
    echo "{$point->violation_type}: {$point->points} - Expires: {$point->expires_at}\n";
}
```

### Manual Point Expiration Processing

```bash
# Run expiration command manually
php artisan attendance:process-expirations
```

### View Processing Statistics

Navigate to `/attendance` and check the statistics panel:
- Total records processed
- On-time percentage
- Late count
- Absent count

## 📋 Quick Reference

### Status Types

| Status | Description |
|--------|-------------|
| on_time | On time |
| tardy | Tardy (late within threshold) |
| half_day_absence | Late >4 hours or significant absence |
| advised_absence | Pre-notified absence |
| ncns | No Call No Show |
| undertime | Left early |
| failed_bio_in | Missing time in biometric |
| failed_bio_out | Missing time out biometric |
| present_no_bio | Present but no biometric record |
| needs_manual_review | Requires manual review |
| non_work_day | Non-working day |
| on_leave | Employee on approved leave |

### Point Types & Expiration Rules

| Point Type | Points | Duration | GBRO Eligible |
|------------|--------|----------|---------------|
| tardy | 0.25 | 6 months | ✅ Yes |
| undertime | 0.25 | 6 months | ✅ Yes |
| undertime_more_than_hour | 0.50 | 6 months | ✅ Yes |
| half_day_absence | 0.50 | 6 months | ✅ Yes |
| whole_day_absence | 1.00 | 1 year | ❌ No |

### Key URLs

| Page | URL |
|------|-----|
| Attendance List | `/attendance` |
| Attendance Calendar | `/attendance/calendar/{user?}` |
| Import Attendance | `/attendance/import` |
| Review Attendance | `/attendance/review` |
| Attendance Statistics | `/attendance/statistics` |
| Attendance Points | `/attendance-points` |
| User Points | `/attendance-points/{user}` |
| Employee Schedules | `/employee-schedules` |

## 🧪 Testing

### Verify System is Working

```bash
# Run attendance tests
php artisan test --filter=Attendance

# Check schedule task is registered
php artisan schedule:list | grep expiration
```

### Manual Testing Checklist

- [ ] Upload biometric file → Records created
- [ ] Check status calculation → Correct based on schedule
- [ ] Verify point generation → Points assigned for violations
- [ ] Check expiration countdown → Shows remaining days
- [ ] Test record verification → Status updates correctly

## 🐛 Troubleshooting

### Records Show "failed_bio_out"

**Cause:** Missing time out record (night shift pending)

**Solution:** Upload the next day's file to complete the shift

### Points Not Generating

**Cause:** Status doesn't qualify for points

**Check:**
```php
// Point types and their values
$pointValues = [
    'tardy' => 0.25,
    'undertime' => 0.25,
    'undertime_more_than_hour' => 0.50,
    'half_day_absence' => 0.50,
    'whole_day_absence' => 1.00,
];
```

### Wrong Shift Date Grouping

**Cause:** Schedule not configured correctly

**Check:**
```php
$schedule = EmployeeSchedule::where('user_id', $userId)->first();
echo "In: {$schedule->scheduled_time_in}, Out: {$schedule->scheduled_time_out}";
```

### Expiration Not Running

**Cause:** Scheduler not running

**Solution:**
```bash
# Verify cron is set up
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1

# Run manually
php artisan attendance:process-expirations
```

## 📚 Next Steps

1. **Set Up All Schedules** - Configure schedules for all employees
2. **Import Historical Data** - Process past attendance files
3. **Configure Notifications** - Set up alerts for violations
4. **Train HR Staff** - Show them the verification workflow
5. **Monitor Points** - Track employee accountability

## 🔗 Related Documentation

- [Attendance Grouping Logic](ATTENDANCE_GROUPING_LOGIC.md)
- [Point Expiration Rules](POINT_EXPIRATION_RULES.md)
- [Biometric Records](../biometric/README.md)

---

**Need help?** Check the [full documentation](README.md) or the [implementation summary](IMPLEMENTATION_SUMMARY.md).
