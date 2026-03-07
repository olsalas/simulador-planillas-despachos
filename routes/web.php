<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Ingestion\BatchController;
use App\Http\Controllers\Ingestion\UploadCsvController;
use App\Http\Controllers\SimulationController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::middleware('can:upload-csv')->group(function () {
        Route::get('/dashboard/upload-csv', [UploadCsvController::class, 'create'])->name('ingestion.upload');
        Route::post('/dashboard/upload-csv', [UploadCsvController::class, 'store'])->name('ingestion.upload.store');
    });

    Route::get('/dashboard/batches', [BatchController::class, 'index'])->name('ingestion.batches');
    Route::get('/dashboard/batches/{routeBatch}', [BatchController::class, 'show'])->name('ingestion.batches.show');

    Route::get('/dashboard/simulate', [SimulationController::class, 'index'])->name('simulation.run');
    Route::post('/dashboard/simulate/preview', [SimulationController::class, 'preview'])->name('simulation.preview');
    Route::post('/dashboard/simulate/compare', [SimulationController::class, 'compare'])->name('simulation.compare');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
