<?php

Route::get('/test', fn() => 'ok');
Route::post('/telegram/webhook', [App\Http\Controllers\TelegramWebhookController::class, 'handle']);

Route::post('/mini-app-data', [App\Http\Controllers\MiniAppController::class, 'handle']);
