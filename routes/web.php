<?php

use App\Http\Controllers\RamSpecsController;
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

    // Specific routes for RAM specs
    // Route::get('ramspecs', [RamSpecsController::class, 'index'])->name('ramspecs.index');
    // Route::get('ramspecs/create', [RamSpecsController::class, 'create'])->name('ramspecs.create');
    // Route::post('ramspecs', [RamSpecsController::class, 'store'])->name('ramspecs.store');
    // Route::get('ramspecs/{ramSpec}/edit', [RamSpecsController::class, 'edit'])->name('ramspecs.edit');
    // Route::put('ramspecs/{ramSpec}', [RamSpecsController::class, 'update'])->name('ramspecs.update');
    // Route::delete('ramspecs/{ramSpec}', [RamSpecsController::class, 'destroy'])->name('ramspecs.destroy');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';
