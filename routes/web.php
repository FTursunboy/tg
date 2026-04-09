<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response('Telegram bot is running', 200);
});

Route::post('/telegram/webhook/{secret?}', [TelegramWebhookController::class, 'handle']);
