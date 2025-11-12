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
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');


Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Hardware Specs
    Route::resource('ramspecs', RamSpecsController::class)->except(['show']);
    Route::resource('diskspecs', DiskSpecsController::class)->except(['show']);
    Route::resource('processorspecs', ProcessorSpecsController::class)->except(['show']);
    Route::resource('monitorspecs', MonitorSpecsController::class)->except(['show']);

    // PC Specs
    Route::patch('pcspecs/{pcspec}/issue', [PcSpecController::class, 'updateIssue'])->name('pcspecs.updateIssue');
    Route::resource('pcspecs', PcSpecController::class);

    // QR Code ZIP features for PC Specs
    Route::prefix('pcspecs/qrcode')->name('pcspecs.qrcode.')->group(function () {
        Route::post('zip-selected', [PcSpecController::class, 'zipSelected']);
        Route::post('bulk-all', [PcSpecController::class, 'bulkAll']);
        Route::get('bulk-progress/{jobId}', [PcSpecController::class, 'bulkProgress']);
        Route::get('zip/{jobId}/download', [PcSpecController::class, 'downloadZip'])->name('zip.download');
        Route::get('selected-progress/{jobId}', [PcSpecController::class, 'selectedZipProgress']);
        Route::get('selected-zip/{jobId}/download', [PcSpecController::class, 'downloadSelectedZip'])->name('selected.download');
    });

    // Sites & Campaigns
    Route::resource('sites', SiteController::class)->except(['show']);
    Route::resource('campaigns', CampaignController::class)->except(['show']);

    // Stations
    Route::post('stations/bulk', [StationController::class, 'storeBulk'])->name('stations.bulk');
    Route::resource('stations', StationController::class)->except(['show']);

    // QR Code ZIP features for Stations
    Route::prefix('stations/qrcode')->name('stations.qrcode.')->group(function () {
        Route::post('zip-selected', [StationController::class, 'zipSelected']);
        Route::post('bulk-all', [StationController::class, 'bulkAllQRCodes']);
        Route::get('bulk-progress/{jobId}', [StationController::class, 'bulkProgress']);
        Route::get('zip/{jobId}/download', [StationController::class, 'downloadZip']);
        Route::get('selected-progress/{jobId}', [StationController::class, 'selectedZipProgress']);
        Route::get('selected-zip/{jobId}/download', [StationController::class, 'downloadSelectedZip']);
    });
    Route::get('stations/scan/{station}', [StationController::class, 'scanResult'])->name('stations.scanResult');

    // Stocks
    Route::resource('stocks', StockController::class);
    Route::post('stocks/adjust', [StockController::class, 'adjust'])->name('stocks.adjust');

    // Accounts
    Route::resource('accounts', AccountController::class)->except(['show']);

    // PC Transfer
    Route::prefix('pc-transfers')->name('pc-transfers.')->group(function () {
        Route::get('/', [PcTransferController::class, 'index'])->name('index');
        Route::get('transfer/{station?}', [PcTransferController::class, 'transferPage'])->name('transferPage');
        Route::post('/', [PcTransferController::class, 'transfer'])->name('transfer');
        Route::post('bulk', [PcTransferController::class, 'bulkTransfer'])->name('bulk');
        Route::delete('remove', [PcTransferController::class, 'remove'])->name('remove');
        Route::get('history', [PcTransferController::class, 'history'])->name('history');
    });

    // PC Maintenance
    Route::resource('pc-maintenance', PcMaintenanceController::class);

    // Attendance Management
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::get('import', [AttendanceController::class, 'import'])->name('import');
        Route::post('upload', [AttendanceController::class, 'upload'])->name('upload');
        Route::get('review', [AttendanceController::class, 'review'])->name('review');
        Route::post('{attendance}/verify', [AttendanceController::class, 'verify'])->name('verify');
        Route::post('{attendance}/mark-advised', [AttendanceController::class, 'markAdvised'])->name('markAdvised');
        Route::get('statistics', [AttendanceController::class, 'statistics'])->name('statistics');
        Route::delete('bulk-delete', [AttendanceController::class, 'bulkDelete'])->name('bulkDelete');
    });

    // Employee Schedules
    Route::resource('employee-schedules', EmployeeScheduleController::class);
    Route::post('employee-schedules/{employeeSchedule}/toggle-active', [EmployeeScheduleController::class, 'toggleActive'])
        ->name('employee-schedules.toggleActive');
    Route::get('employee-schedules/get-schedule', [EmployeeScheduleController::class, 'getSchedule'])
        ->name('employee-schedules.getSchedule');

    // Biometric Records
    Route::prefix('biometric-records')->name('biometric-records.')->group(function () {
        Route::get('/', [BiometricRecordController::class, 'index'])->name('index');
        Route::get('/{user}/{date}', [BiometricRecordController::class, 'show'])->name('show');
    });

    // Biometric Reprocessing
    Route::prefix('biometric-reprocessing')->name('biometric-reprocessing.')->group(function () {
        Route::get('/', [BiometricReprocessingController::class, 'index'])->name('index');
        Route::post('preview', [BiometricReprocessingController::class, 'preview'])->name('preview');
        Route::post('reprocess', [BiometricReprocessingController::class, 'reprocess'])->name('reprocess');
        Route::post('fix-statuses', [BiometricReprocessingController::class, 'fixStatuses'])->name('fix-statuses');
    });

    // Biometric Anomalies
    Route::prefix('biometric-anomalies')->name('biometric-anomalies.')->group(function () {
        Route::get('/', [BiometricAnomalyController::class, 'index'])->name('index');
        Route::post('detect', [BiometricAnomalyController::class, 'detect'])->name('detect');
    });

    // Biometric Export
    Route::prefix('biometric-export')->name('biometric-export.')->group(function () {
        Route::get('/', [BiometricExportController::class, 'index'])->name('index');
        Route::get('export', [BiometricExportController::class, 'export'])->name('export');
    });

    // Attendance Uploads
    Route::prefix('attendance-uploads')->name('attendance-uploads.')->group(function () {
        Route::get('/', [AttendanceUploadController::class, 'index'])->name('index');
        Route::get('/{upload}', [AttendanceUploadController::class, 'show'])->name('show');
    });

    // Attendance Points
    Route::prefix('attendance-points')->name('attendance-points.')->group(function () {
        Route::get('/', [AttendancePointController::class, 'index'])->name('index');
        Route::post('/rescan', [AttendancePointController::class, 'rescan'])->name('rescan');
        Route::get('/export-all', [AttendancePointController::class, 'exportAll'])->name('export-all');
        Route::get('/export-all-excel', [AttendancePointController::class, 'exportAllExcel'])->name('export-all-excel');
        Route::get('/{user}', [AttendancePointController::class, 'show'])->name('show');
        Route::get('/{user}/statistics', [AttendancePointController::class, 'statistics'])->name('statistics');
        Route::get('/{user}/export', [AttendancePointController::class, 'export'])->name('export');
        Route::get('/{user}/export-excel', [AttendancePointController::class, 'exportExcel'])->name('export-excel');
        Route::post('/{point}/excuse', [AttendancePointController::class, 'excuse'])->name('excuse');
        Route::delete('/{point}/unexcuse', [AttendancePointController::class, 'unexcuse'])->name('unexcuse');
    });

    // Biometric Retention Policies
    Route::prefix('biometric-retention-policies')->name('biometric-retention-policies.')->group(function () {
        Route::get('/', [BiometricRetentionPolicyController::class, 'index'])->name('index');
        Route::post('/', [BiometricRetentionPolicyController::class, 'store'])->name('store');
        Route::put('/{policy}', [BiometricRetentionPolicyController::class, 'update'])->name('update');
        Route::delete('/{policy}', [BiometricRetentionPolicyController::class, 'destroy'])->name('destroy');
        Route::post('/{policy}/toggle', [BiometricRetentionPolicyController::class, 'toggle'])->name('toggle');
    });
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';


