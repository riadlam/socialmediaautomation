<?php

use App\Http\Controllers\ScriptMonitorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/scripts-monitor');
});

Route::get('/scripts-monitor', [ScriptMonitorController::class, 'index'])->name('scripts.monitor');
