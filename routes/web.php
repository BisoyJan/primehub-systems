<?php

use App\Http\Controllers\Station\CampaignController;
use App\Http\Controllers\Station\SiteController;
use App\Http\Controllers\Station\StationController;
use App\Http\Controllers\ProcessorSpecsController;
use App\Http\Controllers\DiskSpecsController;
use App\Http\Controllers\RamSpecsController;
use App\Http\Controllers\MonitorSpecsController;
use App\Http\Controllers\PcSpecController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\PcTransferController;
use App\Http\Controllers\PcMaintenanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\EmployeeScheduleController;
use App\Http\Controllers\BiometricRecordController;
use App\Http\Controllers\BiometricReprocessingController;
use App\Http\Controllers\BiometricAnomalyController;
use App\Http\Controllers\BiometricExportController;
use App\Http\Controllers\AttendanceUploadController;
use App\Http\Controllers\AttendancePointController;
use App\Http\Controllers\BiometricRetentionPolicyController;
use App\Http\Controllers\FormRequestRetentionPolicyController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\ItConcernController;
use App\Http\Controllers\MedicationRequestController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');


Route::middleware(['auth', 'verified'])->group(function () {
    // Pending approval page - accessible to authenticated but unapproved users
    Route::get('/pending-approval', function () {
        // If user is already approved, redirect to dashboard
        if (auth()->user()->is_approved) {
            return redirect()->route('dashboard');
        }
        return Inertia::render('auth/pending-approval');
    })->name('pending-approval');
});

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

    // Hardware Specs
    Route::resource('ramspecs', RamSpecsController::class)
        ->except(['show'])
        ->middleware('permission:hardware.view,hardware.create,hardware.edit,hardware.delete');
    Route::resource('diskspecs', DiskSpecsController::class)
        ->except(['show'])
        ->middleware('permission:hardware.view,hardware.create,hardware.edit,hardware.delete');
    Route::resource('processorspecs', ProcessorSpecsController::class)
        ->except(['show'])
        ->middleware('permission:hardware.view,hardware.create,hardware.edit,hardware.delete');
    Route::resource('monitorspecs', MonitorSpecsController::class)
        ->except(['show'])
        ->middleware('permission:hardware.view,hardware.create,hardware.edit,hardware.delete');

    // PC Specs
    Route::patch('pcspecs/{pcspec}/issue', [PcSpecController::class, 'updateIssue'])
        ->middleware('permission:pcspecs.update_issue')
        ->name('pcspecs.updateIssue');
    Route::resource('pcspecs', PcSpecController::class)
        ->middleware('permission:pcspecs.view,pcspecs.create,pcspecs.edit,pcspecs.delete');

    // QR Code ZIP features for PC Specs
    Route::prefix('pcspecs/qrcode')->name('pcspecs.qrcode.')->middleware('permission:pcspecs.qrcode')->group(function () {
        Route::post('zip-selected', [PcSpecController::class, 'zipSelected']);
        Route::post('bulk-all', [PcSpecController::class, 'bulkAll']);
        Route::get('bulk-progress/{jobId}', [PcSpecController::class, 'bulkProgress']);
        Route::get('zip/{jobId}/download', [PcSpecController::class, 'downloadZip'])->name('zip.download');
        Route::get('selected-progress/{jobId}', [PcSpecController::class, 'selectedZipProgress']);
        Route::get('selected-zip/{jobId}/download', [PcSpecController::class, 'downloadSelectedZip'])->name('selected.download');
    });

    // Sites & Campaigns
    Route::resource('sites', SiteController::class)
        ->except(['show'])
        ->middleware('permission:sites.view,sites.create,sites.edit,sites.delete');
    Route::resource('campaigns', CampaignController::class)
        ->except(['show'])
        ->middleware('permission:campaigns.view,campaigns.create,campaigns.edit,campaigns.delete');

    // Stations
    Route::post('stations/bulk', [StationController::class, 'storeBulk'])
        ->middleware('permission:stations.bulk')
        ->name('stations.bulk');
    Route::resource('stations', StationController::class)
        ->except(['show'])
        ->middleware('permission:stations.view,stations.create,stations.edit,stations.delete');

    // QR Code ZIP features for Stations
    Route::prefix('stations/qrcode')->name('stations.qrcode.')->middleware('permission:stations.qrcode')->group(function () {
        Route::post('zip-selected', [StationController::class, 'zipSelected']);
        Route::post('bulk-all', [StationController::class, 'bulkAllQRCodes']);
        Route::get('bulk-progress/{jobId}', [StationController::class, 'bulkProgress']);
        Route::get('zip/{jobId}/download', [StationController::class, 'downloadZip']);
        Route::get('selected-progress/{jobId}', [StationController::class, 'selectedZipProgress']);
        Route::get('selected-zip/{jobId}/download', [StationController::class, 'downloadSelectedZip']);
    });
    Route::get('stations/scan/{station}', [StationController::class, 'scanResult'])->name('stations.scanResult');

    // Stocks
    Route::resource('stocks', StockController::class)
        ->middleware('permission:stocks.view,stocks.create,stocks.edit,stocks.delete');
    Route::post('stocks/adjust', [StockController::class, 'adjust'])
        ->middleware('permission:stocks.adjust')
        ->name('stocks.adjust');

    // Accounts
    Route::resource('accounts', AccountController::class)
        ->except(['show'])
        ->middleware('permission:accounts.view,accounts.create,accounts.edit,accounts.delete');
    Route::post('accounts/{account}/approve', [AccountController::class, 'approve'])
        ->middleware('permission:accounts.edit')
        ->name('accounts.approve');
    Route::post('accounts/{account}/unapprove', [AccountController::class, 'unapprove'])
        ->middleware('permission:accounts.edit')
        ->name('accounts.unapprove');

    // PC Transfer
    Route::prefix('pc-transfers')->name('pc-transfers.')
        ->middleware('permission:pc_transfers.view,pc_transfers.create,pc_transfers.remove')
        ->group(function () {
            Route::get('/', [PcTransferController::class, 'index'])->name('index');
            Route::get('transfer/{station?}', [PcTransferController::class, 'transferPage'])->name('transferPage');
            Route::post('/', [PcTransferController::class, 'transfer'])->name('transfer');
            Route::post('bulk', [PcTransferController::class, 'bulkTransfer'])->name('bulk');
            Route::delete('remove', [PcTransferController::class, 'remove'])->name('remove');
            Route::get('history', [PcTransferController::class, 'history'])->name('history');
        });

    // PC Maintenance
    Route::resource('pc-maintenance', PcMaintenanceController::class)
        ->middleware('permission:pc_maintenance.view,pc_maintenance.create,pc_maintenance.edit,pc_maintenance.delete');

    // Attendance Management
    Route::prefix('attendance')->name('attendance.')
        ->middleware('permission:attendance.view,attendance.create,attendance.import,attendance.review,attendance.verify,attendance.approve,attendance.statistics,attendance.delete')
        ->group(function () {
            Route::get('/', [AttendanceController::class, 'index'])->name('index');
            Route::get('/calendar/{user?}', [AttendanceController::class, 'calendar'])->name('calendar');
            Route::get('/create', [AttendanceController::class, 'create'])->name('create');
            Route::post('/', [AttendanceController::class, 'store'])->name('store');
            Route::post('/bulk', [AttendanceController::class, 'bulkStore'])->name('bulkStore');
            Route::get('import', [AttendanceController::class, 'import'])->name('import');
            Route::post('upload', [AttendanceController::class, 'upload'])->name('upload');
            Route::get('review', [AttendanceController::class, 'review'])->name('review');
            Route::post('{attendance}/verify', [AttendanceController::class, 'verify'])->name('verify');
            Route::post('batch-verify', [AttendanceController::class, 'batchVerify'])->name('batchVerify');
            Route::post('{attendance}/mark-advised', [AttendanceController::class, 'markAdvised'])->name('markAdvised');
            Route::post('{attendance}/quick-approve', [AttendanceController::class, 'quickApprove'])->name('quickApprove');
            Route::post('bulk-quick-approve', [AttendanceController::class, 'bulkQuickApprove'])->name('bulkQuickApprove');
            Route::get('statistics', [AttendanceController::class, 'statistics'])->name('statistics');
            Route::delete('bulk-delete', [AttendanceController::class, 'bulkDelete'])->name('bulkDelete');
        });

    // Employee Schedules
    Route::resource('employee-schedules', EmployeeScheduleController::class)
        ->middleware('permission:schedules.view,schedules.create,schedules.edit,schedules.delete');
    Route::post('employee-schedules/{employeeSchedule}/toggle-active', [EmployeeScheduleController::class, 'toggleActive'])
        ->middleware('permission:schedules.toggle')
        ->name('employee-schedules.toggleActive');
    Route::get('employee-schedules/get-schedule', [EmployeeScheduleController::class, 'getSchedule'])
        ->middleware('permission:schedules.view')
        ->name('employee-schedules.getSchedule');

    // Biometric Records
    Route::prefix('biometric-records')->name('biometric-records.')
        ->middleware('permission:biometric.view')
        ->group(function () {
            Route::get('/', [BiometricRecordController::class, 'index'])->name('index');
            Route::get('/{user}/{date}', [BiometricRecordController::class, 'show'])->name('show');
        });

    // Biometric Reprocessing
    Route::prefix('biometric-reprocessing')->name('biometric-reprocessing.')
        ->middleware('permission:biometric.reprocess')
        ->group(function () {
            Route::get('/', [BiometricReprocessingController::class, 'index'])->name('index');
            Route::post('preview', [BiometricReprocessingController::class, 'preview'])->name('preview');
            Route::post('reprocess', [BiometricReprocessingController::class, 'reprocess'])->name('reprocess');
            Route::post('fix-statuses', [BiometricReprocessingController::class, 'fixStatuses'])->name('fix-statuses');
        });

    // Biometric Anomalies
    Route::prefix('biometric-anomalies')->name('biometric-anomalies.')
        ->middleware('permission:biometric.anomalies')
        ->group(function () {
            Route::get('/', [BiometricAnomalyController::class, 'index'])->name('index');
            Route::post('detect', [BiometricAnomalyController::class, 'detect'])->name('detect');
        });

    // Biometric Export
    Route::prefix('biometric-export')->name('biometric-export.')
        ->middleware('permission:biometric.export')
        ->group(function () {
            Route::get('/', [BiometricExportController::class, 'index'])->name('index');
            Route::get('export', [BiometricExportController::class, 'export'])->name('export');
        });

    // Attendance Uploads
    Route::prefix('attendance-uploads')->name('attendance-uploads.')
        ->middleware('permission:attendance.view')
        ->group(function () {
            Route::get('/', [AttendanceUploadController::class, 'index'])->name('index');
            Route::get('/{upload}', [AttendanceUploadController::class, 'show'])->name('show');
        });

    // Attendance Points
    Route::prefix('attendance-points')->name('attendance-points.')
        ->middleware('permission:attendance_points.view,attendance_points.excuse,attendance_points.export,attendance_points.rescan')
        ->group(function () {
            Route::get('/', [AttendancePointController::class, 'index'])->name('index');
            Route::post('/rescan', [AttendancePointController::class, 'rescan'])->name('rescan');
            Route::get('/export-all', [AttendancePointController::class, 'exportAll'])->name('export-all');
            Route::get('/export-all-excel', [AttendancePointController::class, 'exportAllExcel'])->name('export-all-excel');
            Route::get('/{user}', [AttendancePointController::class, 'show'])->name('show');
            Route::get('/{user}/statistics', [AttendancePointController::class, 'statistics'])->name('statistics');
            Route::get('/{user}/export', [AttendancePointController::class, 'export'])->name('export');
            Route::get('/{user}/export-excel', [AttendancePointController::class, 'exportExcel'])->name('export-excel');
            Route::post('/{point}/excuse', [AttendancePointController::class, 'excuse'])->name('excuse');
            Route::post('/{point}/unexcuse', [AttendancePointController::class, 'unexcuse'])->name('unexcuse');
        });

    // Biometric Retention Policies
    Route::prefix('biometric-retention-policies')->name('biometric-retention-policies.')
        ->middleware('permission:biometric.retention')
        ->group(function () {
            Route::get('/', [BiometricRetentionPolicyController::class, 'index'])->name('index');
            Route::post('/', [BiometricRetentionPolicyController::class, 'store'])->name('store');
            Route::put('/{policy}', [BiometricRetentionPolicyController::class, 'update'])->name('update');
            Route::delete('/{policy}', [BiometricRetentionPolicyController::class, 'destroy'])->name('destroy');
            Route::post('/{policy}/toggle', [BiometricRetentionPolicyController::class, 'toggle'])->name('toggle');
        });

    // Form Requests - Leave Requests
    Route::prefix('form-requests/leave-requests')->name('leave-requests.')
        ->middleware('permission:leave.view,leave.create,leave.approve,leave.deny,leave.cancel')
        ->group(function () {
            Route::get('/', [LeaveRequestController::class, 'index'])->name('index');
            Route::get('/create', [LeaveRequestController::class, 'create'])->name('create');
            Route::post('/', [LeaveRequestController::class, 'store'])->name('store');
            Route::get('/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('show');
        Route::post('/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->name('approve');
        Route::post('/{leaveRequest}/deny', [LeaveRequestController::class, 'deny'])->name('deny');
        Route::post('/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('cancel');
        Route::get('/api/credits-balance', [LeaveRequestController::class, 'getCreditsBalance'])->name('api.credits-balance');
        Route::post('/api/calculate-days', [LeaveRequestController::class, 'calculateDays'])->name('api.calculate-days');
    });

    // Form Requests - IT Concerns
    Route::prefix('form-requests/it-concerns')->name('it-concerns.')
        ->middleware('permission:it_concerns.view,it_concerns.create,it_concerns.edit,it_concerns.delete,it_concerns.assign,it_concerns.resolve')
        ->group(function () {
            Route::get('/', [ItConcernController::class, 'index'])->name('index');
            Route::get('/create', [ItConcernController::class, 'create'])->name('create');
            Route::post('/', [ItConcernController::class, 'store'])->name('store');
            Route::get('/{itConcern}', [ItConcernController::class, 'show'])->name('show');
            Route::get('/{itConcern}/edit', [ItConcernController::class, 'edit'])->name('edit');
            Route::put('/{itConcern}', [ItConcernController::class, 'update'])->name('update');
            Route::delete('/{itConcern}', [ItConcernController::class, 'destroy'])->name('destroy');
            Route::post('/{itConcern}/status', [ItConcernController::class, 'updateStatus'])->name('updateStatus');
            Route::post('/{itConcern}/assign', [ItConcernController::class, 'assign'])->name('assign');
            Route::post('/{itConcern}/resolve', [ItConcernController::class, 'resolve'])->name('resolve');
        });

    // Form Requests - Medication Requests
    Route::prefix('form-requests/medication-requests')->name('medication-requests.')
        ->middleware('permission:medication_requests.view,medication_requests.create,medication_requests.update,medication_requests.delete')
        ->group(function () {
            Route::get('/', [MedicationRequestController::class, 'index'])->name('index');
            Route::get('/create', [MedicationRequestController::class, 'create'])->name('create');
            Route::get('/check-pending/{userId}', [MedicationRequestController::class, 'checkPendingRequest'])->name('check-pending');
            Route::post('/', [MedicationRequestController::class, 'store'])->name('store');
            Route::get('/{medicationRequest}', [MedicationRequestController::class, 'show'])->name('show');
            Route::post('/{medicationRequest}/status', [MedicationRequestController::class, 'updateStatus'])->name('updateStatus');
            Route::delete('/{medicationRequest}/cancel', [MedicationRequestController::class, 'cancel'])->name('cancel');
            Route::delete('/{medicationRequest}', [MedicationRequestController::class, 'destroy'])->name('destroy');
        });

    // Form Requests - Retention Policies
    Route::prefix('form-requests/retention-policies')->name('form-requests.retention-policies.')
        ->middleware('permission:form_requests.retention')
        ->group(function () {
            Route::get('/', [FormRequestRetentionPolicyController::class, 'index'])->name('index');
            Route::post('/', [FormRequestRetentionPolicyController::class, 'store'])->name('store');
            Route::put('/{policy}', [FormRequestRetentionPolicyController::class, 'update'])->name('update');
            Route::delete('/{policy}', [FormRequestRetentionPolicyController::class, 'destroy'])->name('destroy');
            Route::post('/{policy}/toggle', [FormRequestRetentionPolicyController::class, 'toggle'])->name('toggle');
        });

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::get('/recent', [NotificationController::class, 'recent'])->name('recent');
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('mark-as-read');
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::delete('/read/all', [NotificationController::class, 'deleteAllRead'])->name('delete-all-read');
    });
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';


