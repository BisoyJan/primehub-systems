# Computer & Hardware System Implementation Summary

## Overview

A comprehensive computer and hardware management system for tracking PC specifications, hardware inventory, QR code generation, maintenance scheduling, and PC transfers between stations.

## What Was Implemented

### Backend (Laravel)

1. **Database Migrations**
   - `pc_specs` - Main PC specification records
   - `ram_specs` - RAM module specifications
   - `disk_specs` - Storage drive specifications
   - `processor_specs` - CPU specifications
   - `monitor_specs` - Monitor specifications
   - `stocks` - Polymorphic stock inventory
   - `pc_transfers` - Transfer history records
   - `pc_maintenance` - Maintenance scheduling
   - Pivot tables for many-to-many relationships

2. **Models**
   - `PcSpec.php` - PC with hardware relationships
   - `RamSpec.php` - RAM specifications
   - `DiskSpec.php` - Disk specifications
   - `ProcessorSpec.php` - Processor specifications
   - `MonitorSpec.php` - Monitor specifications
   - `Stock.php` - Polymorphic stock tracking
   - `PcTransfer.php` - Transfer records
   - `PcMaintenance.php` - Maintenance records

3. **Controllers**
   - `PcSpecController.php` - PC CRUD and QR generation
   - `RamSpecsController.php` - RAM management
   - `DiskSpecsController.php` - Disk management
   - `ProcessorSpecsController.php` - Processor management
   - `MonitorSpecsController.php` - Monitor management
   - `StockController.php` - Stock inventory
   - `PcTransferController.php` - PC transfers
   - `PcMaintenanceController.php` - Maintenance scheduling

4. **Background Jobs**
   - `GenerateAllPcSpecQRCodesZip.php` - Bulk QR generation
   - `GenerateSelectedPcSpecQRCodesZip.php` - Selected PCs QR

### Frontend (React + TypeScript)

1. **PC Specs Pages** (`resources/js/pages/Computer/PcSpecs/`)
   - `Index.tsx` - PC list with search/filter
   - `Create.tsx` - Add new PC
   - `Edit.tsx` - Modify PC specs
   - `Show.tsx` - PC details and QR code

2. **Hardware Specs Pages**
   - `RamSpecs/` - RAM inventory
   - `DiskSpecs/` - Disk inventory
   - `ProcessorSpecs/` - Processor inventory
   - `MonitorSpecs/` - Monitor inventory
   - `Stocks/` - Stock management

3. **Station Integration Pages**
   - `Station/PcTransfer/` - Transfer management
   - `Station/PcMaintenance/` - Maintenance scheduling

## Key Features

### 1. PC Specification Management
- Detailed PC info (manufacturer, model, form factor)
- Memory configuration (type, slots, max capacity)
- Storage configuration (M.2 slots, SATA ports)
- Issue tracking with free-text field
- Station assignment tracking

### 2. Hardware Component System

| Component | Key Fields |
|-----------|------------|
| RAM | Model, Capacity (GB), Speed, Type (DDR3/4/5) |
| Disk | Model, Capacity (GB), Type (SSD/HDD), Interface |
| Processor | Model, Cores, Threads, Base/Boost Clock |
| Monitor | Model, Size, Resolution, Panel Type |

### 3. Many-to-Many Relationships
- One PC can have multiple RAM sticks
- One PC can have multiple disks
- Quantity tracking on pivot tables
- Same component reusable across PCs

### 4. QR Code Generation
- Individual QR per PC
- Bulk ZIP download (all PCs)
- Selected PCs ZIP download
- Background job processing with progress tracking
- Download notification when ready

### 5. Stock Inventory
- Polymorphic relationship (works with any hardware type)
- Quantity tracking per item
- Stock adjustments with history
- Reservation support

### 6. PC Transfer System
- Station-to-station transfers
- Bulk transfer capability
- Complete transfer history
- User attribution (who transferred)
- Remove PC from station

### 7. Maintenance Scheduling
- Schedule next maintenance date
- Track maintenance status
- Pending, overdue, completed states
- Dashboard alerts for overdue
- Maintenance history

## Database Schema

```sql
-- PC Specifications
pc_specs (id, pc_number, manufacturer, model, form_factor, 
          memory_type, ram_slots, max_ram_capacity_gb, max_ram_speed,
          m2_slots, sata_ports, issue, timestamps)

-- Hardware Specs
ram_specs (id, model, capacity_gb, speed, type, timestamps)
disk_specs (id, model, capacity_gb, drive_type, interface, timestamps)
processor_specs (id, model, cores, threads, base_clock, boost_clock, timestamps)
monitor_specs (id, model, size, resolution, panel_type, timestamps)

-- Pivot Tables
pc_spec_ram_spec (pc_spec_id, ram_spec_id, quantity, timestamps)
pc_spec_disk_spec (pc_spec_id, disk_spec_id)
pc_spec_processor_spec (pc_spec_id, processor_spec_id)
monitor_pc_spec (pc_spec_id, monitor_spec_id, quantity, timestamps)

-- Stock
stocks (id, stockable_type, stockable_id, quantity, reserved, timestamps)

-- Transfers & Maintenance
pc_transfers (id, pc_spec_id, from_station_id, to_station_id, 
              transferred_by, transferred_at, remarks, timestamps)
pc_maintenance (id, station_id, pc_spec_id, maintenance_type,
                description, next_due_date, completed_at, status, timestamps)
```

## Routes

```
# PC Specs
GET/POST   /pcspecs                 - List/Create
GET        /pcspecs/{id}            - View details
PUT        /pcspecs/{id}            - Update
DELETE     /pcspecs/{id}            - Delete
PATCH      /pcspecs/{id}/issue      - Update issue

# QR Codes
POST       /pcspecs/qrcode/zip-selected     - Generate selected ZIP
POST       /pcspecs/qrcode/bulk-all         - Generate all ZIP
GET        /pcspecs/qrcode/bulk-progress/*  - Check progress
GET        /pcspecs/qrcode/zip/*/download   - Download ZIP

# Hardware Specs (same pattern for each)
GET/POST   /ramspecs, /diskspecs, /processorspecs, /monitorspecs
PUT/DELETE /ramspecs/{id}, etc.

# Stock
GET/POST   /stocks                  - List/Create
PUT        /stocks/{id}             - Update
DELETE     /stocks/{id}             - Delete
POST       /stocks/adjust           - Adjust quantities

# Transfers
GET        /pc-transfers            - List transfers
POST       /pc-transfers            - Execute transfer
POST       /pc-transfers/bulk       - Bulk transfer
DELETE     /pc-transfers/remove     - Remove from station
GET        /pc-transfers/history    - Transfer history

# Maintenance
GET/POST   /pc-maintenance          - List/Create
PUT        /pc-maintenance/{id}     - Update
DELETE     /pc-maintenance/{id}     - Delete
```

## Permissions

| Category | Permissions |
|----------|-------------|
| Hardware | `hardware.{view,create,edit,delete}` |
| PC Specs | `pcspecs.{view,create,edit,delete,qrcode,update_issue}` |
| Stock | `stocks.{view,create,edit,delete,adjust}` |
| Transfers | `pc_transfers.{view,create,remove,history}` |
| Maintenance | `pc_maintenance.{view,create,edit,delete}` |

## How It Works

### 1. Adding a New PC

```
Create PC Spec → Add basic info (number, manufacturer, model)
→ Configure memory (type, slots, max capacity)
→ Configure storage (M.2 slots, SATA ports)
→ Attach hardware components (RAM, disk, processor, monitor)
→ Generate QR code
→ Assign to station (optional)
```

### 2. QR Code Generation Flow

```
User selects PCs → Request ZIP generation
→ Job queued → Background processing
→ Generate QR for each PC → Create ZIP
→ Store temporarily → Notify user
→ User downloads ZIP → Cleanup after 24h
```

### 3. PC Transfer Flow

```
Select PC → Choose destination station
→ Record transfer (who, when, from, to)
→ Update PC's station assignment
→ Log to activity trail
```

### 4. Maintenance Tracking

```
Create maintenance record → Set next due date
→ System monitors dates → Overdue alerts on dashboard
→ Mark completed → Schedule next maintenance
```

## Files Reference

### Backend
```
app/
├── Models/
│   ├── PcSpec.php
│   ├── RamSpec.php
│   ├── DiskSpec.php
│   ├── ProcessorSpec.php
│   ├── MonitorSpec.php
│   ├── Stock.php
│   ├── PcTransfer.php
│   └── PcMaintenance.php
├── Http/Controllers/
│   ├── PcSpecController.php
│   ├── RamSpecsController.php
│   ├── DiskSpecsController.php
│   ├── ProcessorSpecsController.php
│   ├── MonitorSpecsController.php
│   ├── StockController.php
│   ├── PcTransferController.php
│   └── PcMaintenanceController.php
└── Jobs/
    ├── GenerateAllPcSpecQRCodesZip.php
    └── GenerateSelectedPcSpecQRCodesZip.php
```

### Frontend
```
resources/js/pages/
├── Computer/
│   ├── PcSpecs/
│   │   ├── Index.tsx
│   │   ├── Create.tsx
│   │   ├── Edit.tsx
│   │   └── Show.tsx
│   ├── RamSpecs/
│   ├── DiskSpecs/
│   ├── ProcessorSpecs/
│   ├── MonitorSpecs/
│   └── Stocks/
└── Station/
    ├── PcTransfer/
    └── PcMaintenance/
```

## Dashboard Integration

The main dashboard displays:
- **Unassigned PCs** - PCs without station assignment
- **SSD vs HDD** - Storage type breakdown
- **Maintenance Due** - Overdue maintenance alerts
- **PCs with Issues** - Count of PCs with issues logged

## Related Documentation

- [Station Management](../stations/README.md) - Station and site docs
- [Database Schema](../database/SCHEMA.md) - Complete schema reference
- [API Routes](../api/ROUTES.md) - All available routes

---

**Implementation Date:** November 2025  
**Status:** ✅ Complete and Production Ready
