<?php

use App\Http\Controllers\DentolizeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/dentolize', DentolizeWebhookController::class);
