<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RetryController;
use App\Http\Controllers\DentolizeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::post('/webhooks/dentolize', DentolizeWebhookController::class);

Route::get('/admin/login', [AuthController::class, 'showLogin']);
Route::post('/admin/auth/login', [AuthController::class, 'login']);
Route::post('/admin/auth/logout', [AuthController::class, 'logout']);

Route::middleware('admin.auth')->group(function (): void {
    Route::get('/admin', [DashboardController::class, 'index']);
    Route::get('/admin/summary', [DashboardController::class, 'summary']);
    Route::get('/admin/items', [DashboardController::class, 'items']);
    Route::get('/admin/items/{syncMap}', [DashboardController::class, 'showItem']);

    Route::middleware('admin.operator')->group(function (): void {
        Route::post('/admin/items/{syncMap}/retry', RetryController::class);
        Route::post('/admin/reconcile/run', [DashboardController::class, 'runReconciliation']);
    });
});
