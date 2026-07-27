<?php

use App\Http\Controllers\DentolizeWebhookController;
use App\Http\Controllers\DentolizeWebhookReprocessController;
use App\Http\Controllers\DentolizeWebhookStatusController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/dentolize', DentolizeWebhookController::class);
Route::get('/webhooks/dentolize/status', DentolizeWebhookStatusController::class);
Route::post('/webhooks/dentolize/reprocess', DentolizeWebhookReprocessController::class);
