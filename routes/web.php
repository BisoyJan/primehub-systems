<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ProcessorSpecsController;
use App\Http\Controllers\DiskSpecsController;
use App\Http\Controllers\RamSpecsController;
use App\Http\Controllers\PcSpecController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
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
    Route::resource('pcspecs', PcSpecController::class)
        ->except(['show']);
    Route::resource('sites', SiteController::class)
        ->except(['show']);
    Route::resource('stations', StationController::class)
        ->except(['show']);
    Route::resource('campaigns', CampaignController::class)
        ->except(['show']);
    Route::resource('stocks', StockController::class);
    Route::post('stocks/adjust', [StockController::class, 'adjust'])
        ->name('stocks.adjust');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';
