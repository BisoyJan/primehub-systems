# Computer & Hardware Management System

Comprehensive documentation for PC specifications, hardware inventory, QR code generation, and related features.

---

## 🚀 Quick Links

- **[QUICKSTART.md](QUICKSTART.md)** - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - Technical overview

---

## 📄 Documents

### [PC_SPECS.md](PC_SPECS.md)
**PC Specifications Management**

Complete documentation for managing computer specifications with hardware components.

**Topics Covered:**
- ✅ PC specification CRUD operations
- ✅ Hardware component relationships (RAM, Disk, Processor, Monitor)
- ✅ QR code generation for assets
- ✅ Issue tracking and reporting
- ✅ Search and filtering

### [HARDWARE_SPECS.md](HARDWARE_SPECS.md)
**Hardware Components Documentation**

Documentation for managing individual hardware component specifications.

**Topics Covered:**
- ✅ RAM specifications (capacity, speed, type)
- ✅ Disk specifications (SSD/HDD, capacity)
- ✅ Processor specifications
- ✅ Monitor specifications
- ✅ Stock inventory integration

### [PC_TRANSFERS.md](PC_TRANSFERS.md)
**PC Transfer System**

Documentation for transferring PCs between stations.

**Topics Covered:**
- ✅ Transfer workflow
- ✅ Transfer history tracking
- ✅ Bulk transfers
- ✅ Removal from stations

### [PC_MAINTENANCE.md](PC_MAINTENANCE.md)
**PC Maintenance Tracking**

Documentation for scheduling and tracking PC maintenance.

**Topics Covered:**
- ✅ Maintenance scheduling
- ✅ Status tracking (pending, overdue, completed)
- ✅ Dashboard integration
- ✅ Notification system

### [STOCK_MANAGEMENT.md](STOCK_MANAGEMENT.md)
**Stock Inventory System**

Documentation for hardware stock management.

**Topics Covered:**
- ✅ Stock tracking for hardware components
- ✅ Stock adjustments
- ✅ Quantity and reservation tracking
- ✅ Polymorphic relationships

---

## 🏗️ Architecture

### Database Schema

```
pc_specs
├── id
├── pc_number (unique identifier)
├── manufacturer
├── model
├── form_factor
├── memory_type
├── ram_slots
├── max_ram_capacity_gb
├── max_ram_speed
├── m2_slots
├── sata_ports
├── issue (text, nullable)
└── timestamps

ram_specs
├── id
├── model
├── capacity_gb
├── speed
├── type (DDR3, DDR4, DDR5)
└── timestamps

disk_specs
├── id
├── model
├── capacity_gb
├── drive_type (SSD, HDD)
├── interface
└── timestamps

processor_specs
├── id
├── model
├── cores
├── threads
├── base_clock
├── boost_clock
└── timestamps

monitor_specs
├── id
├── model
├── size
├── resolution
├── panel_type
└── timestamps
```

### Pivot Tables

```
pc_spec_ram_spec
├── pc_spec_id
├── ram_spec_id
├── quantity
└── timestamps

pc_spec_disk_spec
├── pc_spec_id
└── disk_spec_id

pc_spec_processor_spec
├── pc_spec_id
└── processor_spec_id

monitor_pc_spec
├── pc_spec_id
├── monitor_spec_id
├── quantity
└── timestamps
```

---

## 🔗 Related Features

### Station Integration
- PCs can be assigned to stations
- One PC per station
- Transfer system for reassignment

### QR Code Generation
- Individual QR codes for each PC
- Bulk ZIP download for all PCs
- Selected PCs ZIP download
- Background job processing

### Dashboard Metrics
- Unassigned PCs count
- SSD vs HDD breakdown
- Maintenance due alerts
- PCs with issues

---

## 🎯 Key Routes

### PC Specs
```
GET    /pcspecs                    - List all PC specs
GET    /pcspecs/create             - Create form
POST   /pcspecs                    - Store new PC spec
GET    /pcspecs/{id}               - View PC spec
GET    /pcspecs/{id}/edit          - Edit form
PUT    /pcspecs/{id}               - Update PC spec
DELETE /pcspecs/{id}               - Delete PC spec
PATCH  /pcspecs/{id}/issue         - Update issue status
```

### QR Code Operations
```
POST   /pcspecs/qrcode/zip-selected           - Generate ZIP for selected PCs
POST   /pcspecs/qrcode/bulk-all               - Generate ZIP for all PCs
GET    /pcspecs/qrcode/bulk-progress/{jobId}  - Check bulk job progress
GET    /pcspecs/qrcode/zip/{jobId}/download   - Download generated ZIP
```

### Hardware Specs
```
# RAM Specs
GET/POST       /ramspecs              - List/Create
PUT/DELETE     /ramspecs/{id}         - Update/Delete

# Disk Specs
GET/POST       /diskspecs             - List/Create
PUT/DELETE     /diskspecs/{id}        - Update/Delete

# Processor Specs
GET/POST       /processorspecs        - List/Create
PUT/DELETE     /processorspecs/{id}   - Update/Delete

# Monitor Specs
GET/POST       /monitorspecs          - List/Create
PUT/DELETE     /monitorspecs/{id}     - Update/Delete
```

### Stock Management
```
GET    /stocks                     - List stock items
POST   /stocks                     - Create stock item
PUT    /stocks/{id}                - Update stock item
DELETE /stocks/{id}                - Delete stock item
POST   /stocks/adjust              - Adjust stock quantities
```

### PC Transfers
```
GET    /pc-transfers               - List transfers
GET    /pc-transfers/transfer/{station?} - Transfer page
POST   /pc-transfers               - Execute transfer
POST   /pc-transfers/bulk          - Bulk transfer
DELETE /pc-transfers/remove        - Remove PC from station
GET    /pc-transfers/history       - Transfer history
```

### PC Maintenance
```
GET    /pc-maintenance             - List maintenance records
POST   /pc-maintenance             - Create maintenance record
PUT    /pc-maintenance/{id}        - Update maintenance record
DELETE /pc-maintenance/{id}        - Delete maintenance record
```

---

## 🔒 Permissions

| Permission | Description |
|------------|-------------|
| `hardware.view` | View hardware specs (RAM, Disk, Processor, Monitor) |
| `hardware.create` | Create hardware specs |
| `hardware.edit` | Edit hardware specs |
| `hardware.delete` | Delete hardware specs |
| `pcspecs.view` | View PC specifications |
| `pcspecs.create` | Create PC specifications |
| `pcspecs.edit` | Edit PC specifications |
| `pcspecs.delete` | Delete PC specifications |
| `pcspecs.qrcode` | Generate PC QR codes |
| `pcspecs.update_issue` | Update PC issues |
| `stocks.view` | View stock inventory |
| `stocks.create` | Create stock items |
| `stocks.edit` | Edit stock items |
| `stocks.delete` | Delete stock items |
| `stocks.adjust` | Adjust stock quantities |
| `pc_transfers.view` | View PC transfers |
| `pc_transfers.create` | Transfer PCs |
| `pc_transfers.remove` | Remove PC from station |
| `pc_transfers.history` | View transfer history |
| `pc_maintenance.view` | View PC maintenance |
| `pc_maintenance.create` | Create maintenance records |
| `pc_maintenance.edit` | Edit maintenance records |
| `pc_maintenance.delete` | Delete maintenance records |

---

## 🎓 Key Files

### Models
- `app/Models/PcSpec.php` - PC specifications with hardware relationships
- `app/Models/RamSpec.php` - RAM specifications
- `app/Models/DiskSpec.php` - Disk specifications
- `app/Models/ProcessorSpec.php` - Processor specifications
- `app/Models/MonitorSpec.php` - Monitor specifications
- `app/Models/Stock.php` - Stock inventory (polymorphic)
- `app/Models/PcTransfer.php` - Transfer records
- `app/Models/PcMaintenance.php` - Maintenance records

### Controllers
- `app/Http/Controllers/PcSpecController.php`
- `app/Http/Controllers/RamSpecsController.php`
- `app/Http/Controllers/DiskSpecsController.php`
- `app/Http/Controllers/ProcessorSpecsController.php`
- `app/Http/Controllers/MonitorSpecsController.php`
- `app/Http/Controllers/StockController.php`
- `app/Http/Controllers/PcTransferController.php`
- `app/Http/Controllers/PcMaintenanceController.php`

### Jobs (Background Processing)
- `app/Jobs/GenerateAllPcSpecQRCodesZip.php`
- `app/Jobs/GenerateSelectedPcSpecQRCodesZip.php`

### Frontend Pages
- `resources/js/pages/Computer/PcSpecs/` - PC specs pages
- `resources/js/pages/Computer/RamSpecs/` - RAM specs pages
- `resources/js/pages/Computer/DiskSpecs/` - Disk specs pages
- `resources/js/pages/Computer/ProcessorSpecs/` - Processor specs pages
- `resources/js/pages/Computer/MonitorSpecs/` - Monitor specs pages
- `resources/js/pages/Computer/Stocks/` - Stock management pages
- `resources/js/pages/Station/PcMaintenance/` - Maintenance pages
- `resources/js/pages/Station/PcTransfer/` - Transfer pages

---

## 💡 Features

### PC Specifications
- **Many-to-Many Relationships**: A PC can have multiple RAM sticks, disks, etc.
- **Quantity Tracking**: Track how many of each component is installed
- **Issue Tracking**: Document PC issues with free-text field
- **QR Codes**: Generate QR codes for asset tracking

### Hardware Inventory
- **Separate Tables**: Each hardware type has its own table
- **Stock Integration**: Track available stock for each component
- **Reusability**: Same component can be associated with multiple PCs

### Maintenance System
- **Scheduling**: Set next due dates for maintenance
- **Status Tracking**: Pending, overdue, completed statuses
- **Dashboard Alerts**: Overdue maintenance appears on dashboard
- **History**: Track all maintenance activities

### Transfer System
- **Station-to-Station**: Transfer PCs between workstations
- **Bulk Operations**: Transfer multiple PCs at once
- **History Trail**: Complete transfer history with timestamps
- **User Attribution**: Track who performed each transfer

---

*Last updated: November 28, 2025*
