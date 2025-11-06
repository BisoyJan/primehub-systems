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
use App\Http\Controllers\AttendanceImportController;
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

    // Attendance
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/import', [AttendanceImportController::class, 'create'])->name('attendance.import');
    Route::post('attendance/import', [AttendanceImportController::class, 'store'])->name('attendance.import.store');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';


