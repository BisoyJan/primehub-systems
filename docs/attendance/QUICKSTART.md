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

Or navigate to `/schedules` in the application.

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

| Status | Points | Description |
|--------|--------|-------------|
| on_time | 0 | On time |
| late | 0.25 | Tardy |
| half_day | 0.50 | Late >4 hours |
| absent | 0 | No record (manual) |
| ncns | 1.00 | No Call No Show |
| ftn | 1.00 | Failure to Notify |

### Expiration Rules

| Type | Duration | GBRO Eligible |
|------|----------|---------------|
| Tardy | 6 months | ✅ Yes |
| Half-Day | 6 months | ✅ Yes |
| NCNS | 1 year | ❌ No |
| FTN | 1 year | ❌ No |

### Key URLs

| Page | URL |
|------|-----|
| Attendance List | `/attendance` |
| Import Attendance | `/attendance/import` |
| Attendance Points | `/attendance-points` |
| User Points | `/attendance-points/{userId}` |
| Schedules | `/schedules` |

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
// Only these statuses generate points
$pointStatuses = ['late', 'half_day', 'ncns', 'ftn'];
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
