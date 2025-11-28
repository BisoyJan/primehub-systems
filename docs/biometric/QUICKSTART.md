# Biometric Records - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Attendance system configured
- Biometric file imports working
- Database migrations run

### 1. View Biometric Records

Navigate to `/biometric-records` to see all stored scan records:

- Filter by employee name
- Filter by site
- Filter by date range
- View record details

### 2. Understand Record Storage

Every biometric scan is automatically stored when attendance is processed:

```
Upload File → Parse Records → Save to biometric_records
                           → Process Attendance
```

Records include:
- Employee name (from biometric device)
- Exact timestamp
- Site information
- Link to upload batch

## 🔧 Common Tasks

### Clean Old Records

Records are automatically cleaned after 3 months. To run manually:

```bash
# Clean with default retention (uses policies from database)
php artisan biometric:clean-old-records --force

# Interactive mode (asks for confirmation)
php artisan biometric:clean-old-records

# Override with custom retention
php artisan biometric:clean-old-records --months=6 --force
```

### Reprocess Attendance

If attendance algorithm is updated:

1. Navigate to `/biometric-reprocessing`
2. Select date range
3. Preview affected records
4. Click "Reprocess"

### Detect Anomalies

Find unusual patterns:

1. Navigate to `/biometric-anomalies`
2. Select anomaly type:
   - Duplicate scans
   - Unusual times
   - Missing records
3. Review and investigate

### Export Records

Download records for external analysis:

1. Navigate to `/biometric-export`
2. Select filters (date, employee, site)
3. Click "Download CSV"

### Manage Retention Policies

Configure different retention periods:

1. Navigate to `/biometric-retention-policies`
2. Create policy:
   - Global (applies to all sites)
   - Site-specific (overrides global)
3. Set retention months
4. Set priority (higher = wins conflicts)
5. Activate the policy

## 📋 Quick Reference

### Key URLs

| Page | URL |
|------|-----|
| Records List | `/biometric-records` |
| Reprocessing | `/biometric-reprocessing` |
| Anomaly Detection | `/biometric-anomalies` |
| Export | `/biometric-export` |
| Retention Policies | `/biometric-retention-policies` |

### Retention Policy Priority

```
Site-Specific Policy (priority: 10)  ← Wins
    ↑
Global Policy (priority: 5)
    ↑
Default (3 months)
```

### Storage Estimates

| Employees | Records/Day | 3-Month Storage |
|-----------|-------------|-----------------|
| 50 | ~200 | ~23 MB |
| 100 | ~400 | ~45 MB |
| 200 | ~800 | ~90 MB |

### Scheduled Tasks

| Task | Schedule | Command |
|------|----------|---------|
| Clean Records | Daily 2:00 AM | `biometric:clean-old-records` |

## 🧪 Testing

### Verify Records Are Being Saved

```php
php artisan tinker

// Check recent records
BiometricRecord::latest()->take(10)->get();

// Count by date
BiometricRecord::whereDate('record_date', today())->count();
```

### Test Cleanup Command

```bash
# Dry run (see what would be deleted)
php artisan biometric:clean-old-records

# Force execution
php artisan biometric:clean-old-records --force
```

### Manual Testing Checklist

- [ ] Upload attendance file → Biometric records created
- [ ] Navigate to `/biometric-records` → Records visible
- [ ] Apply filters → Records filter correctly
- [ ] Click record → Details show
- [ ] Run cleanup → Old records deleted
- [ ] Create retention policy → Policy applied on cleanup

## 🐛 Troubleshooting

### No Records Showing

**Cause:** Records not being saved during import

**Check:**
```php
// Verify AttendanceProcessor is saving records
// Look for BiometricRecord::create() calls
```

### Cleanup Not Running

**Cause:** Scheduler not configured

**Solution:**
```bash
# Check scheduler
php artisan schedule:list

# Run manually
php artisan biometric:clean-old-records --force
```

### Reprocessing Fails

**Cause:** No biometric records for date range

**Solution:** Ensure records exist before reprocessing

```php
BiometricRecord::whereBetween('record_date', [$start, $end])->count();
```

### Policy Not Applied

**Cause:** Policy not active or wrong priority

**Check:**
```php
BiometricRetentionPolicy::where('is_active', true)
    ->orderBy('priority', 'desc')
    ->get();
```

## 📊 Statistics Dashboard

The biometric records page shows:

- **Total Records** - All stored records
- **Today's Records** - Records from current day
- **This Week** - Records from current week
- **Next Cleanup** - Records scheduled for deletion

## 🔗 Related Documentation

- [Biometric Storage](BIOMETRIC_RECORDS_STORAGE.md)
- [Implementation Summary](BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)
- [UI Documentation](BIOMETRIC_RECORDS_UI.md)
- [Attendance System](../attendance/README.md)

---

**Need help?** Check the [full documentation](README.md) or [implementation details](BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md).
