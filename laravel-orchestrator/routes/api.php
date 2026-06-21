<?php

use App\Http\Controllers\Api\AiCallbackController;
use App\Http\Controllers\Api\AiTaskController;
use App\Http\Controllers\Api\AutomotiveController;
use Illuminate\Support\Facades\Route;

Route::post('/ai-tasks', [AiTaskController::class, 'store'])->name('ai-tasks.store');

Route::get('/ai-tasks/{taskUuid}', [AiTaskController::class, 'show'])
    ->where('taskUuid', '[0-9a-fA-F-]{36}')
    ->name('ai-tasks.show');

Route::post('/ai-callback/{taskUuid}', [AiCallbackController::class, 'store'])
    ->where('taskUuid', '[0-9a-fA-F-]{36}')
    ->name('ai-callback.store');

// ---- Automotive domain ----
Route::post('/vehicles', [AutomotiveController::class, 'registerVehicle'])->name('vehicles.register');

Route::post('/diagnostics', [AutomotiveController::class, 'startDiagnostic'])->name('diagnostics.start');

Route::get('/vehicles/{vin}/alerts', [AutomotiveController::class, 'alertsForVehicle'])
    ->where('vin', '[A-HJ-NPR-Z0-9]{17}')
    ->name('vehicles.alerts');

Route::patch('/alerts/{alertUuid}', [AutomotiveController::class, 'updateAlert'])
    ->where('alertUuid', '[0-9a-fA-F-]{36}')
    ->name('alerts.update');
