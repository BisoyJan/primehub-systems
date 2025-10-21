<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ProcessorSpecsController;
use App\Http\Controllers\DiskSpecsController;
use App\Http\Controllers\RamSpecsController;
use App\Http\Controllers\PcSpecController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\PcTransferController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::resource('ramspecs', RamSpecsController::class)
        ->except(['show']);
    Route::resource('diskspecs', DiskSpecsController::class)
        ->except(['show']);
    Route::resource('processorspecs', ProcessorSpecsController::class)
        ->except(['show']);
    Route::patch('pcspecs/{pcspec}/issue', [PcSpecController::class, 'updateIssue'])
        ->name('pcspecs.updateIssue');
    Route::resource('pcspecs', PcSpecController::class)
        ->except(['show']);
    Route::resource('sites', SiteController::class)
        ->except(['show']);
    Route::post('stations/bulk', [StationController::class, 'storeBulk'])
        ->name('stations.bulk');
    Route::resource('stations', StationController::class)
        ->except(['show']);
    Route::resource('campaigns', CampaignController::class)
        ->except(['show']);
    Route::resource('stocks', StockController::class);
    Route::post('stocks/adjust', [StockController::class, 'adjust'])
        ->name('stocks.adjust');
    Route::resource('accounts', AccountController::class)
        ->except(['show']);

    // PC Transfer routes
    Route::get('pc-transfers', [PcTransferController::class, 'index'])
        ->name('pc-transfers.index');
    // Dedicated transfer page (table-based PC selector) - station is optional for bulk mode
    Route::get('pc-transfers/transfer/{station?}', [PcTransferController::class, 'transferPage'])
        ->name('pc-transfers.transferPage');
    Route::post('pc-transfers', [PcTransferController::class, 'transfer'])
        ->name('pc-transfers.transfer');
    Route::post('pc-transfers/bulk', [PcTransferController::class, 'bulkTransfer'])
        ->name('pc-transfers.bulk');
    Route::delete('pc-transfers/remove', [PcTransferController::class, 'remove'])
        ->name('pc-transfers.remove');
    Route::get('pc-transfers/history', [PcTransferController::class, 'history'])
        ->name('pc-transfers.history');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';
