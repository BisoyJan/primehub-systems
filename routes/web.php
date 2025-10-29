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
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');


Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('dashboard', fn() => Inertia::render('dashboard'))->name('dashboard');

    // Hardware Specs
    Route::resource('ramspecs', RamSpecsController::class)->except(['show']);
    Route::resource('diskspecs', DiskSpecsController::class)->except(['show']);
    Route::resource('processorspecs', ProcessorSpecsController::class)->except(['show']);
    Route::resource('monitorspecs', MonitorSpecsController::class)->except(['show']);

    // PC Specs
    Route::patch('pcspecs/{pcspec}/issue', [PcSpecController::class, 'updateIssue'])->name('pcspecs.updateIssue');
    Route::resource('pcspecs', PcSpecController::class);

    // QR Code ZIP features
    Route::post('/pcspecs/qrcode/zip-selected', [PcSpecController::class, 'zipSelected']);
    Route::post('/pcspecs/qrcode/bulk-all', [PcSpecController::class, 'bulkAll']);
    Route::get('/pcspecs/qrcode/bulk-progress/{jobId}', [PcSpecController::class, 'bulkProgress']);
    Route::get('/pcspecs/qrcode/zip/{jobId}/download', [PcSpecController::class, 'downloadZip'])->name('pcspecs.qrcode.zip.download');
    Route::get('/pcspecs/qrcode/selected-progress/{jobId}', [PcSpecController::class, 'selectedZipProgress']);
    Route::get('/pcspecs/qrcode/selected-zip/{jobId}/download', [PcSpecController::class, 'downloadSelectedZip'])->name('pcspecs.qrcode.selected.download');

    // Sites & Campaigns
    Route::resource('sites', SiteController::class)->except(['show']);
    Route::resource('campaigns', CampaignController::class)->except(['show']);

    // Stations
    Route::post('stations/bulk', [StationController::class, 'storeBulk'])->name('stations.bulk');
    Route::resource('stations', StationController::class)->except(['show']);

    // Stocks
    Route::resource('stocks', StockController::class);
    Route::post('stocks/adjust', [StockController::class, 'adjust'])->name('stocks.adjust');

    // Accounts
    Route::resource('accounts', AccountController::class)->except(['show']);

    // PC Transfer
    Route::prefix('pc-transfers')->group(function () {
        Route::get('/', [PcTransferController::class, 'index'])->name('pc-transfers.index');
        Route::get('/transfer/{station?}', [PcTransferController::class, 'transferPage'])->name('pc-transfers.transferPage');
        Route::post('/', [PcTransferController::class, 'transfer'])->name('pc-transfers.transfer');
        Route::post('/bulk', [PcTransferController::class, 'bulkTransfer'])->name('pc-transfers.bulk');
        Route::delete('/remove', [PcTransferController::class, 'remove'])->name('pc-transfers.remove');
        Route::get('/history', [PcTransferController::class, 'history'])->name('pc-transfers.history');
    });

    // PC Maintenance
    Route::resource('pc-maintenance', PcMaintenanceController::class);
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';


