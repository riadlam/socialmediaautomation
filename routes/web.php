<?php

use App\Http\Controllers\ScriptMonitorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/scripts-monitor');
});

Route::get('/scripts-monitor', [ScriptMonitorController::class, 'index'])->name('scripts.monitor');
Route::post('/scripts-monitor', [ScriptMonitorController::class, 'store'])->name('scripts.monitor.store');
Route::post('/scripts-monitor/clear-logs', [ScriptMonitorController::class, 'clearLogs'])->name('scripts.monitor.clear-logs');
