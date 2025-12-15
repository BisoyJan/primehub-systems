# Database Schema Reference

Complete database schema documentation for PrimeHub Systems.

---

## 📊 Entity Relationship Diagram

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│      Users      │────<│   Attendances   │>────│ EmployeeSchedule│
└─────────────────┘     └─────────────────┘     └─────────────────┘
        │                       │
        │                       │
        ▼                       ▼
┌─────────────────┐     ┌─────────────────┐
│ AttendancePoints│     │ BiometricRecords│
└─────────────────┘     └─────────────────┘
        │
        │
        ▼
┌─────────────────┐     ┌─────────────────┐
│  LeaveCredits   │────<│  LeaveRequests  │
└─────────────────┘     └─────────────────┘


┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│      Sites      │────<│    Stations     │>────│   Campaigns     │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                               │
                               ▼
                        ┌─────────────────┐
                        │    PcSpecs      │>────── RamSpecs
                        └─────────────────┘>────── DiskSpecs
                               │           >────── ProcessorSpecs
                               │           >────── MonitorSpecs
                               ▼
                        ┌─────────────────┐
                        │ PcMaintenances  │
                        └─────────────────┘
```

---

## 🗄️ Core Tables

### users
Primary user accounts table.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| first_name | varchar(255) | User's first name |
| middle_name | varchar(255) | User's middle name (nullable) |
| last_name | varchar(255) | User's last name |
| email | varchar(255) | Unique email address |
| email_verified_at | timestamp | Email verification timestamp |
| password | varchar(255) | Hashed password |
| role | enum | User role (Super Admin, Admin, Team Lead, Agent, HR, IT, Utility) |
| is_active | boolean | Account active status (default: true) |
| is_approved | boolean | Account approval status (default: false) |
| time_format | enum | Preferred time format (12 or 24, default: 24) |
| hired_date | date | Employment start date (nullable) |
| approved_at | timestamp | Approval timestamp |
| two_factor_secret | text | 2FA secret (nullable) |
| two_factor_recovery_codes | text | 2FA recovery codes (nullable) |
| two_factor_confirmed_at | timestamp | 2FA confirmation timestamp |
| remember_token | varchar(100) | Session remember token |
| deleted_at | timestamp | Soft delete timestamp (nullable) |
| deleted_by | bigint | User who deleted (nullable, foreign key) |
| deletion_confirmed_at | timestamp | Deletion confirmation timestamp (nullable) |
| deletion_confirmed_by | bigint | User who confirmed deletion (nullable, foreign key) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

**Indexes**: email (unique)

---

## 🖥️ Computer/Hardware Tables

### pc_specs
Computer specifications.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| pc_number | varchar(255) | Unique PC identifier |
| manufacturer | varchar(255) | PC manufacturer |
| model | varchar(255) | PC model |
| form_factor | varchar(255) | Form factor type |
| memory_type | varchar(255) | Memory type (DDR4, etc) |
| ram_slots | integer | Number of RAM slots |
| max_ram_capacity_gb | integer | Maximum RAM capacity |
| max_ram_speed | integer | Maximum RAM speed |
| m2_slots | integer | Number of M.2 slots |
| sata_ports | integer | Number of SATA ports |
| issue | text | Current issues (nullable) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### ram_specs
RAM specifications.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| model | varchar(255) | RAM model name |
| capacity_gb | integer | Capacity in GB |
| speed | integer | Speed in MHz |
| type | varchar(50) | DDR type (DDR3, DDR4, DDR5) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### disk_specs
Storage specifications.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| model | varchar(255) | Disk model name |
| capacity_gb | integer | Capacity in GB |
| drive_type | enum | SSD or HDD |
| interface | varchar(255) | Interface type |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### processor_specs
CPU specifications.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| model | varchar(255) | Processor model name |
| cores | integer | Number of cores |
| threads | integer | Number of threads |
| base_clock | decimal | Base clock speed |
| boost_clock | decimal | Boost clock speed |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### monitor_specs
Monitor specifications.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| model | varchar(255) | Monitor model name |
| size | varchar(50) | Screen size |
| resolution | varchar(50) | Display resolution |
| panel_type | varchar(50) | Panel type (IPS, VA, TN) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### Pivot Tables

#### pc_spec_ram_spec
| Column | Type |
|--------|------|
| pc_spec_id | bigint |
| ram_spec_id | bigint |
| quantity | integer |

#### pc_spec_disk_spec
| Column | Type |
|--------|------|
| pc_spec_id | bigint |
| disk_spec_id | bigint |

**Primary Key**: (pc_spec_id, disk_spec_id)

#### pc_spec_processor_spec
| Column | Type |
|--------|------|
| pc_spec_id | bigint |
| processor_spec_id | bigint |

#### monitor_pc_spec
| Column | Type |
|--------|------|
| pc_spec_id | bigint |
| monitor_spec_id | bigint |
| quantity | integer |

### stocks
Hardware inventory tracking (polymorphic).

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| stockable_type | varchar(255) | Model class name |
| stockable_id | bigint | Model ID |
| quantity | integer | Available quantity |
| reserved | integer | Reserved quantity |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

---

## 🏢 Station/Site Tables

### sites
Physical locations.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | varchar(255) | Site name (unique) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### campaigns
Campaigns/projects.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | varchar(255) | Campaign name (unique) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### stations
Workstations.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| site_id | bigint | Foreign key to sites |
| station_number | varchar(255) | Station identifier |
| campaign_id | bigint | Foreign key to campaigns |
| status | enum | Active, Vacant, Maintenance |
| monitor_type | enum | single, dual |
| pc_spec_id | bigint | Foreign key to pc_specs (nullable) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

#### monitor_station
| Column | Type |
|--------|------|
| station_id | bigint |
| monitor_spec_id | bigint |
| quantity | integer |

### pc_transfers
PC transfer history.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| from_station_id | bigint | Source station (nullable) |
| to_station_id | bigint | Destination station (nullable) |
| pc_spec_id | bigint | PC being transferred |
| user_id | bigint | User who performed transfer |
| transfer_type | varchar(255) | Type of transfer |
| notes | text | Transfer notes (nullable) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### pc_maintenances
Maintenance tracking.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| station_id | bigint | Foreign key to stations |
| last_maintenance_date | date | Last maintenance date |
| next_due_date | date | Next due date |
| maintenance_type | varchar(255) | Type of maintenance |
| notes | text | Maintenance notes (nullable) |
| performed_by | varchar(255) | Technician name |
| status | enum | pending, overdue, completed |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

---

## ⏰ Attendance Tables

### employee_schedules
Work schedules for employees with shift type classification.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| campaign_id | bigint | Foreign key to campaigns (nullable) |
| site_id | bigint | Foreign key to sites (nullable) |
| shift_type | enum | morning_shift, afternoon_shift, night_shift, graveyard_shift, utility_24h |
| scheduled_time_in | time | Scheduled start time |
| scheduled_time_out | time | Scheduled end time |
| work_days | json | Array of work days (e.g., ["monday", "tuesday", ...]) |
| grace_period_minutes | integer | Minutes before considered tardy (default: 15) |
| effective_date | date | Schedule start date (hired date) |
| end_date | date | Schedule end date (nullable) |
| is_active | boolean | Currently active schedule |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

**Shift Types & Recommended Time Ranges:**

| Shift Type | Time In Range | Time Out Range | Crosses Midnight |
|------------|---------------|----------------|------------------|
| morning_shift | 04:00-09:00 (4AM-9AM) | 12:00-17:00 (12PM-5PM) | No |
| afternoon_shift | 11:00-16:00 (11AM-4PM) | 19:00-00:00 (7PM-12AM) | No |
| night_shift | 18:00-23:00 (6PM-11PM) | 04:00-10:00 (4AM-10AM) | Yes |
| graveyard_shift | 22:00-02:00 (10PM-2AM) | 05:00-11:00 (5AM-11AM) | Yes |
| utility_24h | Any | Any | Depends |

**Note:** Time ranges are recommendations for UI validation only. The attendance algorithm uses the actual `scheduled_time_in` and `scheduled_time_out` values stored in the database.

### attendances
Attendance records.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| employee_schedule_id | bigint | Foreign key to schedules |
| leave_request_id | bigint | Foreign key (nullable) |
| shift_date | date | Date of shift |
| scheduled_time_in | time | Scheduled start |
| scheduled_time_out | time | Scheduled end |
| actual_time_in | datetime | Actual clock in |
| actual_time_out | datetime | Actual clock out |
| bio_in_site_id | bigint | Site of clock in |
| bio_out_site_id | bigint | Site of clock out |
| status | enum | on_time, tardy, half_day_absence, advised_absence, ncns, undertime, failed_bio_in, failed_bio_out, present_no_bio, needs_manual_review, non_work_day, on_leave |
| secondary_status | varchar(255) | Additional status |
| tardy_minutes | integer | Minutes late |
| undertime_minutes | integer | Minutes early out |
| overtime_minutes | integer | Overtime minutes |
| overtime_approved | boolean | OT approved status (default: false) |
| overtime_approved_at | timestamp | OT approval timestamp (nullable) |
| overtime_approved_by | bigint | User who approved OT (nullable, foreign key) |
| is_advised | boolean | Advised absence (default: false) |
| admin_verified | boolean | Admin verification |
| is_cross_site_bio | boolean | Different sites |
| verification_notes | text | Verification notes |
| notes | text | General notes |
| warnings | json | Warning flags |
| date_from | date | Range start date (nullable) |
| date_to | date | Range end date (nullable) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### attendance_uploads
Biometric file uploads.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| filename | varchar(255) | Original filename |
| site_id | bigint | Upload site |
| shift_date | date | Shift date |
| uploaded_by | bigint | User who uploaded |
| total_records | integer | Records in file |
| matched_employees | integer | Matched records |
| unmatched_employees | integer | Unmatched records |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### biometric_records
Raw biometric scans.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| attendance_upload_id | bigint | Foreign key |
| site_id | bigint | Site of scan |
| employee_name | varchar(255) | Name from device |
| datetime | datetime | Scan timestamp |
| record_date | date | Date portion |
| record_time | time | Time portion |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

**Indexes**: record_date, user_id

### attendance_points
Violation points.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| attendance_id | bigint | Foreign key (nullable) |
| shift_date | date | Date of shift |
| point_type | enum | whole_day_absence, half_day_absence, undertime, undertime_more_than_hour, tardy |
| points | decimal(3,2) | Point value (1.00, 0.50, 0.25) |
| status | varchar(255) | Attendance status reference (nullable) |
| is_advised | boolean | Advised absence (default: false) |
| notes | text | General notes (nullable) |
| is_excused | boolean | Excused status (default: false) |
| is_manual | boolean | Manual entry flag (default: false) |
| created_by | bigint | User who created (nullable, foreign key) |
| excused_by | bigint | Who excused (nullable, foreign key) |
| excused_at | timestamp | Excusal timestamp (nullable) |
| excuse_reason | text | Reason for excuse (nullable) |
| expires_at | date | Expiration date (nullable, indexed) |
| expiration_type | enum | sro, gbro, none (default: sro) |
| is_expired | boolean | Expired status (default: false, indexed) |
| expired_at | date | Expiration date (nullable) |
| violation_details | text | Violation details (nullable) |
| tardy_minutes | integer | Minutes late (nullable) |
| undertime_minutes | integer | Minutes early (nullable) |
| eligible_for_gbro | boolean | GBRO eligibility (default: true) |
| gbro_applied_at | date | GBRO application date (nullable) |
| gbro_batch_id | varchar(255) | GBRO batch identifier (nullable, indexed) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

**Indexes**: (user_id, shift_date), point_type, expires_at, is_expired, gbro_batch_id

### biometric_retention_policies
Data retention rules.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | varchar(255) | Policy name |
| description | text | Policy description (nullable) |
| retention_months | integer | Months to retain (default: 3) |
| applies_to_type | varchar(255) | Policy scope (site, department, global) |
| applies_to_id | bigint | Site/department ID (nullable) |
| priority | integer | Policy priority (default: 0) |
| is_active | boolean | Policy active (default: true) |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

**Indexes**: (applies_to_type, applies_to_id), is_active

---

## 📋 Form Request Tables

### leave_credits
Leave balance tracking.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| credits_earned | decimal(8,2) | Monthly accrual |
| credits_used | decimal(8,2) | Credits used |
| credits_balance | decimal(8,2) | Current balance |
| year | year | Year for credits |
| month | tinyint | Month (1-12) |
| accrued_at | date | Accrual date |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

**Unique**: user_id, year, month

### leave_requests
Leave request submissions.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| leave_type | enum | VL, SL, BL, SPL, LOA, LDV, UPTO |
| start_date | date | Leave start date |
| end_date | date | Leave end date |
| days_requested | decimal(5,2) | Number of days |
| reason | text | Leave reason |
| campaign_department | varchar(255) | Department |
| medical_cert_submitted | boolean | Med cert flag |
| status | enum | pending, approved, denied, cancelled |
| reviewed_by | bigint | Reviewer ID |
| reviewed_at | timestamp | Review timestamp |
| review_notes | text | Review notes |
| credits_deducted | decimal(5,2) | Credits used |
| credits_year | year | Year for credits |
| attendance_points_at_request | decimal(5,2) | Points at request |
| auto_rejected | boolean | Auto rejection flag |
| auto_rejection_reason | text | Rejection reason |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### it_concerns
IT issue tracking.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| site_id | bigint | Foreign key to sites |
| station_number | varchar(255) | Station reference |
| category | enum | Issue category |
| description | text | Issue description |
| status | enum | pending, in_progress, resolved, cancelled |
| priority | enum | low, medium, high, urgent |
| resolution_notes | text | Resolution notes |
| resolved_at | timestamp | Resolution timestamp |
| resolved_by | bigint | Resolver ID |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### medication_requests
Medication request tracking.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| name | varchar(255) | Medication name |
| medication_type | enum | Medication type |
| reason | text | Request reason |
| onset_of_symptoms | varchar(255) | Symptom start |
| agrees_to_policy | boolean | Policy agreement |
| status | enum | pending, approved, dispensed, rejected |
| approved_by | bigint | Approver ID |
| approved_at | timestamp | Approval timestamp |
| admin_notes | text | Admin notes |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### form_request_retention_policies
Form data retention rules.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| request_type | enum | leave_request, it_concern, medication_request |
| retention_months | integer | Months to retain |
| is_active | boolean | Policy active |
| description | text | Policy description |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

---

## 🔔 System Tables

### notifications
User notifications.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| type | varchar(255) | Notification type |
| title | varchar(255) | Notification title |
| message | text | Notification message |
| data | json | Additional data |
| read_at | timestamp | Read timestamp |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### activity_log
Spatie Activity Log table.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| log_name | varchar(255) | Log name |
| description | text | Activity description |
| subject_type | varchar(255) | Model class |
| subject_id | bigint | Model ID |
| event | varchar(255) | Event type |
| causer_type | varchar(255) | User class |
| causer_id | bigint | User ID |
| properties | json | Changed properties |
| batch_uuid | uuid | Batch identifier |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

---

## 🔧 System Tables

### cache
Laravel cache table.

### jobs / job_batches / failed_jobs
Laravel queue tables.

### sessions
User sessions (if using database driver).

### password_reset_tokens
Password reset tokens.

---

## 📈 Migration Timeline

| Date | Migration | Description |
|------|-----------|-------------|
| 2025-08-26 | Two Factor Auth | Added 2FA columns to users |
| 2025-09-30 | RAM Specs | Created ram_specs table |
| 2025-10-02 | Disk Specs | Created disk_specs table |
| 2025-10-03 | PC Specs | Created pc_specs and pivot tables |
| 2025-10-06 | Stocks | Created stocks table |
| 2025-10-13 | Sites/Campaigns | Created sites, campaigns, stations |
| 2025-10-20 | PC Transfers | Created pc_transfers table |
| 2025-10-22 | Maintenance | Created pc_maintenances table |
| 2025-10-24 | Monitors | Created monitor_specs and pivot tables |
| 2025-11-07 | Leave/Attendance | Created leave, schedules, attendance tables |
| 2025-11-10 | Biometric | Created biometric_records table |
| 2025-11-12 | Points | Created attendance_points table |
| 2025-11-15 | Leave Credits | Created leave_credits table |
| 2025-11-22 | IT Concerns | Created it_concerns table |
| 2025-11-24 | Medication | Created medication_requests table |
| 2025-11-25 | Notifications | Created notifications table |
| 2025-11-27 | Activity Log | Created activity_log table |

---

*Last updated: December 15, 2025*
