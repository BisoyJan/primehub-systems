# Biometric Records Storage & Management

## Overview
The system now stores **all raw biometric records** permanently in the `biometric_records` table for audit trail, debugging, and reprocessing capabilities. Records are automatically deleted based on **configurable retention policies** (default: 3 months) to manage database size while maintaining compliance flexibility.

## Features

### ✅ Audit Trail
- Every fingerprint scan from biometric devices is stored
- Preserves original employee name as written by device
- Links to upload file for traceability
- Tracks which site's device recorded the scan

### ✅ Cross-Day Timeout Handling
- Records from Wednesday can update Tuesday's attendance
- All scans are queryable across multiple uploads
- Eliminates "missing timeout" issues for night shifts
- Example: Tuesday 22:00 time in + Wednesday 07:00 time out = Complete record

### ✅ Debugging & Reports
- View exact timestamps from biometric devices
- Identify patterns in late arrivals or early departures
- Analyze biometric device usage across sites
- Track employee movement between sites

### ✅ Reprocessing Capability
- Update attendance algorithm without re-uploading files
- Fix past attendance issues by reprocessing stored records
- Run reports on historical biometric data

### ✅ Configurable Retention Policies
- Site-specific or global retention periods
- Priority-based policy resolution
- Flexible compliance with different regulatory requirements
- Automatic enforcement via scheduled cleanup

## Database Schema

```sql
biometric_records
├── id (primary key)
├── user_id (foreign key → users)
├── attendance_upload_id (foreign key → attendance_uploads)
├── site_id (foreign key → sites) -- Biometric device location
├── employee_name (string) -- Original name from device
├── datetime (timestamp) -- Exact scan time
├── record_date (date, indexed) -- For quick date lookups
├── record_time (time) -- For time range queries
└── timestamps (created_at, updated_at)

Indexes:
- (user_id, record_date, record_time) -- Fast time in/out searches
- (user_id, datetime) -- Historical queries
- record_date -- Cleanup operations

biometric_retention_policies
├── id (primary key)
├── name (string) -- Policy name
├── description (text, nullable) -- Policy description
├── retention_months (integer) -- Months to retain data (default: 3)
├── applies_to_type (string) -- 'global', 'site', or 'department'
├── applies_to_id (foreign key, nullable) -- site_id or department_id if applicable
├── priority (integer) -- Higher number = higher priority (default: 0)
├── is_active (boolean) -- Whether policy is active (default: true)
└── timestamps (created_at, updated_at)

Indexes:
- (applies_to_type, applies_to_id) -- Fast policy lookups
- is_active -- Active policy queries
```

## Data Lifecycle

### 1. Upload & Storage
```
TXT File Upload
    ↓
Parse Records
    ↓
Save to biometric_records (ALL records)
    ↓
Process Attendance (existing logic)
```

### 2. Retention Period
**Records are kept based on configured retention policies:**

**Default Policy:**
- 3 months for all sites (if no custom policies exist)
- Sufficient for payroll processing and corrections
- Covers typical attendance dispute resolution timeframes
- Balances audit needs with database performance

**Custom Policies:**
- Create site-specific or department-specific retention periods
- Override global settings with higher priority
- Support different regulatory requirements per location/department
- Flexible compliance management
- Options: 'global', 'site', or 'department'

**Policy Resolution:**
1. Check for active site-specific policy
2. Fall back to global policy if no site policy exists
3. Use default 3 months if no policies configured
4. Higher priority wins if multiple policies apply

### 3. Automatic Cleanup
**Scheduled Task:** Daily at 2:00 AM
- Applies retention policies per site automatically
- Deletes records older than configured retention period
- Logs deletion count per site for audit
- Runs on single server (prevents duplicates)
- Prevents overlap with concurrent runs

**Manual Cleanup:**
```bash
# Automatic cleanup using retention policies (default)
php artisan biometric:clean-old-records --force

# Interactive cleanup (asks for confirmation per site)
php artisan biometric:clean-old-records

# Manual override - bypass policies and use specific months for all sites
php artisan biometric:clean-old-records --months=6 --force
```

**How Cleanup Works:**
1. **Query Policies:** Get retention period for each site
2. **Calculate Cutoff:** Subtract retention months from current date
3. **Delete Records:** Remove records older than cutoff date
4. **Log Results:** Record deletion count per site
5. **Repeat:** Process all sites separately

Example:
- Site A has 12-month policy → keeps records from Nov 2024 onwards
- Site B has 6-month policy → keeps records from May 2025 onwards
- No policy for Site C → uses default 3 months (Aug 2025 onwards)

## How It Works

### Saving Records
When an attendance file is uploaded:

1. **Parse** the TXT file into individual records
2. **Match** employee names to users in database
3. **Save** each record to `biometric_records` table:
   - Links to user (for matched employees)
   - Links to upload file (for traceability)
   - Links to site (device location)
   - Stores original name (for debugging mismatches)
   - Extracts date and time for fast queries

4. **Process** attendance using stored records (existing logic)

### Unmatched Employees
- Records for unmatched names are **logged but not saved**
- Prevents orphaned data in biometric_records
- Admin can view unmatched names in upload summary
- Fix name mismatches and reprocess if needed

## Use Cases

### 1. Cross-Day Timeout Resolution
**Scenario:** Tuesday night shift (22:00-07:00)
- **Tuesday upload:** Time in at 22:05 recorded
- **Wednesday upload:** Timeout at 07:02 stored in biometric_records
- **System:** Automatically links Wednesday 07:02 to Tuesday's shift
- **Result:** Complete attendance record without manual intervention

### 2. Debugging Missing Attendance
**Problem:** Employee claims they bio'd in but status shows NCNS

**Solution:**
```php
// Query raw biometric records
$records = BiometricRecord::forUser($userId)
    ->forDate($shiftDate)
    ->orderedByTime()
    ->get();

// Check:
// - Did their scan actually happen?
// - Was it at correct site?
// - Was name spelling different?
// - Was time outside expected range?
```

### 3. Reprocessing with Updated Algorithm
**Scenario:** Attendance algorithm improved to handle edge cases

**Solution:**
```php
// Get stored records for affected period
$records = BiometricRecord::dateRange($startDate, $endDate)
    ->forUser($userId)
    ->get();

// Reprocess using new logic
AttendanceProcessor::reprocessRecords($records);
```

### 4. Cross-Site Movement Reports
**Query:** Which employees frequently bio at different sites?

```php
$crossSiteEmployees = DB::table('biometric_records as br')
    ->join('employee_schedules as es', 'br.user_id', '=', 'es.user_id')
    ->where('br.site_id', '!=', DB::raw('es.site_id'))
    ->groupBy('br.user_id')
    ->havingRaw('COUNT(*) > 5')
    ->get();
```

## Model Usage

### Basic Queries
```php
use App\Models\BiometricRecord;
use Carbon\Carbon;

// Get records for specific user and date
$records = BiometricRecord::forUser($userId)
    ->forDate(Carbon::today())
    ->orderedByTime()
    ->get();

// Get records for date range
$records = BiometricRecord::dateRange($startDate, $endDate)
    ->forSite($siteId)
    ->get();

// Get old records (for cleanup preview)
$oldRecords = BiometricRecord::olderThan(3)->count();
```

### Relationships
```php
$record = BiometricRecord::find(1);

$record->user; // User who made this scan
$record->attendanceUpload; // Upload file that contained this record
$record->site; // Site where biometric device is located
```

## Performance Considerations

### Indexing Strategy
- **record_date indexed:** Fast cleanup operations
- **(user_id, record_date, record_time):** Optimized for time in/out searches
- **(user_id, datetime):** Efficient historical queries

### Bulk Operations
- Records inserted using **bulk insert** (not individual inserts)
- Cleanup uses **batch delete** with date index
- Upload processing streams large files (doesn't load all in memory)

### Growth Estimation
- Average 200 employees × 2 scans/day × 90 days = ~36,000 records
- With timestamps: ~2.5 KB per record = ~90 MB per 3 months
- Highly manageable even for large operations

## Monitoring & Maintenance

### Check Storage Size
```bash
# MySQL
SELECT 
    TABLE_NAME,
    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS 'Size (MB)',
    TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_NAME = 'biometric_records';
```

### Monitor Cleanup Logs
```bash
# View cleanup history
tail -f storage/logs/laravel.log | grep "Biometric records cleanup"
```

### Adjust Retention Period
If 3 months isn't suitable for your organization:

1. **Update schedule** in `app/Console/Kernel.php`:
```php
$schedule->command('biometric:clean-old-records --months=6')
    ->dailyAt('02:00');
```

2. **Or run manually** with custom period:
```bash
php artisan biometric:clean-old-records --months=6
```

## Migration

### Running the Migration
```bash
php artisan migrate
```

This creates the `biometric_records` table with proper indexes and foreign keys.

### Rollback
```bash
php artisan migrate:rollback
```

This drops the table and all stored records (use with caution!).

## Laravel Scheduler Setup

For automatic cleanup to work, ensure Laravel's scheduler is running:

### Linux/Mac (Cron)
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

### Windows (Task Scheduler)
Create a scheduled task that runs every minute:
```
Program: C:\php\php.exe
Arguments: C:\path-to-project\artisan schedule:run
```

### Docker
Add to your container's entrypoint:
```bash
while true; do
    php artisan schedule:run
    sleep 60
done &
```

## Security & Privacy

### Data Retention Compliance
- Configurable retention aligns with varying regulatory requirements
- Site-specific policies support different jurisdictions
- Old records automatically purged (GDPR "right to erasure")
- Audit trail maintained for configured period
- Priority system allows flexible compliance management

### Access Control
Use Laravel policies to restrict access:

```php
// app/Policies/BiometricRecordPolicy.php
public function view(User $user, BiometricRecord $record)
{
    // Only admins or the employee themselves
    return $user->is_admin || $user->id === $record->user_id;
}
```

## Retention Policy Management

### Creating Policies

**Via UI:**
1. Navigate to `/biometric-retention-policies`
2. Click "Add Policy"
3. Configure:
   - Name and description
   - Retention period (months)
   - Applies to: Global or specific site
   - Priority (higher wins)
   - Active status

**Example Policies:**
```
Policy 1: "EU Sites Compliance"
- Retention: 6 months
- Applies to: Site "London Office"
- Priority: 100
- Status: Active

Policy 2: "Default Retention"
- Retention: 3 months
- Applies to: Global
- Priority: 50
- Status: Active

Result: London Office keeps 6 months, all others keep 3 months
```

### Policy Resolution Logic

```php
// Get retention for a site
$retentionMonths = BiometricRetentionPolicy::getRetentionMonths($siteId);

// Resolution order:
// 1. Active site-specific policy (highest priority)
// 2. Active global policy (highest priority)
// 3. Default: 3 months
```

### Policy Priority Examples

**Scenario 1: Simple Override**
- Global policy: 3 months (priority 50)
- Site A policy: 12 months (priority 100)
- Result: Site A uses 12 months, others use 3 months

**Scenario 2: Multiple Site Policies**
- Global policy: 3 months (priority 50)
- Site A policy: 12 months (priority 100)
- Site A policy 2: 6 months (priority 80)
- Result: Site A uses 12 months (higher priority wins)

**Scenario 3: Deactivated Policy**
- Global policy: 3 months (priority 50, active)
- Site A policy: 12 months (priority 100, inactive)
- Result: Site A falls back to global 3 months

### Testing Policies

**Check what will be deleted:**
```bash
# Dry-run mode (shows what would be deleted without actually deleting)
php artisan biometric:clean-old-records

# View calculated retention for each site
SELECT s.name, 
       BiometricRetentionPolicy::getRetentionMonths(s.id) as retention_months
FROM sites s;
```

**Manual test cleanup:**
```bash
# Run cleanup with confirmation prompts
php artisan biometric:clean-old-records

# Force cleanup without prompts
php artisan biometric:clean-old-records --force
```

## Troubleshooting

### Records Not Being Saved
**Check:**
1. Migration ran successfully: `php artisan migrate:status`
2. Upload processing completed: Check `attendance_uploads.status`
3. Logs for errors: `storage/logs/laravel.log`
4. Employee names matched: Check unmatched_names_list in upload

### Cleanup Not Running
**Check:**
1. Laravel scheduler is running: `php artisan schedule:list`
2. Cron/Task Scheduler configured correctly
3. Server timezone matches application timezone
4. Logs: `tail storage/logs/laravel.log | grep biometric`
5. Retention policies configured: Check `/biometric-retention-policies`

### Policies Not Being Applied
**Check:**
1. Policy is active: Check `is_active` column
2. Policy priority: Higher priority wins
3. Site association: Ensure `applies_to_id` matches site
4. Manual override: Check if `--months` flag is being used
5. Logs: Review cleanup logs for applied retention periods

### Query Performance Slow
**Solutions:**
1. Ensure indexes exist: `SHOW INDEX FROM biometric_records;`
2. Run cleanup if records accumulate: `php artisan biometric:clean-old-records --force`
3. Add site_id index if filtering by site frequently
4. Consider partitioning table by month for very large datasets
5. Review retention policies to avoid keeping too much data

## Future Enhancements

Completed:
- ✅ **Reprocessing UI:** Admin interface to reprocess specific date ranges
- ✅ **Retention Policies:** Configurable per-site retention with priority system
- ✅ **Anomaly Detection:** Flag unusual patterns (e.g., bio at 2 sites simultaneously)
- ✅ **Export Feature:** Download raw biometric data for external analysis

Potential additions:
- **Archive Table:** Move old records to archive instead of deleting
- **Retention Reports:** Dashboard showing retention compliance per site
- **Policy Simulation:** Preview impact of policy changes before applying

---

*Last updated: December 15, 2025*
