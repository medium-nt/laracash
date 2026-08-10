<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Сборка мусора: файлы скринов без связи с картой (брошенные/истёкшие сессии бота)
Schedule::command('cashback-image:gc')->hourly();
