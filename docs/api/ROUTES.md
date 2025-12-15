# API & Routes Reference

Complete reference for all application routes, controllers, and API endpoints.

---

## 🌐 Route Organization

Routes are organized across multiple files:

| File | Purpose |
|------|---------|
| `routes/web.php` | Main application routes |
| `routes/auth.php` | Authentication routes |
| `routes/settings.php` | Settings routes |
| `routes/console.php` | Console commands |

---

## 🔐 Authentication Routes

**File:** `routes/auth.php`

### Guest Routes
| Method | URI | Controller | Name |
|--------|-----|------------|------|
| GET | /register | RegisteredUserController@create | register |
| POST | /register | RegisteredUserController@store | - |
| GET | /login | AuthenticatedSessionController@create | login |
| POST | /login | AuthenticatedSessionController@store | - |
| GET | /forgot-password | PasswordResetLinkController@create | password.request |
| POST | /forgot-password | PasswordResetLinkController@store | password.email |
| GET | /reset-password/{token} | NewPasswordController@create | password.reset |
| POST | /reset-password | NewPasswordController@store | password.store |

### Account Reactivation
| Method | URI | Controller | Name |
|--------|-----|------------|------|
| GET | /account/reactivate | AccountController@showReactivate | account.reactivate.show |
| POST | /account/reactivate | AccountController@reactivate | account.reactivate |

### Authenticated Routes
| Method | URI | Controller | Name |
|--------|-----|------------|------|
| GET | /verify-email | EmailVerificationPromptController | verification.notice |
| GET | /verify-email/{id}/{hash} | VerifyEmailController | verification.verify |
| POST | /email/verification-notification | EmailVerificationNotificationController | verification.send |
| POST | /logout | AuthenticatedSessionController@destroy | logout |

---

## 📊 Dashboard

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /dashboard | DashboardController@index | dashboard | dashboard.view |

---

## 🖥️ Hardware Specs

### RAM Specs
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /ramspecs | RamSpecsController@index | ramspecs.index | hardware.view |
| GET | /ramspecs/create | RamSpecsController@create | ramspecs.create | hardware.create |
| POST | /ramspecs | RamSpecsController@store | ramspecs.store | hardware.create |
| GET | /ramspecs/{id}/edit | RamSpecsController@edit | ramspecs.edit | hardware.edit |
| PUT | /ramspecs/{id} | RamSpecsController@update | ramspecs.update | hardware.edit |
| DELETE | /ramspecs/{id} | RamSpecsController@destroy | ramspecs.destroy | hardware.delete |

### Disk Specs
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /diskspecs | DiskSpecsController@index | diskspecs.index | hardware.view |
| GET | /diskspecs/create | DiskSpecsController@create | diskspecs.create | hardware.create |
| POST | /diskspecs | DiskSpecsController@store | diskspecs.store | hardware.create |
| GET | /diskspecs/{id}/edit | DiskSpecsController@edit | diskspecs.edit | hardware.edit |
| PUT | /diskspecs/{id} | DiskSpecsController@update | diskspecs.update | hardware.edit |
| DELETE | /diskspecs/{id} | DiskSpecsController@destroy | diskspecs.destroy | hardware.delete |

### Processor Specs
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /processorspecs | ProcessorSpecsController@index | processorspecs.index | hardware.view |
| GET | /processorspecs/create | ProcessorSpecsController@create | processorspecs.create | hardware.create |
| POST | /processorspecs | ProcessorSpecsController@store | processorspecs.store | hardware.create |
| GET | /processorspecs/{id}/edit | ProcessorSpecsController@edit | processorspecs.edit | hardware.edit |
| PUT | /processorspecs/{id} | ProcessorSpecsController@update | processorspecs.update | hardware.edit |
| DELETE | /processorspecs/{id} | ProcessorSpecsController@destroy | processorspecs.destroy | hardware.delete |

### Monitor Specs
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /monitorspecs | MonitorSpecsController@index | monitorspecs.index | hardware.view |
| GET | /monitorspecs/create | MonitorSpecsController@create | monitorspecs.create | hardware.create |
| POST | /monitorspecs | MonitorSpecsController@store | monitorspecs.store | hardware.create |
| GET | /monitorspecs/{id}/edit | MonitorSpecsController@edit | monitorspecs.edit | hardware.edit |
| PUT | /monitorspecs/{id} | MonitorSpecsController@update | monitorspecs.update | hardware.edit |
| DELETE | /monitorspecs/{id} | MonitorSpecsController@destroy | monitorspecs.destroy | hardware.delete |

---

## 💻 PC Specs

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /pcspecs | PcSpecController@index | pcspecs.index | pcspecs.view |
| GET | /pcspecs/create | PcSpecController@create | pcspecs.create | pcspecs.create |
| POST | /pcspecs | PcSpecController@store | pcspecs.store | pcspecs.create |
| GET | /pcspecs/{id} | PcSpecController@show | pcspecs.show | pcspecs.view |
| GET | /pcspecs/{id}/edit | PcSpecController@edit | pcspecs.edit | pcspecs.edit |
| PUT | /pcspecs/{id} | PcSpecController@update | pcspecs.update | pcspecs.edit |
| DELETE | /pcspecs/{id} | PcSpecController@destroy | pcspecs.destroy | pcspecs.delete |
| PATCH | /pcspecs/{id}/issue | PcSpecController@updateIssue | pcspecs.updateIssue | pcspecs.update_issue |

### QR Code Operations
| Method | URI | Controller | Permission |
|--------|-----|------------|------------|
| POST | /pcspecs/qrcode/zip-selected | PcSpecController@zipSelected | pcspecs.qrcode |
| POST | /pcspecs/qrcode/bulk-all | PcSpecController@bulkAll | pcspecs.qrcode |
| GET | /pcspecs/qrcode/bulk-progress/{jobId} | PcSpecController@bulkProgress | pcspecs.qrcode |
| GET | /pcspecs/qrcode/zip/{jobId}/download | PcSpecController@downloadZip | pcspecs.qrcode |
| GET | /pcspecs/qrcode/selected-progress/{jobId} | PcSpecController@selectedZipProgress | pcspecs.qrcode |
| GET | /pcspecs/qrcode/selected-zip/{jobId}/download | PcSpecController@downloadSelectedZip | pcspecs.qrcode |

---

## 🏢 Sites & Campaigns

### Sites
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /sites | SiteController@index | sites.index | sites.view |
| GET | /sites/create | SiteController@create | sites.create | sites.create |
| POST | /sites | SiteController@store | sites.store | sites.create |
| GET | /sites/{id}/edit | SiteController@edit | sites.edit | sites.edit |
| PUT | /sites/{id} | SiteController@update | sites.update | sites.edit |
| DELETE | /sites/{id} | SiteController@destroy | sites.destroy | sites.delete |

### Campaigns
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /campaigns | CampaignController@index | campaigns.index | campaigns.view |
| GET | /campaigns/create | CampaignController@create | campaigns.create | campaigns.create |
| POST | /campaigns | CampaignController@store | campaigns.store | campaigns.create |
| GET | /campaigns/{id}/edit | CampaignController@edit | campaigns.edit | campaigns.edit |
| PUT | /campaigns/{id} | CampaignController@update | campaigns.update | campaigns.edit |
| DELETE | /campaigns/{id} | CampaignController@destroy | campaigns.destroy | campaigns.delete |

---

## 📍 Stations

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /stations | StationController@index | stations.index | stations.view |
| GET | /stations/create | StationController@create | stations.create | stations.create |
| POST | /stations | StationController@store | stations.store | stations.create |
| POST | /stations/bulk | StationController@storeBulk | stations.bulk | stations.bulk |
| GET | /stations/{id}/edit | StationController@edit | stations.edit | stations.edit |
| PUT | /stations/{id} | StationController@update | stations.update | stations.edit |
| DELETE | /stations/{id} | StationController@destroy | stations.destroy | stations.delete |
| GET | /stations/scan/{station} | StationController@scanResult | stations.scanResult | - |

### Station QR Codes
| Method | URI | Controller | Permission |
|--------|-----|------------|------------|
| POST | /stations/qrcode/zip-selected | StationController@zipSelected | stations.qrcode |
| POST | /stations/qrcode/bulk-all | StationController@bulkAllQRCodes | stations.qrcode |
| GET | /stations/qrcode/bulk-progress/{jobId} | StationController@bulkProgress | stations.qrcode |
| GET | /stations/qrcode/zip/{jobId}/download | StationController@downloadZip | stations.qrcode |
| GET | /stations/qrcode/selected-progress/{jobId} | StationController@selectedZipProgress | stations.qrcode |
| GET | /stations/qrcode/selected-zip/{jobId}/download | StationController@downloadSelectedZip | stations.qrcode |
| POST | /stations/qrcode/bulk-all-stream | StationController@bulkAllQRCodesStream | stations.qrcode |
| POST | /stations/qrcode/zip-selected-stream | StationController@zipSelectedStream | stations.qrcode |

---

## 📦 Stocks

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /stocks | StockController@index | stocks.index | stocks.view |
| GET | /stocks/create | StockController@create | stocks.create | stocks.create |
| POST | /stocks | StockController@store | stocks.store | stocks.create |
| GET | /stocks/{id} | StockController@show | stocks.show | stocks.view |
| GET | /stocks/{id}/edit | StockController@edit | stocks.edit | stocks.edit |
| PUT | /stocks/{id} | StockController@update | stocks.update | stocks.edit |
| DELETE | /stocks/{id} | StockController@destroy | stocks.destroy | stocks.delete |
| POST | /stocks/adjust | StockController@adjust | stocks.adjust | stocks.adjust |

---

## 🔄 PC Transfers

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /pc-transfers | PcTransferController@index | pc-transfers.index | pc_transfers.view |
| GET | /pc-transfers/transfer/{station?} | PcTransferController@transferPage | pc-transfers.transferPage | pc_transfers.create |
| POST | /pc-transfers | PcTransferController@transfer | pc-transfers.transfer | pc_transfers.create |
| POST | /pc-transfers/bulk | PcTransferController@bulkTransfer | pc-transfers.bulk | pc_transfers.create |
| DELETE | /pc-transfers/remove | PcTransferController@remove | pc-transfers.remove | pc_transfers.remove |
| GET | /pc-transfers/history | PcTransferController@history | pc-transfers.history | pc_transfers.view |

---

## 🔧 PC Maintenance

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /pc-maintenance | PcMaintenanceController@index | pc-maintenance.index | pc_maintenance.view |
| GET | /pc-maintenance/create | PcMaintenanceController@create | pc-maintenance.create | pc_maintenance.create |
| POST | /pc-maintenance | PcMaintenanceController@store | pc-maintenance.store | pc_maintenance.create |
| GET | /pc-maintenance/{id} | PcMaintenanceController@show | pc-maintenance.show | pc_maintenance.view |
| GET | /pc-maintenance/{id}/edit | PcMaintenanceController@edit | pc-maintenance.edit | pc_maintenance.edit |
| PUT | /pc-maintenance/{id} | PcMaintenanceController@update | pc-maintenance.update | pc_maintenance.edit |
| DELETE | /pc-maintenance/{id} | PcMaintenanceController@destroy | pc-maintenance.destroy | pc_maintenance.delete |
| POST | /pc-maintenance/bulk-update | PcMaintenanceController@bulkUpdate | pc-maintenance.bulkUpdate | pc_maintenance.edit |

---

## 👥 Accounts

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /accounts | AccountController@index | accounts.index | accounts.view |
| GET | /accounts/create | AccountController@create | accounts.create | accounts.create |
| POST | /accounts | AccountController@store | accounts.store | accounts.create |
| GET | /accounts/{id}/edit | AccountController@edit | accounts.edit | accounts.edit |
| PUT | /accounts/{id} | AccountController@update | accounts.update | accounts.edit |
| DELETE | /accounts/{id} | AccountController@destroy | accounts.destroy | accounts.delete |
| POST | /accounts/{id}/approve | AccountController@approve | accounts.approve | accounts.edit |
| POST | /accounts/{id}/unapprove | AccountController@unapprove | accounts.unapprove | accounts.edit |
| POST | /accounts/{id}/toggle-active | AccountController@toggleActive | accounts.toggleActive | accounts.edit |
| POST | /accounts/bulk-approve | AccountController@bulkApprove | accounts.bulkApprove | accounts.edit |
| POST | /accounts/bulk-unapprove | AccountController@bulkUnapprove | accounts.bulkUnapprove | accounts.edit |
| POST | /accounts/{id}/confirm-delete | AccountController@confirmDelete | accounts.confirmDelete | accounts.delete |
| POST | /accounts/{id}/restore | AccountController@restore | accounts.restore | accounts.edit |
| DELETE | /accounts/{id}/force-delete | AccountController@forceDelete | accounts.forceDelete | accounts.delete |

---

## 📊 Activity Logs

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /activity-logs | ActivityLogController@index | activity-logs.index | activity_logs.view |

---

## ⏰ Attendance

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /attendance | AttendanceController@index | attendance.index | attendance.view |
| GET | /attendance/calendar/{user?} | AttendanceController@calendar | attendance.calendar | attendance.view |
| GET | /attendance/create | AttendanceController@create | attendance.create | attendance.create |
| POST | /attendance | AttendanceController@store | attendance.store | attendance.create |
| POST | /attendance/bulk | AttendanceController@bulkStore | attendance.bulkStore | attendance.create |
| GET | /attendance/import | AttendanceController@import | attendance.import | attendance.import |
| POST | /attendance/upload | AttendanceController@upload | attendance.upload | attendance.import |
| GET | /attendance/review | AttendanceController@review | attendance.review | attendance.review |
| POST | /attendance/{id}/verify | AttendanceController@verify | attendance.verify | attendance.verify |
| POST | /attendance/batch-verify | AttendanceController@batchVerify | attendance.batchVerify | attendance.verify |
| POST | /attendance/{id}/mark-advised | AttendanceController@markAdvised | attendance.markAdvised | attendance.verify |
| POST | /attendance/{id}/quick-approve | AttendanceController@quickApprove | attendance.quickApprove | attendance.approve |
| POST | /attendance/bulk-quick-approve | AttendanceController@bulkQuickApprove | attendance.bulkQuickApprove | attendance.approve |
| GET | /attendance/statistics | AttendanceController@statistics | attendance.statistics | attendance.statistics |
| DELETE | /attendance/bulk-delete | AttendanceController@bulkDelete | attendance.bulkDelete | attendance.delete |

---

## 📅 Employee Schedules

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /employee-schedules | EmployeeScheduleController@index | employee-schedules.index | schedules.view |
| GET | /employee-schedules/create | EmployeeScheduleController@create | employee-schedules.create | schedules.create |
| POST | /employee-schedules | EmployeeScheduleController@store | employee-schedules.store | schedules.create |
| GET | /employee-schedules/{id} | EmployeeScheduleController@show | employee-schedules.show | schedules.view |
| GET | /employee-schedules/{id}/edit | EmployeeScheduleController@edit | employee-schedules.edit | schedules.edit |
| PUT | /employee-schedules/{id} | EmployeeScheduleController@update | employee-schedules.update | schedules.edit |
| DELETE | /employee-schedules/{id} | EmployeeScheduleController@destroy | employee-schedules.destroy | schedules.delete |
| POST | /employee-schedules/{id}/toggle-active | EmployeeScheduleController@toggleActive | employee-schedules.toggleActive | schedules.toggle |
| GET | /employee-schedules/get-schedule | EmployeeScheduleController@getSchedule | employee-schedules.getSchedule | schedules.view |
| GET | /employee-schedules/user/{userId}/schedules | EmployeeScheduleController@getUserSchedules | employee-schedules.getUserSchedules | schedules.view |
| GET | /schedule-setup | EmployeeScheduleController@firstTimeSetup | schedule-setup | - |
| POST | /schedule-setup | EmployeeScheduleController@storeFirstTimeSetup | schedule-setup.store | - |

---

## 📊 Biometric Records

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /biometric-records | BiometricRecordController@index | biometric-records.index | biometric.view |
| GET | /biometric-records/{user}/{date} | BiometricRecordController@show | biometric-records.show | biometric.view |

### Biometric Reprocessing
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /biometric-reprocessing | BiometricReprocessingController@index | biometric-reprocessing.index | biometric.reprocess |
| POST | /biometric-reprocessing/preview | BiometricReprocessingController@preview | biometric-reprocessing.preview | biometric.reprocess |
| POST | /biometric-reprocessing/reprocess | BiometricReprocessingController@reprocess | biometric-reprocessing.reprocess | biometric.reprocess |
| POST | /biometric-reprocessing/fix-statuses | BiometricReprocessingController@fixStatuses | biometric-reprocessing.fix-statuses | biometric.reprocess |

### Biometric Anomalies
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /biometric-anomalies | BiometricAnomalyController@index | biometric-anomalies.index | biometric.anomalies |
| POST | /biometric-anomalies/detect | BiometricAnomalyController@detect | biometric-anomalies.detect | biometric.anomalies |

### Biometric Export
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /biometric-export | BiometricExportController@index | biometric-export.index | biometric.export |
| POST | /biometric-export/start | BiometricExportController@startExport | biometric-export.start | biometric.export |
| GET | /biometric-export/progress/{jobId} | BiometricExportController@exportProgress | biometric-export.progress | biometric.export |
| GET | /biometric-export/download/{jobId} | BiometricExportController@downloadExport | biometric-export.download | biometric.export |

### Biometric Retention Policies
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /biometric-retention-policies | BiometricRetentionPolicyController@index | biometric-retention-policies.index | biometric.retention |
| POST | /biometric-retention-policies | BiometricRetentionPolicyController@store | biometric-retention-policies.store | biometric.retention |
| PUT | /biometric-retention-policies/{policy} | BiometricRetentionPolicyController@update | biometric-retention-policies.update | biometric.retention |
| DELETE | /biometric-retention-policies/{policy} | BiometricRetentionPolicyController@destroy | biometric-retention-policies.destroy | biometric.retention |
| POST | /biometric-retention-policies/{policy}/toggle | BiometricRetentionPolicyController@toggle | biometric-retention-policies.toggle | biometric.retention |

---

## 📈 Attendance Points

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /attendance-points | AttendancePointController@index | attendance-points.index | attendance_points.view |
| POST | /attendance-points | AttendancePointController@store | attendance-points.store | attendance_points.create |
| POST | /attendance-points/rescan | AttendancePointController@rescan | attendance-points.rescan | attendance_points.rescan |
| POST | /attendance-points/start-export-all-excel | AttendancePointController@startExportAllExcel | attendance-points.start-export-all-excel | attendance_points.export |
| GET | /attendance-points/export-all-excel/status/{jobId} | AttendancePointController@checkExportAllExcelStatus | attendance-points.export-all-excel.status | attendance_points.export |
| GET | /attendance-points/export-all-excel/download/{jobId} | AttendancePointController@downloadExportAllExcel | attendance-points.export-all-excel.download | attendance_points.export |
| GET | /attendance-points/{user} | AttendancePointController@show | attendance-points.show | attendance_points.view |
| GET | /attendance-points/{user}/statistics | AttendancePointController@statistics | attendance-points.statistics | attendance_points.view |
| GET | /attendance-points/{user}/export | AttendancePointController@export | attendance-points.export | attendance_points.export |
| POST | /attendance-points/{user}/start-export-excel | AttendancePointController@startExportExcel | attendance-points.start-export-excel | attendance_points.export |
| GET | /attendance-points/export-excel/status/{jobId} | AttendancePointController@checkExportExcelStatus | attendance-points.export-excel.status | attendance_points.export |
| GET | /attendance-points/export-excel/download/{jobId} | AttendancePointController@downloadExportExcel | attendance-points.export-excel.download | attendance_points.export |
| PUT | /attendance-points/{point} | AttendancePointController@update | attendance-points.update | attendance_points.edit |
| DELETE | /attendance-points/{point} | AttendancePointController@destroy | attendance-points.destroy | attendance_points.delete |
| POST | /attendance-points/{point}/excuse | AttendancePointController@excuse | attendance-points.excuse | attendance_points.excuse |
| POST | /attendance-points/{point}/unexcuse | AttendancePointController@unexcuse | attendance-points.unexcuse | attendance_points.excuse |

---

## 📋 Attendance Uploads

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /attendance-uploads | AttendanceUploadController@index | attendance-uploads.index | attendance.view |
| GET | /attendance-uploads/{upload} | AttendanceUploadController@show | attendance-uploads.show | attendance.view |

---

## 🏖️ Leave Requests

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /form-requests/leave-requests | LeaveRequestController@index | leave-requests.index | leave.view |
| GET | /form-requests/leave-requests/create | LeaveRequestController@create | leave-requests.create | leave.create |
| POST | /form-requests/leave-requests | LeaveRequestController@store | leave-requests.store | leave.create |
| GET | /form-requests/leave-requests/{id} | LeaveRequestController@show | leave-requests.show | leave.view |
| GET | /form-requests/leave-requests/{id}/edit | LeaveRequestController@edit | leave-requests.edit | leave.edit |
| PUT | /form-requests/leave-requests/{id} | LeaveRequestController@update | leave-requests.update | leave.edit |
| POST | /form-requests/leave-requests/{id}/approve | LeaveRequestController@approve | leave-requests.approve | leave.approve |
| POST | /form-requests/leave-requests/{id}/deny | LeaveRequestController@deny | leave-requests.deny | leave.deny |
| POST | /form-requests/leave-requests/{id}/approve-tl | LeaveRequestController@approveTL | leave-requests.approve-tl | leave.approve |
| POST | /form-requests/leave-requests/{id}/deny-tl | LeaveRequestController@denyTL | leave-requests.deny-tl | leave.deny |
| POST | /form-requests/leave-requests/{id}/cancel | LeaveRequestController@cancel | leave-requests.cancel | leave.cancel |
| DELETE | /form-requests/leave-requests/{id} | LeaveRequestController@destroy | leave-requests.destroy | leave.delete |
| GET | /form-requests/leave-requests/api/credits-balance | LeaveRequestController@getCreditsBalance | leave-requests.api.credits-balance | leave.view |
| POST | /form-requests/leave-requests/api/calculate-days | LeaveRequestController@calculateDays | leave-requests.api.calculate-days | leave.view |
| POST | /form-requests/leave-requests/export/credits | LeaveRequestController@exportCredits | leave-requests.export.credits | leave.export |
| GET | /form-requests/leave-requests/export/credits/progress | LeaveRequestController@exportCreditsProgress | leave-requests.export.credits.progress | leave.export |
| GET | /form-requests/leave-requests/export/credits/download/{filename} | LeaveRequestController@exportCreditsDownload | leave-requests.export.download | leave.export |

### Leave Credits
| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /form-requests/leave-requests/credits | LeaveRequestController@creditsIndex | leave-requests.credits.index | leave_credits.view_all |
| GET | /form-requests/leave-requests/credits/{user} | LeaveRequestController@creditsShow | leave-requests.credits.show | leave_credits.view_all |

---

## 🔧 IT Concerns

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /form-requests/it-concerns | ItConcernController@index | it-concerns.index | it_concerns.view |
| GET | /form-requests/it-concerns/create | ItConcernController@create | it-concerns.create | it_concerns.create |
| POST | /form-requests/it-concerns | ItConcernController@store | it-concerns.store | it_concerns.create |
| GET | /form-requests/it-concerns/{id} | ItConcernController@show | it-concerns.show | it_concerns.view |
| GET | /form-requests/it-concerns/{id}/edit | ItConcernController@edit | it-concerns.edit | it_concerns.edit |
| PUT | /form-requests/it-concerns/{id} | ItConcernController@update | it-concerns.update | it_concerns.edit |
| DELETE | /form-requests/it-concerns/{id} | ItConcernController@destroy | it-concerns.destroy | it_concerns.delete |
| POST | /form-requests/it-concerns/{id}/status | ItConcernController@updateStatus | it-concerns.updateStatus | it_concerns.edit |
| POST | /form-requests/it-concerns/{id}/assign | ItConcernController@assign | it-concerns.assign | it_concerns.assign |
| POST | /form-requests/it-concerns/{id}/resolve | ItConcernController@resolve | it-concerns.resolve | it_concerns.resolve |
| POST | /form-requests/it-concerns/{id}/cancel | ItConcernController@cancel | it-concerns.cancel | it_concerns.edit |

---

## 💊 Medication Requests

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /form-requests/medication-requests | MedicationRequestController@index | medication-requests.index | medication_requests.view |
| GET | /form-requests/medication-requests/create | MedicationRequestController@create | medication-requests.create | medication_requests.create |
| GET | /form-requests/medication-requests/check-pending/{userId} | MedicationRequestController@checkPendingRequest | medication-requests.check-pending | medication_requests.create |
| POST | /form-requests/medication-requests | MedicationRequestController@store | medication-requests.store | medication_requests.create |
| GET | /form-requests/medication-requests/{id} | MedicationRequestController@show | medication-requests.show | medication_requests.view |
| POST | /form-requests/medication-requests/{id}/status | MedicationRequestController@updateStatus | medication-requests.updateStatus | medication_requests.update |
| DELETE | /form-requests/medication-requests/{id}/cancel | MedicationRequestController@cancel | medication-requests.cancel | medication_requests.update |
| DELETE | /form-requests/medication-requests/{id} | MedicationRequestController@destroy | medication-requests.destroy | medication_requests.delete |

---

## 📋 Form Request Retention Policies

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /form-requests/retention-policies | FormRequestRetentionPolicyController@index | form-requests.retention-policies.index | form_requests.retention |
| POST | /form-requests/retention-policies | FormRequestRetentionPolicyController@store | form-requests.retention-policies.store | form_requests.retention |
| PUT | /form-requests/retention-policies/{policy} | FormRequestRetentionPolicyController@update | form-requests.retention-policies.update | form_requests.retention |
| DELETE | /form-requests/retention-policies/{policy} | FormRequestRetentionPolicyController@destroy | form-requests.retention-policies.destroy | form_requests.retention |
| POST | /form-requests/retention-policies/{policy}/toggle | FormRequestRetentionPolicyController@toggle | form-requests.retention-policies.toggle | form_requests.retention |

---

## 🔔 Notifications

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /notifications | NotificationController@index | notifications.index | - |
| GET | /notifications/unread-count | NotificationController@unreadCount | notifications.unread-count | - |
| GET | /notifications/recent | NotificationController@recent | notifications.recent | - |
| POST | /notifications/{notification}/read | NotificationController@markAsRead | notifications.mark-as-read | - |
| POST | /notifications/mark-all-read | NotificationController@markAllAsRead | notifications.mark-all-read | - |
| DELETE | /notifications/all | NotificationController@deleteAll | notifications.delete-all | - |
| DELETE | /notifications/{notification} | NotificationController@destroy | notifications.destroy | - |
| DELETE | /notifications/read/all | NotificationController@deleteAllRead | notifications.delete-all-read | - |

---

## ⚙️ Settings

| Method | URI | Controller | Name | Permission |
|--------|-----|------------|------|------------|
| GET | /settings | Redirect to /settings/account | - | - |
| GET | /settings/account | ProfileController@edit | account.edit | - |
| PATCH | /settings/account | ProfileController@update | account.update | - |
| DELETE | /settings/account | ProfileController@destroy | account.destroy | - |
| GET | /settings/password | PasswordController@edit | password.edit | - |
| PUT | /settings/password | PasswordController@update | password.update | - |
| GET | /settings/preferences | PreferencesController@edit | preferences.edit | - |
| PATCH | /settings/preferences | PreferencesController@update | preferences.update | - |
| GET | /settings/appearance | Inertia Page | appearance.edit | - |
| GET | /settings/two-factor | TwoFactorAuthenticationController@show | two-factor.show | - |

---

## 🔒 Middleware Groups

### Applied to All Routes
- `auth` - Require authentication
- `verified` - Require email verification
- `approved` - Require admin approval

### Permission Middleware
```php
Route::middleware('permission:permission.name')
```

Checks if user has specific permission based on their role.

---

*Last updated: December 15, 2025*
