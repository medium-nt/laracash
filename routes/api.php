<?php

Route::get('/test', fn() => 'ok');
Route::post('/telegram/webhook', [App\Http\Controllers\TelegramWebhookController::class, 'handle']);
