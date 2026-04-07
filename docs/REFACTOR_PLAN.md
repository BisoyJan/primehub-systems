# Hardware Module Refactoring Plan

## Status: ✅ COMPLETED

## Overview
Remove RamSpecs, DiskSpecs, and MonitorSpecs as standalone modules. Simplify PcSpecs to store RAM (GB), Disk (GB), and Available Ports directly. Add "Release Date" to ProcessorSpecs and remove its Stock feature.

---

## Phase 1: Database Migrations ✅
Create migrations to:
- [x] Add `ram_gb` (integer), `disk_gb` (integer), `available_ports` (string, comma-separated) columns to `pc_specs` table
- [x] Add `release_date` (date, nullable) column to `processor_specs` table
- [x] Drop pivot tables: `pc_spec_ram_spec`, `pc_spec_disk_spec`, `monitor_pc_spec`
- [x] Drop tables: `ram_specs`, `disk_specs`, `monitor_specs`, `monitor_station`
- [x] Remove Stock records where `stockable_type` is RamSpec, DiskSpec, MonitorSpec, or ProcessorSpec

## Phase 2: Remove RamSpecs Module
### Backend (Delete files)
- [x] `app/Models/RamSpec.php`
- [x] `app/Http/Controllers/RamSpecsController.php`
- [x] `app/Http/Requests/RamSpecRequest.php`
- [x] `database/factories/RamSpecFactory.php`
- [x] `database/seeders/RamSpecSeeder.php`

### Frontend (Delete files/folders)
- [x] `resources/js/pages/Computer/RamSpecs/` (entire folder: Index.tsx, Create.tsx, Edit.tsx)

### Tests (Delete files)
- [x] `tests/Feature/Controllers/Hardware/RamSpecsControllerTest.php`
- [x] `tests/Unit/Requests/RamSpecRequestTest.php`
- [x] `tests/Unit/Models/RamSpecTest.php` (if exists)

## Phase 3: Remove DiskSpecs Module
### Backend (Delete files)
- [x] `app/Models/DiskSpec.php`
- [x] `app/Http/Controllers/DiskSpecsController.php`
- [x] `app/Http/Requests/DiskSpecRequest.php`
- [x] `database/factories/DiskSpecFactory.php`
- [x] `database/seeders/DiskSpecSeeder.php`

### Frontend (Delete files/folders)
- [x] `resources/js/pages/Computer/DiskSpecs/` (entire folder: Index.tsx, Create.tsx, Edit.tsx)

### Tests (Delete files)
- [x] `tests/Feature/Controllers/Hardware/DiskSpecsControllerTest.php`
- [x] `tests/Unit/Requests/DiskSpecRequestTest.php`

## Phase 4: Remove MonitorSpecs Module
### Backend (Delete files)
- [x] `app/Models/MonitorSpec.php`
- [x] `app/Http/Controllers/MonitorSpecsController.php`
- [x] `app/Http/Requests/MonitorSpecRequest.php`
- [x] `database/factories/MonitorSpecFactory.php`
- [x] `database/seeders/MonitorSpecSeeder.php`

### Frontend (Delete files/folders)
- [x] `resources/js/pages/Computer/MonitorSpecs/` (entire folder: Index.tsx, Form.tsx)

### Tests (Delete files)
- [x] `tests/Feature/Controllers/Hardware/MonitorSpecsControllerTest.php`
- [x] `tests/Unit/Requests/MonitorSpecRequestTest.php`

## Phase 5: Update PcSpecs Module
### Backend Changes
- [x] **PcSpec Model** (`app/Models/PcSpec.php`):
  - Add `ram_gb`, `disk_gb`, `available_ports` to `$fillable`
  - Add `ram_gb` and `disk_gb` to `casts()` as integer
  - Remove `ramSpecs()`, `diskSpecs()`, `monitors()` relationships
  - Keep `processorSpecs()` relationship (but remove stock logic)
  - Update `getFormattedDetails()` to use new columns instead of relationships
  - Update `getFormSelectionData()` to use new columns

- [x] **PcSpecController** (`app/Http/Controllers/PcSpecController.php`):
  - Remove RamSpec, DiskSpec imports
  - Remove `computeTotalRamCapacity()` method
  - Remove `applyRamStockDiffs()` method
  - Remove `applyDiskStockDiffs()` method
  - Remove `reserveAndDecrement()` for RAM/Disk
  - Remove `verifyStockForMultiple()` for RAM/Disk
  - Remove `decrementStockForMultiple()` for RAM/Disk
  - Simplify `create()` - no more ramOptions/diskOptions
  - Simplify `store()` - validate `ram_gb`, `disk_gb`, `available_ports` as simple fields
  - Remove stock decrement/increment logic for RAM/Disk in store/update/destroy
  - Simplify `edit()` - no more ramOptions/diskOptions
  - Simplify `update()` - no more RAM/Disk stock diff logic
  - Simplify `destroy()` - remove RAM/Disk/Processor stock restoration
  - Update `index()` - show `ram_gb`, `disk_gb`, `available_ports` instead of relationships
  - Remove processor stock logic from store/update/destroy
  - Keep processor assignment but remove stock tracking

- [x] **PcSpec Form Request** (create or update any existing validation)

### Frontend Changes
- [x] **PcSpecs/Index.tsx**: Display `ram_gb`, `disk_gb`, `available_ports` as simple columns
- [x] **PcSpecs/Create.tsx**: Replace RAM/Disk selection dropdowns with simple number inputs for GB and text input for ports
- [x] **PcSpecs/Edit.tsx**: Same as Create changes
- [x] **PcSpecs/Show.tsx**: Update to display new fields

## Phase 6: Update ProcessorSpecs Module
### Backend Changes
- [x] **ProcessorSpec Model** (`app/Models/ProcessorSpec.php`):
  - Add `release_date` to `$fillable`
  - Add `release_date` to `casts()` as `date`
  - Remove `stock()` relationship

- [x] **ProcessorSpecsController** (`app/Http/Controllers/ProcessorSpecsController.php`):
  - Remove `HandlesStockOperations` trait usage
  - Remove stock creation in `store()`
  - Remove stock checks in `destroy()`
  - Simplify deletion logic (only check if used in PcSpecs)

- [x] **ProcessorSpecRequest** (`app/Http/Requests/ProcessorSpecRequest.php`):
  - Add `release_date` validation (nullable|date)
  - Remove `stock_quantity` validation

- [x] **ProcessorSpecFactory** (`database/factories/ProcessorSpecFactory.php`):
  - Add `release_date` generation
  - Remove stock creation in afterCreating

### Frontend Changes
- [x] **ProcessorSpecs/Index.tsx**: Add Release Date column, remove Stock column
- [x] **ProcessorSpecs/Create.tsx**: Add Release Date field, remove Stock Quantity field
- [x] **ProcessorSpecs/Edit.tsx**: Add Release Date field

## Phase 7: Cross-Module Cleanup
### Routes (`routes/web.php`)
- [x] Remove `use` import for RamSpecsController, DiskSpecsController, MonitorSpecsController
- [x] Remove Route::resource for ramspecs, diskspecs, monitorspecs
- [x] Keep processorspecs route but update if needed

### Navigation (`resources/js/components/app-sidebar.tsx`)
- [x] Remove imports: `ramIndex`, `diskIndex`, `monitorIndex`
- [x] Remove sidebar items: "Ram Specs", "Disk Specs", "Monitor Specs"
- [x] Remove unused icon imports: `MemoryStick`, `HardDrive`, `Monitor`

### Command Palette (`resources/js/components/command-palette.tsx`)
- [x] Remove RamSpecs, DiskSpecs, MonitorSpecs command entries
- [x] Remove unused imports

### Stocks Module
- [x] **StockController** (`app/Http/Controllers/StockController.php`):
  - Remove `ram`, `disk`, `monitor`, `processor` from `$typeMap`
  - If stocks module becomes empty/useless, consider removing it entirely
- [x] **Stocks/Index.tsx**: Remove references to removed spec types
- [x] **Stock Model**: No changes needed (polymorphic, orphans cleaned by migration)

### Dashboard Service (`app/Services/DashboardService.php`)
- [x] Update `getUnassignedPcSpecs()` - remove loading `ramSpecs`, `diskSpecs` relationships
- [x] Update type mapping to remove RamSpec, DiskSpec, MonitorSpec
- [x] Update display format to use new `ram_gb`, `disk_gb` columns

### Station Module
- [x] **Station Model** (`app/Models/Station.php`): Remove `monitors()` relationship
- [x] **StationController**: Remove MonitorSpec loading in create/edit
- [x] **StationRequest** (`app/Http/Requests/StationRequest.php`): Remove `monitor_ids` validation
- [x] **Station Frontend Pages**: Remove monitor selection/display

### Seeders
- [x] **DatabaseSeeder**: Remove calls to RamSpecSeeder, DiskSpecSeeder, MonitorSpecSeeder
- [x] **PcSpecSeeder**: Update to use `ram_gb`, `disk_gb`, `available_ports` instead of spec syncing
- [x] **StockSeeder**: Remove stock creation for removed spec types

### HandlesStockOperations Trait
- [x] Review if still needed after removing stock from ProcessorSpec
- [x] If only used by removed controllers, delete it

### Policy
- [x] **HardwareSpecPolicy** (`app/Policies/HardwareSpecPolicy.php`): Keep for ProcessorSpecs

### Clean Orphaned Stocks Command
- [x] **CleanOrphanedStocks** (`app/Console/Commands/CleanOrphanedStocks.php`): Remove references to deleted model types

### Activity Logs
- [x] Historical activity logs for deleted spec types will remain but won't cause issues

### Wayfinder Routes (auto-generated)
- [x] Routes in `resources/js/routes/ramspecs/`, `resources/js/routes/diskspecs/`, `resources/js/routes/monitorspecs/` will be auto-cleaned on next `npm run build`

### Tests
- [x] **ComponentSpecsTest**: Remove RAM/Disk/Monitor related tests
- [x] **PcSpecCrudTest**: Update to use new fields
- [x] **PcSpecControllerTest**: Update to use new fields, remove stock interactions
- [x] **PcSpecTest (Unit)**: Update relationships, remove ram/disk/monitor assertions
- [x] **StationCrudTest**: Remove monitor spec references
- [x] **DashboardServiceTest**: Update stock summary references

### Documentation (optional)
- [x] Update `docs/computer/README.md`
- [x] Update `docs/api/ROUTES.md`
- [x] Update `docs/database/SCHEMA.md`
- [x] Update `.github/copilot-instructions.md` references

---

## Files to DELETE (complete list)

### Backend
1. `app/Models/RamSpec.php`
2. `app/Models/DiskSpec.php`
3. `app/Models/MonitorSpec.php`
4. `app/Http/Controllers/RamSpecsController.php`
5. `app/Http/Controllers/DiskSpecsController.php`
6. `app/Http/Controllers/MonitorSpecsController.php`
7. `app/Http/Requests/RamSpecRequest.php`
8. `app/Http/Requests/DiskSpecRequest.php`
9. `app/Http/Requests/MonitorSpecRequest.php`
10. `database/factories/RamSpecFactory.php`
11. `database/factories/DiskSpecFactory.php`
12. `database/factories/MonitorSpecFactory.php`
13. `database/seeders/RamSpecSeeder.php`
14. `database/seeders/DiskSpecSeeder.php`
15. `database/seeders/MonitorSpecSeeder.php`

### Frontend
16. `resources/js/pages/Computer/RamSpecs/` (entire folder)
17. `resources/js/pages/Computer/DiskSpecs/` (entire folder)
18. `resources/js/pages/Computer/MonitorSpecs/` (entire folder)

### Tests
19. `tests/Feature/Controllers/Hardware/RamSpecsControllerTest.php`
20. `tests/Feature/Controllers/Hardware/DiskSpecsControllerTest.php`
21. `tests/Feature/Controllers/Hardware/MonitorSpecsControllerTest.php`
22. `tests/Unit/Requests/RamSpecRequestTest.php`
23. `tests/Unit/Requests/DiskSpecRequestTest.php`
24. `tests/Unit/Requests/MonitorSpecRequestTest.php`

---

## Files to EDIT (complete list)

### Backend
1. `app/Models/PcSpec.php` - Add new fields, remove old relationships
2. `app/Models/ProcessorSpec.php` - Add release_date, remove stock relationship
3. `app/Models/Station.php` - Remove monitors() relationship
4. `app/Http/Controllers/PcSpecController.php` - Major simplification
5. `app/Http/Controllers/ProcessorSpecsController.php` - Remove stock logic
6. `app/Http/Controllers/StockController.php` - Remove spec type mappings
7. `app/Http/Controllers/Station/StationController.php` - Remove monitor references
8. `app/Http/Requests/ProcessorSpecRequest.php` - Add release_date, remove stock_quantity
9. `app/Http/Requests/StationRequest.php` - Remove monitor validation
10. `app/Services/DashboardService.php` - Update references
11. `app/Console/Commands/CleanOrphanedStocks.php` - Remove deleted model refs
12. `database/factories/ProcessorSpecFactory.php` - Add release_date
13. `database/factories/PcSpecFactory.php` - Add new fields
14. `database/seeders/DatabaseSeeder.php` - Remove deleted seeder calls
15. `database/seeders/PcSpecSeeder.php` - Update for new schema
16. `database/seeders/StockSeeder.php` - Remove deleted spec types
17. `routes/web.php` - Remove deleted routes
18. `config/permissions.php` - Review if hardware permissions still needed

### Frontend
19. `resources/js/pages/Computer/PcSpecs/Index.tsx` - New columns
20. `resources/js/pages/Computer/PcSpecs/Create.tsx` - Simplified form
21. `resources/js/pages/Computer/PcSpecs/Edit.tsx` - Simplified form
22. `resources/js/pages/Computer/PcSpecs/Show.tsx` - New fields
23. `resources/js/pages/Computer/ProcessorSpecs/Index.tsx` - Add release date, remove stock
24. `resources/js/pages/Computer/ProcessorSpecs/Create.tsx` - Add release date, remove stock
25. `resources/js/pages/Computer/ProcessorSpecs/Edit.tsx` - Add release date
26. `resources/js/pages/Computer/Stocks/Index.tsx` - Remove spec types
27. `resources/js/components/app-sidebar.tsx` - Remove nav items
28. `resources/js/components/command-palette.tsx` - Remove command entries

### Tests
29. `tests/Feature/ComponentSpecsTest.php` - Remove RAM/Disk/Monitor tests
30. `tests/Feature/PcSpecCrudTest.php` - Update for new schema
31. `tests/Feature/Controllers/Hardware/PcSpecControllerTest.php` - Update
32. `tests/Feature/Controllers/Hardware/ProcessorSpecsControllerTest.php` - Update (no stock)
33. `tests/Unit/Models/PcSpecTest.php` - Update relationships
34. `tests/Unit/Requests/ProcessorSpecRequestTest.php` - Update
35. `tests/Feature/Station/StationCrudTest.php` - Remove monitor refs
36. `tests/Unit/Services/DashboardServiceTest.php` - Update refs

---

## Execution Order
1. Phase 1 - Migrations (database changes)
2. Phase 2-4 - Delete removed modules (backend + frontend + tests)
3. Phase 5 - Update PcSpecs module
4. Phase 6 - Update ProcessorSpecs module ✅
5. Phase 7 - Cross-module cleanup (routes, nav, dashboard, stations, stocks, seeders, tests) ✅
6. Run `vendor/bin/pint --dirty` for PHP formatting ✅
7. Run `php artisan test --compact` to verify ✅ (all tests pass in batches; full suite hits PHP memory limit)
8. Run `npm run build` to regenerate Wayfinder routes

## Additional Files Fixed (Phase 8 Verification)
- `app/Http/Controllers/PcTransferController.php` - Removed `ramSpecs`/`diskSpecs` eager loading
- `database/factories/StockFactory.php` - Removed RamSpec/DiskSpec factory states
- `database/seeders/StationSeeder.php` - Removed MonitorSpec references
- `resources/js/pages/Station/Create.tsx` - Removed monitor selection UI
- `resources/js/pages/Station/Edit.tsx` - Removed monitor selection UI
- `resources/js/pages/Station/Index.tsx` - Removed monitors display/dialog
- `resources/js/components/MonitorSpecTable.tsx` - Deleted
- `resources/js/wayfinder/actions/App/Http/Controllers/{Ram,Disk,Monitor}SpecsController.ts` - Deleted
- `resources/js/wayfinder/routes/{ramspecs,diskspecs,monitorspecs}/` - Deleted
- `tests/Feature/Console/CleanOrphanedStocksTest.php` - Rewritten without deleted models
- `tests/Feature/Controllers/Station/StationControllerTest.php` - Removed monitor_ids references
- `tests/Feature/Controllers/Stocks/StockControllerTest.php` - Rewritten without deleted models
- `tests/Unit/Models/StationTest.php` - Removed monitors relationship test
- `tests/Unit/Models/StockTest.php` - Rewritten without deleted models
- `tests/Unit/Requests/StationRequestTest.php` - Removed monitor_ids validation tests
