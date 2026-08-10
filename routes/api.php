<?php

Route::get('/test', fn () => 'ok');

// Telegram webhook (без CSRF middleware, API-маршрут)
Route::post('/telegram/webhook', \App\Http\Controllers\TelegramWebhookController::class);

Route::post('/mini-app-data', [App\Http\Controllers\MiniAppController::class, 'handle']);
