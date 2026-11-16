# PrimeHub Systems - Documentation

Welcome to the PrimeHub Systems comprehensive documentation! This directory contains complete guides for all major features, setup procedures, and technical references.

---

## 📂 Documentation Structure

### 🖥️ **Computer & Hardware** (`computer/`) 
PC specifications, hardware inventory, QR codes, and asset management.

- **[README.md](computer/README.md)** - Computer & hardware system overview
- **[QUICKSTART.md](computer/QUICKSTART.md)** ⭐ - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](computer/IMPLEMENTATION_SUMMARY.md)** ⭐ - Technical overview
  - PC specifications management
  - Hardware specs (RAM, Disk, Processor, Monitor)
  - QR code generation
  - Stock inventory
  - PC transfers and maintenance

### 🏢 **Stations & Sites** (`stations/`) 
Workstation management, physical locations, and campaigns.

- **[README.md](stations/README.md)** - Station management overview
- **[QUICKSTART.md](stations/QUICKSTART.md)** ⭐ - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](stations/IMPLEMENTATION_SUMMARY.md)** ⭐ - Technical overview
  - Station CRUD operations
  - Site and campaign management
  - QR code generation
  - Bulk station creation

### 📋 **Attendance System** (`attendance/`)
Complete documentation for the attendance tracking system including biometric file processing, shift detection, point expiration, and employee matching.

- **[README.md](attendance/README.md)** - Attendance system overview
- **[QUICKSTART.md](attendance/QUICKSTART.md)** ⭐ - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](attendance/IMPLEMENTATION_SUMMARY.md)** ⭐ - Technical overview
- **[EXPIRATION_SYSTEM_SUMMARY.md](attendance/EXPIRATION_SYSTEM_SUMMARY.md)** - Point expiration system (SRO/GBRO)
- **[POINT_EXPIRATION_RULES.md](attendance/POINT_EXPIRATION_RULES.md)** - Complete expiration rules
- **[AUTOMATIC_POINT_GENERATION.md](attendance/AUTOMATIC_POINT_GENERATION.md)** - Automatic point generation
- **[ATTENDANCE_GROUPING_LOGIC.md](attendance/ATTENDANCE_GROUPING_LOGIC.md)** - Universal shift detection (48 patterns)
- **[CROSS_UPLOAD_TIMEOUT_HANDLING.md](attendance/CROSS_UPLOAD_TIMEOUT_HANDLING.md)** - Multi-upload handling

### 🔐 **Biometric Records** (`biometric/`)
Documentation for biometric record storage, audit trails, and management features.

- **[README.md](biometric/README.md)** - Biometric system overview
- **[QUICKSTART.md](biometric/QUICKSTART.md)** ⭐ - Get started quickly
- **[BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md](biometric/BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)** - Implementation overview
- **[BIOMETRIC_RECORDS_STORAGE.md](biometric/BIOMETRIC_RECORDS_STORAGE.md)** - Database schema and lifecycle
- **[BIOMETRIC_RECORDS_UI.md](biometric/BIOMETRIC_RECORDS_UI.md)** - UI features and components

### 🏖️ **Leave Management** (`leave/`)
Complete documentation for the employee leave management system.

- **[README.md](leave/README.md)** - Complete leave system documentation
- **[QUICKSTART.md](leave/QUICKSTART.md)** ⭐ - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](leave/IMPLEMENTATION_SUMMARY.md)** ⭐ - Technical overview
- **[LEAVE_CREDITS_ACCRUAL.md](leave/LEAVE_CREDITS_ACCRUAL.md)** - Monthly accrual system
- **[LEAVE_REQUEST_VALIDATION.md](leave/LEAVE_REQUEST_VALIDATION.md)** - Validation rules
- **[LEAVE_APPROVAL_WORKFLOW.md](leave/LEAVE_APPROVAL_WORKFLOW.md)** - Approval process

### 📝 **Form Requests** (`form-requests/`) 
Employee form request systems documentation.

- **[README.md](form-requests/README.md)** - Form requests overview
- **[QUICKSTART.md](form-requests/QUICKSTART.md)** ⭐ - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](form-requests/IMPLEMENTATION_SUMMARY.md)** ⭐ - Technical overview
  - IT Concerns tracking
  - Medication requests
  - Retention policies

### 👤 **Accounts & Activity** (`accounts/`) 
User management and activity logging.

- **[README.md](accounts/README.md)** - Account management overview
- **[QUICKSTART.md](accounts/QUICKSTART.md)** ⭐ - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](accounts/IMPLEMENTATION_SUMMARY.md)** ⭐ - Technical overview
  - User CRUD operations
  - Role assignment
  - Approval workflow
  - Activity logging

### 🔒 **Authorization** (`authorization/`)
Role-Based Access Control (RBAC) system documentation.

- **[README.md](authorization/README.md)** - RBAC overview
- **[QUICKSTART.md](authorization/QUICKSTART.md)** ⭐ - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](authorization/IMPLEMENTATION_SUMMARY.md)** - Technical overview
- **[RBAC_GUIDE.md](authorization/RBAC_GUIDE.md)** - Complete guide
- **[QUICK_REFERENCE.md](authorization/QUICK_REFERENCE.md)** - Quick reference
- **[ROLE_ACCESS_MATRIX.md](authorization/ROLE_ACCESS_MATRIX.md)** - Permission matrix

### 🔔 **Notifications** (`notification/`)
Notification system documentation.

- **[NOTIFICATION_SYSTEM.md](notification/NOTIFICATION_SYSTEM.md)** - Complete notification system
- **[NOTIFICATION_QUICKSTART.md](notification/NOTIFICATION_QUICKSTART.md)** - Quick start guide
- **[NOTIFICATION_IMPLEMENTATION_SUMMARY.md](notification/NOTIFICATION_IMPLEMENTATION_SUMMARY.md)** - Technical overview

### 📊 **Database** (`database/`) 
Database schema and architecture.

- **[SCHEMA.md](database/SCHEMA.md)** - Complete database schema reference

### 🌐 **API Reference** (`api/`)
Routes and API documentation.

- **[ROUTES.md](api/ROUTES.md)** - Complete routes reference

### 📱 **QR Code System** (`QR/`)
QR code generation and management documentation.

- **[IMPLEMENTATION_SUMMARY.md](QR/IMPLEMENTATION_SUMMARY.md)** - QR code technical overview

### ⚙️ **Setup & Configuration** (`setup/`)
Technical setup guides for server configuration.

- **[README.md](setup/README.md)** - Setup overview
- **[PHP_EXTENSIONS_SETUP.md](setup/PHP_EXTENSIONS_SETUP.md)** - PHP extensions
- **[QR_CODE_ZIP_GENERATION_SETUP_GUIDE.MD](setup/QR_CODE_ZIP_GENERATION_SETUP_GUIDE.MD)** - QR code setup

### 🚀 **Deployment Guides** (`guides/`)
Environment setup and deployment.

- **[README.md](guides/README.md)** - Guides overview
- **[LOCAL_SETUP_GUIDE.md](guides/LOCAL_SETUP_GUIDE.md)** - Local development
- **[NGROK_GUIDE.md](guides/NGROK_GUIDE.md)** - Remote access
- **[DIGITALOCEAN_DEPLOYMENT.md](guides/DIGITALOCEAN_DEPLOYMENT.md)** - Cloud deployment

---

## 🎯 Quick Navigation

### For New Developers
1. Start with **[guides/README.md](guides/README.md)** - Choose setup method
2. Review **[../REFACTORING_GUIDE.md](../REFACTORING_GUIDE.md)** - Code standards
3. Check **[../.github/copilot-instructions.md](../.github/copilot-instructions.md)** - Project architecture
4. Read **[database/SCHEMA.md](database/SCHEMA.md)** - Understand data model
5. Review **[api/ROUTES.md](api/ROUTES.md)** - API reference

### By Feature Area

#### IT Department
- **[computer/README.md](computer/README.md)** - PC & hardware management
- **[stations/README.md](stations/README.md)** - Station management
- **[form-requests/README.md](form-requests/README.md)** - IT concerns

#### HR Department
- **[attendance/README.md](attendance/README.md)** - Attendance system
- **[leave/README.md](leave/README.md)** - Leave management
- **[accounts/README.md](accounts/README.md)** - User management
- **[biometric/README.md](biometric/README.md)** - Biometric records

#### System Administration
- **[authorization/README.md](authorization/README.md)** - RBAC system
- **[NOTIFICATION_SYSTEM.md](NOTIFICATION_SYSTEM.md)** - Notifications
- **[database/SCHEMA.md](database/SCHEMA.md)** - Database reference
- **[api/ROUTES.md](api/ROUTES.md)** - API routes

### For Attendance Feature
1. **[attendance/README.md](attendance/README.md)** - System overview
2. **[attendance/EXPIRATION_SYSTEM_SUMMARY.md](attendance/EXPIRATION_SYSTEM_SUMMARY.md)** - Point expiration
3. **[attendance/ATTENDANCE_GROUPING_LOGIC.md](attendance/ATTENDANCE_GROUPING_LOGIC.md)** - Algorithm deep dive

### For Biometric Features
1. **[biometric/BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md](biometric/BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)** - Feature overview
2. **[biometric/BIOMETRIC_RECORDS_STORAGE.md](biometric/BIOMETRIC_RECORDS_STORAGE.md)** - Data management
3. **[biometric/BIOMETRIC_RECORDS_UI.md](biometric/BIOMETRIC_RECORDS_UI.md)** - UI components
4. **[BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)** - Recent enhancements (in root)
5. **[BIOMETRIC_ENHANCEMENTS_STATUS.md](../BIOMETRIC_ENHANCEMENTS_STATUS.md)** - Implementation status (in root)

### For Leave Management
1. **[leave/README.md](leave/README.md)** - Complete system overview
2. Database schema (leave_credits, leave_requests tables)
3. Business rules and validation logic
4. Console commands (accrual + backfilling)

### For Setup & Deployment
1. **[guides/README.md](guides/README.md)** - Start here
2. **[guides/LOCAL_SETUP_GUIDE.md](guides/LOCAL_SETUP_GUIDE.md)** - For local development
3. **[setup/PHP_EXTENSIONS_SETUP.md](setup/PHP_EXTENSIONS_SETUP.md)** - Production server setup

---

## 📚 Documentation Types

### 🔍 **Algorithm & Logic**
Deep dives into business logic and algorithms:
- Attendance grouping (48 shift patterns)
- Employee name matching
- Status determination
- Cross-site detection

### 🏗️ **Architecture & Implementation**
Technical implementation details:
- Database schemas
- Service classes
- Controller patterns
- Job queues

### 🎨 **UI & User Experience**
Frontend documentation:
- React components
- Page layouts
- User workflows
- Filter systems

### ⚙️ **Setup & Configuration**
Server and environment setup:
- PHP extensions
- Redis configuration
- Queue workers
- Scheduled tasks

### 🧪 **Testing**
Test coverage and quality assurance:
- Unit tests (72 tests)
- Feature tests
- Factory patterns
- Test execution

---

## 🔗 Related Documentation (Root Level)

These important docs are in the project root directory:

### Attendance System
- **[ATTENDANCE_FEATURES_SUMMARY.md](../ATTENDANCE_FEATURES_SUMMARY.md)** - Quick reference for all attendance features
- **[ATTENDANCE_SYSTEM_ANALYSIS.md](../ATTENDANCE_SYSTEM_ANALYSIS.md)** - Complete feature analysis (production ready)
- **[ATTENDANCE_TESTS_SUMMARY.md](../ATTENDANCE_TESTS_SUMMARY.md)** - 72 tests with 100% coverage

### Biometric Enhancements
- **[BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md](../BIOMETRIC_ENHANCEMENTS_IMPLEMENTATION.md)** - 4 new features (reprocessing, anomalies, export, retention)
- **[BIOMETRIC_ENHANCEMENTS_STATUS.md](../BIOMETRIC_ENHANCEMENTS_STATUS.md)** - Implementation checklist
- **[BIOMETRIC_UI_FIXES.md](../BIOMETRIC_UI_FIXES.md)** - UI troubleshooting guide

### Project Standards
- **[REFACTORING_GUIDE.md](../REFACTORING_GUIDE.md)** - Code quality standards
- **[.github/copilot-instructions.md](../.github/copilot-instructions.md)** - Project conventions and architecture

---

## 🗺️ Complete File Map

```
docs/
├── README.md                              ← You are here
│
├── accounts/                              ← User Account Management 
│   ├── README.md
│   ├── QUICKSTART.md                      
│   └── IMPLEMENTATION_SUMMARY.md          
│
├── api/                                   ← API & Routes Reference 
│   └── ROUTES.md
│
├── attendance/                            ← Attendance System
│   ├── README.md
│   ├── QUICKSTART.md                      
│   ├── IMPLEMENTATION_SUMMARY.md          
│   ├── ATTENDANCE_GROUPING_LOGIC.md
│   ├── AUTOMATIC_POINT_GENERATION.md
│   ├── CROSS_UPLOAD_TIMEOUT_HANDLING.md
│   ├── EXPIRATION_SYSTEM_SUMMARY.md
│   └── POINT_EXPIRATION_RULES.md
│
├── authorization/                         ← RBAC System
│   ├── README.md
│   ├── QUICKSTART.md                      
│   ├── IMPLEMENTATION_SUMMARY.md
│   ├── QUICK_REFERENCE.md
│   ├── RBAC_GUIDE.md
│   └── ROLE_ACCESS_MATRIX.md
│
├── biometric/                             ← Biometric Records
│   ├── README.md
│   ├── QUICKSTART.md                      
│   ├── BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md
│   ├── BIOMETRIC_RECORDS_STORAGE.md
│   └── BIOMETRIC_RECORDS_UI.md
│
├── computer/                              ← Computer & Hardware 
│   ├── README.md
│   ├── QUICKSTART.md                      
│   └── IMPLEMENTATION_SUMMARY.md          
│
├── database/                              ← Database Schema 
│   └── SCHEMA.md
│
├── form-requests/                         ← Form Requests 
│   ├── README.md
│   ├── QUICKSTART.md                      
│   └── IMPLEMENTATION_SUMMARY.md          
│
├── guides/                                ← Deployment & Setup
│   ├── README.md
│   ├── DIGITALOCEAN_APP_PLATFORM_SETUP.md
│   ├── DIGITALOCEAN_DEPLOYMENT.md
│   ├── inactivity-logout.md
│   ├── LOCAL_SETUP_GUIDE.md
│   ├── NGROK_GUIDE.md
│   └── NGROK_SETUP.md
│
├── leave/                                 ← Leave Management
│   ├── README.md
│   ├── QUICKSTART.md                      
│   ├── IMPLEMENTATION_SUMMARY.md          
│   ├── LEAVE_APPROVAL_WORKFLOW.md
│   ├── LEAVE_CREDITS_ACCRUAL.md
│   └── LEAVE_REQUEST_VALIDATION.md
│
├── setup/                                 ← Server Setup
│   ├── README.md
│   ├── PHP_EXTENSIONS_SETUP.md
│   └── QR_CODE_ZIP_GENERATION_SETUP_GUIDE.MD
│
├── notification/                          ← Notification System
│   ├── NOTIFICATION_SYSTEM.md
│   ├── NOTIFICATION_QUICKSTART.md
│   └── NOTIFICATION_IMPLEMENTATION_SUMMARY.md
│
├── QR/                                    ← QR Code Documentation
│
├── setup/                                 ← Server Setup
│   ├── README.md
│   ├── PHP_EXTENSIONS_SETUP.md
│   └── QR_CODE_ZIP_GENERATION_SETUP_GUIDE.MD
│
└── stations/                              ← Station Management
    ├── README.md
    ├── QUICKSTART.md
    └── IMPLEMENTATION_SUMMARY.md
```

---

## 🎓 Learning Paths

### Path 1: New Developer Onboarding
1. Read **[guides/README.md](guides/README.md)** → Choose setup method
2. Review **[../.github/copilot-instructions.md](../.github/copilot-instructions.md)** → Understand architecture
3. Check **[../REFACTORING_GUIDE.md](../REFACTORING_GUIDE.md)** → Learn code standards
4. Study **[database/SCHEMA.md](database/SCHEMA.md)** → Understand data models
5. Browse **[api/ROUTES.md](api/ROUTES.md)** → API overview
6. Browse feature docs as needed

### Path 2: Understanding Attendance System
1. **[attendance/README.md](attendance/README.md)** → Quick overview (10 min read)
2. **[attendance/EXPIRATION_SYSTEM_SUMMARY.md](attendance/EXPIRATION_SYSTEM_SUMMARY.md)** → Point expiration overview (15 min)
3. **[attendance/ATTENDANCE_GROUPING_LOGIC.md](attendance/ATTENDANCE_GROUPING_LOGIC.md)** → Algorithm details (20 min)
4. **[attendance/AUTOMATIC_POINT_GENERATION.md](attendance/AUTOMATIC_POINT_GENERATION.md)** → Point rules
5. **[attendance/CROSS_UPLOAD_TIMEOUT_HANDLING.md](attendance/CROSS_UPLOAD_TIMEOUT_HANDLING.md)** → Edge cases

### Path 3: Working with Biometric Records
1. **[biometric/README.md](biometric/README.md)** → Overview
2. **[biometric/BIOMETRIC_RECORDS_STORAGE.md](biometric/BIOMETRIC_RECORDS_STORAGE.md)** → Data lifecycle
3. **[biometric/BIOMETRIC_RECORDS_UI.md](biometric/BIOMETRIC_RECORDS_UI.md)** → UI features
4. **[biometric/BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md](biometric/BIOMETRIC_RECORDS_IMPLEMENTATION_SUMMARY.md)** → Implementation details

### Path 4: Understanding Leave Management
1. **[leave/README.md](leave/README.md)** → Complete system overview (30 min)
   - Leave types and credits accrual
   - Business rules and validations
   - Request workflow and approval process
   - Console commands and setup

### Path 5: IT Department - Computer & Hardware 
1. **[computer/README.md](computer/README.md)** → Complete hardware system
   - PC spec management and tracking
   - Hardware components (RAM, Disk, Processor, Monitor)
   - QR code generation for assets
   - PC maintenance scheduling
   - PC transfers between stations
2. **[stations/README.md](stations/README.md)** → Station management
   - Station, site, and campaign hierarchy

### Path 6: HR Department - Form Requests 
1. **[form-requests/README.md](form-requests/README.md)** → Form request system
   - IT concerns workflow
   - Medication requests
   - Retention policies
2. **[accounts/README.md](accounts/README.md)** → User management
   - Account creation and management
   - Activity logging and audit trail

### Path 7: Understanding Authorization 
1. **[authorization/README.md](authorization/README.md)** → RBAC overview
2. **[authorization/RBAC_GUIDE.md](authorization/RBAC_GUIDE.md)** → Implementation guide
3. **[authorization/ROLE_ACCESS_MATRIX.md](authorization/ROLE_ACCESS_MATRIX.md)** → Permission matrix
4. **[authorization/QUICK_REFERENCE.md](authorization/QUICK_REFERENCE.md)** → Quick lookup

### Path 8: Production Deployment
1. **[setup/PHP_EXTENSIONS_SETUP.md](setup/PHP_EXTENSIONS_SETUP.md)** → Server requirements
2. **[guides/LOCAL_SETUP_GUIDE.md](guides/LOCAL_SETUP_GUIDE.md)** → Environment setup
3. **[guides/DIGITALOCEAN_DEPLOYMENT.md](guides/DIGITALOCEAN_DEPLOYMENT.md)** → Cloud deployment
4. **[guides/NGROK_GUIDE.md](guides/NGROK_GUIDE.md)** → Remote access (optional)

---

## 🔧 Common Tasks

### Running Tests
```bash
# All tests
php artisan test

# Specific feature tests
php artisan test --filter=Attendance
php artisan test --filter=LeaveRequest
php artisan test --filter=Policy
```

### Processing Attendance Upload
1. Navigate to `/attendance/import`
2. Upload biometric TXT file
3. Select shift date and site
4. Review results
   
See: [attendance/README.md](attendance/README.md)

### Cleaning Old Biometric Records
```bash
# Manual cleanup (3 months default)
php artisan biometric:clean-old-records

# Custom retention period
php artisan biometric:clean-old-records --months=6
```
See: [biometric/BIOMETRIC_RECORDS_STORAGE.md](biometric/BIOMETRIC_RECORDS_STORAGE.md)

### Managing Leave Credits
```bash
# Accrue credits for current month (runs monthly via cron)
php artisan leave:accrue-credits

# Backfill credits for all employees
php artisan leave:backfill-credits

# Backfill for specific employee
php artisan leave:backfill-credits --user=123
```
See: [leave/README.md](leave/README.md)

### Managing PC Specs & Hardware 
1. Navigate to `/computer/pc-specs` → Manage PC specifications
2. Navigate to `/computer/ram-specs` → Manage RAM inventory
3. Navigate to `/computer/disk-specs` → Manage disk inventory
4. Navigate to `/computer/stock` → Track available stock
5. Use QR codes for quick asset identification

See: [computer/README.md](computer/README.md)

### Managing Stations 
1. Navigate to `/sites` → Manage sites
2. Navigate to `/campaigns` → Manage campaigns
3. Navigate to `/stations` → Manage stations

See: [stations/README.md](stations/README.md)

### Managing User Accounts 
1. Navigate to `/accounts` → View all users
2. Navigate to `/accounts/create` → Create new user
3. Navigate to `/admin/activity-log` → View audit trail

See: [accounts/README.md](accounts/README.md)

---

## 📊 Key Metrics & Statistics

### System Overview
- **Total Models:** 24 Eloquent models
- **Total Controllers:** 28+ controllers
- **User Roles:** 7 (super_admin, admin, team_lead, agent, hr, it, utility)
- **Permissions:** 60+ defined permissions
- **Database Tables:** 35+ tables

### Attendance System
- **Shift Patterns Supported:** 48 (universal algorithm)
- **Point Expiration:** SRO (6 mo/1 yr) + GBRO (60 days clean)
- **Automated Processing:** Daily at 3:00 AM
- **Employee Matching Accuracy:** 98.5%
- **Test Coverage:** 72 tests, 100% pass rate

### Biometric Records
- **Retention Period:** 3 months (90 days)
- **Cleanup Schedule:** Daily at 2:00 AM
- **Audit Trail:** Complete scan history preserved

### Leave Management System
- **Leave Types:** 7 (VL, SL, BL, SPL, LOA, LDV, UPTO)
- **Monthly Accrual:** 1.5 days (managers), 1.25 days (employees)
- **Eligibility:** 6 months from hire date
- **Accrual Schedule:** Hire day of each month through the regularization year, then last day of month (checked daily at 8:28 AM)
- **⚠️ Annual Reset:** Credits expire on Dec 31

### Computer & Hardware 
- **Hardware Types:** 5 (PC Specs, RAM, Disk, Processor, Monitor)
- **QR Generation:** Individual and bulk ZIP download
- **Maintenance Tracking:** Scheduled and reactive
- **Transfer System:** Full audit trail between stations

### Form Requests 
- **Request Types:** IT Concerns, Medication Requests
- **Retention Policies:** Configurable per request type
- **Workflow:** Submit → Review → Approve/Reject

---

## 🆘 Getting Help

### Documentation Issues
1. Check the appropriate subfolder (attendance, biometric, computer, stations, etc.)
2. Review the main **[api/ROUTES.md](api/ROUTES.md)** for endpoint details
3. Check **[database/SCHEMA.md](database/SCHEMA.md)** for data model questions
4. Search for keywords in all .md files

### Feature Questions by Department
| Department | Start Here |
|------------|------------|
| General | **[guides/README.md](guides/README.md)** |
| Attendance/HR | **[attendance/README.md](attendance/README.md)** |
| Biometric | **[biometric/README.md](biometric/README.md)** |
| Leave | **[leave/README.md](leave/README.md)** |
| IT | **[computer/README.md](computer/README.md)** |
| Operations | **[stations/README.md](stations/README.md)** |
| HR Forms | **[form-requests/README.md](form-requests/README.md)** |
| Admin | **[authorization/README.md](authorization/README.md)** |

### Technical Issues
- Setup problems: **[guides/](guides/)** folder
- API/Routes questions: **[api/ROUTES.md](api/ROUTES.md)**
- Database questions: **[database/SCHEMA.md](database/SCHEMA.md)**
- Permission issues: **[authorization/](authorization/)** folder

---

## 📝 Documentation Standards

When adding new documentation:

1. **Choose the right location:**
   - Feature documentation → Appropriate `docs/` subfolder
   - API documentation → `docs/api/`
   - Database changes → Update `docs/database/SCHEMA.md`
   - Setup guides → `docs/setup/` or `docs/guides/`

2. **Use clear naming:**
   - UPPERCASE_WITH_UNDERSCORES for main docs
   - Descriptive names (ATTENDANCE_*, BIOMETRIC_*, etc.)
   - README.md for folder index files

3. **Include in this README:**
   - Add to appropriate section
   - Update file map
   - Add to learning paths if applicable
   - Update maintenance table

4. **Link related docs:**
   - Cross-reference other documentation
   - Use relative paths
   - Keep navigation easy

---

## 🔄 Documentation Maintenance

| Category | Last Updated | Status |
|----------|--------------|--------|
| Accounts & Activity | Dec 15, 2025 | ✅ Complete |
| API Routes Reference | Dec 15, 2025 | ✅ Complete |
| Attendance System | Dec 15, 2025 | ✅ Complete |
| Authorization/RBAC | Dec 15, 2025 | ✅ Complete |
| Biometric Records | Dec 15, 2025 | ✅ Complete |
| Computer & Hardware | Dec 15, 2025 | ✅ Complete |
| Database Schema | Dec 15, 2025 | ✅ Complete |
| Deployment Guides | Nov 1, 2025 | ✅ Complete |
| Form Requests | Dec 15, 2025 | ✅ Complete |
| Leave Management | Dec 15, 2025 | ✅ Complete |
| Notifications | Dec 15, 2025 | ✅ Complete |
| Point Expiration | Dec 15, 2025 | ✅ Complete |
| Setup Guides | Dec 15, 2025 | ✅ Complete |
| Stations & Sites | Dec 15, 2025 | ✅ Complete |
| Test Documentation | Nov 10, 2025 | ✅ Complete |

---

## 🎯 Next Steps

### For Developers
1. Complete environment setup using **[guides/](guides/)**
2. Review **[database/SCHEMA.md](database/SCHEMA.md)** for data models
3. Check **[api/ROUTES.md](api/ROUTES.md)** for available endpoints
4. Run tests to verify setup: `php artisan test`
5. Start coding!

### For DevOps
1. Review **[setup/PHP_EXTENSIONS_SETUP.md](setup/PHP_EXTENSIONS_SETUP.md)**
2. Configure production servers
3. Set up scheduled tasks (see cron jobs in each feature doc)
4. Enable monitoring and logging

### For IT Department
1. Read **[computer/README.md](computer/README.md)** for hardware management
2. Review **[stations/README.md](stations/README.md)** for station setup
3. Understand QR code generation workflow
4. Plan PC maintenance schedules

### For HR Department
1. Check **[leave/README.md](leave/README.md)** for leave management
2. Review **[form-requests/README.md](form-requests/README.md)** for form workflows
3. Understand **[accounts/README.md](accounts/README.md)** for user management
4. Review attendance documentation

### For Product Managers
1. Read feature documentation for business context
2. Review **[authorization/ROLE_ACCESS_MATRIX.md](authorization/ROLE_ACCESS_MATRIX.md)** for access control
3. Understand business metrics in each feature doc
4. Plan future enhancements

---

## 👨‍💻 Credits & Developer

**Developer:** Jan Ramil P. Intong (BisoyJan)  
**Email:** bisoyjan@gmail.com  
**GitHub:** [@BisoyJan](https://github.com/BisoyJan)  
**Role:** Developer

---

**Happy coding! 🚀**

For questions or documentation requests, please contact the development team.

*Last updated: December 15, 2025*
