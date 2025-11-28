# Station Management - Quick Start Guide

## 🚀 Getting Started

### Prerequisites
- Laravel application running
- Database migrated
- Redis configured (for job queues)

### 1. Create a Site

Navigate to `/sites/create`:

1. Enter site name (e.g., "Main Office")
2. Save

Sites represent physical locations where stations are grouped.

### 2. Create Campaigns

Navigate to `/campaigns/create`:

1. Enter campaign name (e.g., "Project Alpha")
2. Save

Campaigns represent projects or departments that use stations.

### 3. Create Stations

Navigate to `/stations/create`:

**Single Station:**
1. Select site
2. Enter station number
3. Select campaign
4. Select status (Active, Vacant, Maintenance)
5. Select monitor type (Single, Dual)
6. Save

**Bulk Creation:**
1. Click "Bulk Create"
2. Select site
3. Enter start number (e.g., 1)
4. Enter end number (e.g., 20)
5. Select campaign
6. Create all at once

### 4. Assign PC to Station

Use the PC Transfer system:

1. Navigate to `/pc-transfers/transfer`
2. Select PC
3. Select destination station
4. Execute transfer

### 5. Generate QR Codes

**Single Station:**
1. View station list
2. Click QR icon on station row

**Multiple Stations:**
1. Select stations with checkboxes
2. Click "Generate ZIP"
3. Wait for background job
4. Download when ready

**All Stations:**
1. Click "Download All QR Codes"
2. Wait for background job
3. Download ZIP

## 🔧 Common Tasks

### Search Stations

Navigate to `/stations`:
- Search by station number
- Filter by site
- Filter by campaign
- Filter by status

### Update Station Status

1. Click Edit on station
2. Change status:
   - **Active** - In use
   - **Vacant** - Available
   - **Maintenance** - Under repair
3. Save

### Print QR Codes

1. Generate QR codes (single or bulk)
2. Download ZIP file
3. Extract images
4. Print at appropriate size
5. Attach to physical stations

### Scan QR Code

When a QR code is scanned:
1. Opens `/stations/scan/{station}`
2. Shows station details:
   - Station number
   - Site name
   - Campaign
   - Assigned PC info
   - Status

## 📋 Quick Reference

### Key URLs

| Page | URL |
|------|-----|
| Sites List | `/sites` |
| Create Site | `/sites/create` |
| Campaigns List | `/campaigns` |
| Create Campaign | `/campaigns/create` |
| Stations List | `/stations` |
| Create Station | `/stations/create` |
| Bulk Create | `/stations` (button) |
| Scan Result | `/stations/scan/{id}` |

### Station Statuses

| Status | Color | Description |
|--------|-------|-------------|
| Active | Green | In use |
| Vacant | Yellow | Available |
| Maintenance | Red | Under repair |

### Monitor Types

| Type | Description |
|------|-------------|
| Single | One monitor setup |
| Dual | Two monitor setup |

### Hierarchy

```
Sites (Physical Locations)
└── Stations (Workstations)
    ├── Campaign (Project)
    ├── PC (Assigned computer)
    └── Monitors (Display setup)
```

## 🧪 Testing

### Verify Station Creation

```php
php artisan tinker

$station = \App\Models\Station::with(['site', 'campaign', 'pcSpec'])->first();
$station->site->name;      // Site name
$station->campaign->name;  // Campaign name
$station->pcSpec;          // Assigned PC (if any)
```

### Test QR Generation

```bash
# Start queue worker
php artisan queue:work

# Generate QR for all stations
# Use UI or dispatch job manually
```

### Manual Testing Checklist

- [ ] Create site → Shows in list
- [ ] Create campaign → Shows in list
- [ ] Create station → Assigned to site
- [ ] Bulk create → Multiple stations created
- [ ] Generate QR → Downloads correctly
- [ ] Scan QR → Shows station info
- [ ] Transfer PC → Station shows PC
- [ ] Change status → Updates correctly

## 🐛 Troubleshooting

### Station Not Showing Site

**Cause:** Site not selected during creation

**Fix:** Edit station and select site

### QR Code Not Generating

**Cause:** Queue worker not running

**Solution:**
```bash
php artisan queue:work
```

### Duplicate Station Number

**Cause:** Station numbers are unique per site

**Fix:** Use different number or different site

### Cannot Delete Site

**Cause:** Site has stations assigned

**Fix:** Delete or move stations first

### Bulk Create Not Working

**Cause:** Start number >= End number

**Fix:** Ensure start < end (e.g., 1-20)

## 📊 Dashboard Integration

The dashboard shows station metrics:

| Widget | Description |
|--------|-------------|
| Total Stations | Count per site |
| Vacant Stations | Available for use |
| Without PCs | Need assignment |
| Dual Monitor | Special setups |

## 🔗 Related Features

### PC Transfers
- Transfer PCs between stations
- Track history
- See: [Computer System](../computer/README.md)

### Maintenance
- Schedule per station
- Track status
- See: [Computer System](../computer/README.md)

### Attendance
- Stations linked to sites
- See: [Attendance System](../attendance/README.md)

---

**Need help?** Check the [full documentation](README.md) or [implementation details](IMPLEMENTATION_SUMMARY.md).
