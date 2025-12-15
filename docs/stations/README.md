# Station & Site Management System

Comprehensive documentation for stations, sites, campaigns, and workstation management.

---

## 🚀 Quick Links

- **[QUICKSTART.md](QUICKSTART.md)** - Get started quickly
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - Technical overview

---

## 📄 Documents

### [STATIONS.md](STATIONS.md)
**Station Management**

Complete documentation for managing workstations.

**Topics Covered:**
- ✅ Station CRUD operations
- ✅ Site and campaign associations
- ✅ PC assignment
- ✅ Monitor type tracking
- ✅ QR code generation
- ✅ Bulk station creation

### [SITES.md](SITES.md)
**Site Management**

Documentation for physical location management.

### [CAMPAIGNS.md](CAMPAIGNS.md)
**Campaign Management**

Documentation for campaign/project management.

---

## 🏗️ Architecture

### Database Schema

```
sites
├── id
├── name (unique)
└── timestamps

campaigns
├── id
├── name (unique)
└── timestamps

stations
├── id
├── site_id (foreign key → sites)
├── station_number
├── campaign_id (foreign key → campaigns)
├── status (Admin, Occupied, Vacant, No PC)
├── monitor_type (enum: single, dual)
├── pc_spec_id (foreign key → pc_specs, nullable)
└── timestamps

monitor_station
├── station_id
├── monitor_spec_id
├── quantity
└── timestamps
```

### Entity Relationships

```
Site (1) ─────────┬──── (N) Station
                  │
Campaign (1) ─────┤
                  │
PcSpec (1) ───────┘
```

---

## 🎯 Key Routes

### Sites
```
GET    /sites                      - List all sites
GET    /sites/create               - Create form
POST   /sites                      - Store new site
GET    /sites/{id}/edit            - Edit form
PUT    /sites/{id}                 - Update site
DELETE /sites/{id}                 - Delete site
```

### Campaigns
```
GET    /campaigns                  - List all campaigns
GET    /campaigns/create           - Create form
POST   /campaigns                  - Store new campaign
GET    /campaigns/{id}/edit        - Edit form
PUT    /campaigns/{id}             - Update campaign
DELETE /campaigns/{id}             - Delete campaign
```

### Stations
```
GET    /stations                   - List all stations
GET    /stations/create            - Create form
POST   /stations                   - Store new station
POST   /stations/bulk              - Bulk create stations
GET    /stations/{id}/edit         - Edit form
PUT    /stations/{id}              - Update station
DELETE /stations/{id}              - Delete station
GET    /stations/scan/{station}    - QR scan result page
```

### Station QR Codes
```
POST   /stations/qrcode/zip-selected           - Generate ZIP for selected stations
POST   /stations/qrcode/bulk-all               - Generate ZIP for all stations
GET    /stations/qrcode/bulk-progress/{jobId}  - Check bulk job progress
GET    /stations/qrcode/zip/{jobId}/download   - Download generated ZIP
```

---

## 🔒 Permissions

| Permission | Description |
|------------|-------------|
| `sites.view` | View sites |
| `sites.create` | Create sites |
| `sites.edit` | Edit sites |
| `sites.delete` | Delete sites |
| `campaigns.view` | View campaigns |
| `campaigns.create` | Create campaigns |
| `campaigns.edit` | Edit campaigns |
| `campaigns.delete` | Delete campaigns |
| `stations.view` | View stations |
| `stations.create` | Create stations |
| `stations.edit` | Edit stations |
| `stations.delete` | Delete stations |
| `stations.qrcode` | Generate station QR codes |
| `stations.bulk` | Bulk create stations |

---

## 💡 Features

### Station Management
- **Multi-Site Support**: Organize stations by physical location
- **Campaign Tracking**: Associate stations with campaigns/projects
- **PC Assignment**: Assign PCs to stations (one-to-one)
- **Monitor Configuration**: Track single/dual monitor setups
- **Status Tracking**: Admin, Occupied, Vacant, No PC states

### QR Code System
- **Individual Codes**: Generate QR for single station
- **Bulk Generation**: ZIP file with all station QR codes
- **Selected Generation**: ZIP for selected stations only
- **Background Processing**: Jobs handle large batch generation
- **Scan Result Page**: Public page for QR scan results

### Bulk Operations
- **Bulk Creation**: Create multiple stations at once
- **Range Specification**: Define start and end station numbers
- **Auto-numbering**: Automatic station number assignment

### Search & Filtering
- **Search by Number**: Find stations by station number
- **Filter by Site**: Filter stations by location
- **Filter by Campaign**: Filter stations by campaign
- **Filter by Status**: Filter by Admin/Occupied/Vacant/No PC

---

## 🎓 Key Files

### Models
- `app/Models/Site.php` - Physical locations
- `app/Models/Campaign.php` - Campaigns/projects
- `app/Models/Station.php` - Workstations

### Controllers
- `app/Http/Controllers/Station/SiteController.php`
- `app/Http/Controllers/Station/CampaignController.php`
- `app/Http/Controllers/Station/StationController.php`

### Jobs (Background Processing)
- `app/Jobs/GenerateAllStationQRCodesZip.php`
- `app/Jobs/GenerateSelectedStationQRCodesZip.php`

### Frontend Pages
- `resources/js/pages/Station/Index.tsx` - Station list
- `resources/js/pages/Station/Create.tsx` - Create station
- `resources/js/pages/Station/Edit.tsx` - Edit station
- `resources/js/pages/Station/ScanResult.tsx` - QR scan result
- `resources/js/pages/Station/Site/` - Site management
- `resources/js/pages/Station/Campaigns/` - Campaign management

---

## 📊 Dashboard Integration

The dashboard displays:
- **Total Stations**: Count by site
- **Vacant Stations**: Stations without assignments
- **Stations Without PCs**: Stations needing PC assignment
- **Dual Monitor Stations**: Stations with dual monitors
- **Maintenance Due**: Stations with overdue maintenance

---

## 🔗 Related Features

### PC Transfer Integration
- Transfer PCs between stations
- Track transfer history
- Remove PCs from stations

### PC Maintenance Integration
- Schedule maintenance per station
- Track maintenance status
- Dashboard alerts for overdue

### Monitor Association
- Associate monitor specs with stations
- Track quantity per station
- Separate from PC monitor configuration

---

*Last updated: December 15, 2025*
