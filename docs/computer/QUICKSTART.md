# Computer & Hardware System - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Laravel application running
- Database migrated
- Redis configured (for job queues)
- Storage linked (`php artisan storage:link`)

### 1. Add a PC Specification

Navigate to `/pcspecs/create` or use the UI:

1. Enter PC Number (unique identifier)
2. Enter Manufacturer and Model
3. Select Form Factor (Desktop, Mini, AIO, Laptop)
4. Configure Memory (DDR type, slots, max capacity)
5. Configure Storage (M.2 slots, SATA ports)
6. Save

### 2. Add Hardware Components

Before attaching components to PCs, create them:

**RAM Specs** (`/ramspecs`):
- Model name
- Capacity (4GB, 8GB, 16GB, etc.)
- Speed (3200MHz, etc.)
- Type (DDR3, DDR4, DDR5)

**Disk Specs** (`/diskspecs`):
- Model name
- Capacity (256GB, 512GB, 1TB, etc.)
- Type (SSD or HDD)
- Interface (SATA, NVMe)

**Processor Specs** (`/processorspecs`):
- Model name
- Cores and Threads
- Base and Boost Clock speeds

**Monitor Specs** (`/monitorspecs`):
- Model name
- Size (24", 27", etc.)
- Resolution (1080p, 4K, etc.)
- Panel Type (IPS, VA, TN)

### 3. Attach Components to PC

1. Edit the PC specification
2. Select RAM modules (with quantity)
3. Select disks
4. Select processor
5. Select monitors (with quantity)
6. Save

### 4. Generate QR Codes

**Single PC:**
1. View PC details (`/pcspecs/{id}`)
2. Click "Generate QR Code"

**Multiple PCs:**
1. Go to PC list (`/pcspecs`)
2. Select PCs with checkboxes
3. Click "Generate ZIP"
4. Wait for background job
5. Download ZIP when ready

**All PCs:**
1. Click "Download All QR Codes"
2. Wait for background job
3. Download ZIP when ready

## 🔧 Common Tasks

### Track Stock Inventory

Navigate to `/stocks`:

```
1. Create stock item
2. Select hardware type (RAM, Disk, etc.)
3. Select specific item
4. Enter quantity available
5. Track as items are used
```

### Transfer PC to Another Station

Navigate to `/pc-transfers/transfer`:

```
1. Select PC to transfer
2. Select destination station
3. Add remarks (optional)
4. Execute transfer
5. History automatically logged
```

### Schedule PC Maintenance

Navigate to `/pc-maintenance`:

```
1. Create new maintenance record
2. Select station and PC
3. Set maintenance type (routine, repair, upgrade)
4. Set next due date
5. System will alert when overdue
```

### Log PC Issue

```
1. View PC details (/pcspecs/{id})
2. Click "Update Issue"
3. Enter issue description
4. Save
5. Issue appears in dashboard alerts
```

## 📋 Quick Reference

### Key URLs

| Page | URL |
|------|-----|
| PC Specs List | `/pcspecs` |
| Create PC | `/pcspecs/create` |
| RAM Specs | `/ramspecs` |
| Disk Specs | `/diskspecs` |
| Processor Specs | `/processorspecs` |
| Monitor Specs | `/monitorspecs` |
| Stock Inventory | `/stocks` |
| PC Transfers | `/pc-transfers` |
| PC Maintenance | `/pc-maintenance` |

### Hardware Types

| Type | Fields |
|------|--------|
| RAM | Model, Capacity (GB), Speed, Type |
| Disk | Model, Capacity (GB), Drive Type, Interface |
| Processor | Model, Cores, Threads, Base/Boost Clock |
| Monitor | Model, Size, Resolution, Panel Type |

### PC Form Factors

| Type | Description |
|------|-------------|
| Desktop | Standard tower PC |
| Mini | Small form factor |
| AIO | All-in-One |
| Laptop | Portable computer |

### Memory Types

| Type | Description |
|------|-------------|
| DDR3 | Older standard |
| DDR4 | Current standard |
| DDR5 | Latest standard |

## 🧪 Testing

### Verify QR Generation

```bash
# Check queue is running
php artisan queue:work

# Test job manually
php artisan tinker
dispatch(new \App\Jobs\GenerateAllPcSpecQRCodesZip($userId));
```

### Check PC Relationships

```php
php artisan tinker

$pc = \App\Models\PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs', 'monitorSpecs'])->first();
$pc->ramSpecs;      // Attached RAM
$pc->diskSpecs;     // Attached disks
$pc->station;       // Assigned station
```

### Manual Testing Checklist

- [ ] Create PC spec → Saved successfully
- [ ] Add RAM spec → Shows in list
- [ ] Attach RAM to PC → Relationship created
- [ ] Generate single QR → Downloads correctly
- [ ] Generate bulk QR → Job processes, ZIP downloads
- [ ] Transfer PC → History logged
- [ ] Schedule maintenance → Shows in dashboard
- [ ] Log issue → Alert appears

## 🐛 Troubleshooting

### QR Code Not Generating

**Cause:** Queue worker not running

**Solution:**
```bash
# Start queue worker
php artisan queue:work

# Or for production
php artisan queue:work --daemon
```

### Cannot Attach Hardware

**Cause:** Hardware spec not created first

**Solution:** Create the RAM/Disk/etc. spec before attaching to PC

### Transfer Not Working

**Cause:** PC already at destination station

**Check:**
```php
$pc = PcSpec::find($id);
echo $pc->station_id; // Current station
```

### Stock Not Updating

**Cause:** Wrong stockable type

**Solution:** Ensure polymorphic relationship is correct:
```php
// stockable_type should be full class name
// e.g., "App\Models\RamSpec"
```

## 📊 Dashboard Widgets

The main dashboard shows:

| Widget | Description |
|--------|-------------|
| Unassigned PCs | PCs without station |
| SSD vs HDD | Storage type breakdown |
| Maintenance Due | Overdue maintenance count |
| PCs with Issues | Count of logged issues |

## 🔗 Related Documentation

- [Station Management](../stations/README.md)
- [Implementation Summary](IMPLEMENTATION_SUMMARY.md)
- [Full README](README.md)
- [Database Schema](../database/SCHEMA.md)

---

**Need help?** Check the [full documentation](README.md) or [implementation details](IMPLEMENTATION_SUMMARY.md).
