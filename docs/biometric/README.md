# Biometric Records Documentation

This directory contains documentation for biometric record storage, audit trails, and management features.

---

## 🚀 Quick Links

- **[QUICKSTART.md](QUICKSTART.md)** - Get started quickly
- **[BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md](BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)** - Technical overview

---

## 📄 Documents

### [BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md](BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)
**Implementation Overview**

Quick summary of the biometric records storage feature implementation.

**Topics Covered:**
- ✅ BiometricRecord model creation
- ✅ AttendanceProcessor updates
- ✅ Cleanup command (`biometric:clean-old-records`)
- ✅ Scheduled daily cleanup (2:00 AM)
- ✅ Migration details
- ✅ Setup instructions

**Best For:**
- Understanding what was built
- Quick implementation reference
- Setup and configuration
- Next steps after deployment

---

### [BIOMETRIC_RECORDS_STORAGE.md](BIOMETRIC_RECORDS_STORAGE.md)
**Storage & Data Management**

Complete guide to biometric record storage, audit trails, and data lifecycle.

**Topics Covered:**
- ✅ **Audit Trail** - Preserving every fingerprint scan
- ✅ **Cross-Day Timeout Handling** - Completing shifts across uploads
- ✅ **Debugging & Reports** - Analyzing scan patterns
- ✅ **Reprocessing Capability** - Updating attendance without re-uploads
- ✅ **Database Schema** - Table structure and indexes
- ✅ **Data Lifecycle** - 3-month retention policy
- ✅ **Storage Metrics** - Database size calculations

**Best For:**
- Understanding data storage architecture
- Learning retention policies
- Database optimization
- Audit compliance requirements
- Reprocessing attendance data

**Key Features:**
- 3-month automatic retention
- 90 MB storage for 200 employees
- Daily cleanup at 2:00 AM
- Complete scan history preservation

---

### [BIOMETRIC_RECORDS_UI.md](BIOMETRIC_RECORDS_UI.md)
**User Interface Documentation**

Complete guide to the biometric records management UI.

**Topics Covered:**
- ✅ **Backend Controller** - `BiometricRecordController.php`
- ✅ **Routes** - `/biometric-records/*`
- ✅ **Main UI Page** - `BiometricRecords/Index.tsx`
- ✅ **Statistics Dashboard** - Real-time metrics
- ✅ **Advanced Filters** - Search and filtering
- ✅ **Records Table** - Data display
- ✅ **Detail View** - Individual record inspection
- ✅ **Integration** - Navigation and menu items

**Best For:**
- Understanding UI features
- Frontend development
- User workflow design
- Feature testing

**UI Features:**
- Statistics cards (total, today, week, cleanup)
- Multi-filter system (employee, site, date range)
- Paginated records table (50 per page)
- Real-time search
- Detailed record view

---

## 🔗 Related Documentation

### In Project Root
- **[../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)** - 4 new features (reprocessing, anomalies, export, retention policies)
- **[../../BIOMETRIC_ENHANCEMENTS_STATUS.md](../../BIOMETRIC_ENHANCEMENTS_STATUS.md)** - Implementation status checklist
- **[../../BIOMETRIC_UI_FIXES.md](../../BIOMETRIC_UI_FIXES.md)** - UI troubleshooting guide

### In Attendance Folder
- **[../attendance/CROSS_UPLOAD_TIMEOUT_HANDLING.md](../attendance/CROSS_UPLOAD_TIMEOUT_HANDLING.md)** - Multi-upload scenarios
- **[../attendance/ATTENDANCE_GROUPING_LOGIC.md](../attendance/ATTENDANCE_GROUPING_LOGIC.md)** - Shift detection algorithm

---

## 🎯 Quick Reference

### Database Schema
```sql
biometric_records
├── id (primary key)
├── user_id (foreign key → users)
├── attendance_upload_id (foreign key → attendance_uploads)
├── site_id (foreign key → sites)
├── employee_name (string)
├── datetime (timestamp)
├── record_date (date, indexed)
├── record_time (time)
└── timestamps
```

### Retention Policy
- **Default Retention:** 3 months (90 days) - configurable via policies
- **Policy Management:** Site-specific or global retention periods
- **Cleanup Schedule:** Daily at 2:00 AM
- **Manual Cleanup:** `php artisan biometric:clean-old-records --force`
- **Custom Retention:** `--months` flag for manual override (e.g., `--months=6`)
- **Priority System:** Site-specific policies override global policies

### Storage Metrics
| Employees | Records/Day | 3-Month Storage |
|-----------|-------------|-----------------|
| 50        | ~200        | ~23 MB          |
| 100       | ~400        | ~45 MB          |
| 200       | ~800        | ~90 MB          |
| 500       | ~2000       | ~225 MB         |

---

## 🚀 Features

### Core Features
1. **Audit Trail** - Every scan preserved with original device name
2. **Cross-Upload Resolution** - Complete shifts across multiple uploads
3. **Reprocessing** - Update attendance without re-uploading files
4. **Debugging** - Query exact timestamps and patterns
5. **Compliance** - 3-month audit trail for labor law requirements

### New Enhancements (2025)
1. **Reprocessing UI** - Recalculate attendance with updated algorithm
2. **Anomaly Detection** - Find unusual scan patterns
3. **CSV Export** - Download records for external analysis
4. **Retention Policies** - Customizable retention per site with priority system

See **[../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)** for details.

---

## 🎓 Learning Path

### For Developers
1. **[BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md](BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)**
   - Quick overview of what exists
   - Setup instructions
   - Next steps

2. **[BIOMETRIC_RECORDS_STORAGE.md](BIOMETRIC_RECORDS_STORAGE.md)**
   - Deep dive into architecture
   - Database schema
   - Data lifecycle

3. **[BIOMETRIC_RECORDS_UI.md](BIOMETRIC_RECORDS_UI.md)**
   - UI components
   - User workflows
   - Frontend integration

4. **[../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)**
   - New features (2025)
   - Advanced capabilities

### For Product/Business
1. **[BIOMETRIC_RECORDS_STORAGE.md](BIOMETRIC_RECORDS_STORAGE.md)**
   - Features overview
   - Business benefits
   - Compliance value

2. **[BIOMETRIC_RECORDS_UI.md](BIOMETRIC_RECORDS_UI.md)**
   - User interface
   - Feature walkthrough

3. **[../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)**
   - Recent enhancements
   - Roadmap

---

## 🔧 Common Tasks

### Viewing Biometric Records
1. Navigate to `/biometric-records`
2. Use filters (employee, site, date range)
3. Click record to see details

### Cleaning Old Records
```bash
# Automatic cleanup (uses retention policies from database)
php artisan biometric:clean-old-records --force

# Interactive cleanup (asks for confirmation per site)
php artisan biometric:clean-old-records

# Manual override (bypass policies, use specific months for all sites)
php artisan biometric:clean-old-records --months=6 --force

# Check scheduled task
php artisan schedule:list
```

### Reprocessing Attendance
1. Navigate to `/biometric-reprocessing`
2. Select date range
3. Preview affected records
4. Execute reprocessing

See **[../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)** for details.

### Detecting Anomalies
1. Navigate to `/biometric-anomalies`
2. Select date range and anomaly types
3. Review detected patterns
4. Take corrective actions

### Exporting Records
1. Navigate to `/biometric-export`
2. Select filters (dates, employees, sites)
3. Download CSV file

### Managing Retention Policies
1. Navigate to `/biometric-retention-policies`
2. Create new policy (global or site-specific)
3. Set retention period in months
4. Configure priority (higher number = higher priority)
5. Activate/deactivate policies as needed

**How Policies Work:**
- Site-specific policies override global policies
- Higher priority wins when multiple policies apply
- Cleanup command applies policies automatically
- Each site can have different retention periods

---

## 📊 Key Files in Codebase

### Models
- `app/Models/BiometricRecord.php` - Main model
- `app/Models/BiometricRetentionPolicy.php` - Retention policies

### Services
- `app/Services/AttendanceProcessor.php` - Saves records during upload
- `app/Services/BiometricAnomalyDetector.php` - Detects anomalies

### Controllers
- `app/Http/Controllers/BiometricRecordController.php` - Main CRUD
- `app/Http/Controllers/BiometricReprocessingController.php` - Reprocessing
- `app/Http/Controllers/BiometricAnomalyController.php` - Anomaly detection
- `app/Http/Controllers/BiometricExportController.php` - CSV export
- `app/Http/Controllers/BiometricRetentionPolicyController.php` - Policies

### Frontend Pages
- `resources/js/pages/BiometricRecords/Index.tsx` - Main listing
- `resources/js/pages/BiometricRecords/Reprocessing.tsx` - Reprocessing UI
- `resources/js/pages/BiometricRecords/Anomalies.tsx` - Anomaly detection UI
- `resources/js/pages/BiometricRecords/Export.tsx` - Export UI
- `resources/js/pages/BiometricRecords/RetentionPolicies.tsx` - Policies UI

### Commands
- `app/Console/Commands/CleanOldBiometricRecords.php` - Cleanup command

---

## 🧪 Testing

### Related Tests
```bash
# Test biometric record creation
php artisan test --filter=BiometricRecord

# Test attendance processing with biometric records
php artisan test tests/Unit/AttendanceProcessorTest.php
```

### Manual Testing Checklist
- [ ] Upload biometric file → Records saved
- [ ] Navigate to `/biometric-records` → UI loads
- [ ] Apply filters → Records filtered correctly
- [ ] View record details → Shows all data
- [ ] Wait 3 months → Records auto-deleted
- [ ] Run cleanup command → Old records removed
- [ ] Reprocess attendance → Uses stored records

---

## 💡 Benefits

### Operational
- ✅ **No Re-uploads:** Fix attendance without re-uploading files
- ✅ **Cross-Upload Resolution:** Night shifts completed automatically
- ✅ **Debug Capability:** Query exact scan timestamps
- ✅ **Pattern Analysis:** Identify recurring issues

### Compliance
- ✅ **Audit Trail:** 3-month scan history
- ✅ **Labor Law:** Documentation for disputes
- ✅ **Data Retention:** Automatic cleanup
- ✅ **Traceability:** Link to original upload

### Technical
- ✅ **Reprocessing:** Update algorithm without data loss
- ✅ **Performance:** Indexed for fast queries
- ✅ **Storage:** Efficient (~90 MB for 200 employees)
- ✅ **Scalability:** Handles large datasets

---

## 🔐 Security & Compliance

### Data Protection
- Personal data (scans) stored securely
- Automatic deletion after 3 months
- Access controlled by authentication
- Audit trail preserved

### Retention Compliance
- Configurable retention periods per site or globally
- Site-specific policies override global policies
- Priority system for policy resolution
- Manual override capability for special cases
- Automatic enforcement via scheduled cleanup
- Detailed logging for audit purposes

---

**Need Help?**
- Implementation → [BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md](BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)
- Architecture → [BIOMETRIC_RECORDS_STORAGE.md](BIOMETRIC_RECORDS_STORAGE.md)
- UI Features → [BIOMETRIC_RECORDS_UI.md](BIOMETRIC_RECORDS_UI.md)
- New Features → [../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)

---

*Last updated: November 22, 2025*
