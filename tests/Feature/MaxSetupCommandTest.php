<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('команда max:setup зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('max:setup');
});

test('max:setup вызывает PATCH /me/commands с /menu и /start', function () {
    Http::fake(['*platform-api2.max.ru/*' => Http::response(null, 200)]);
    config()->set('max.token', 'TEST');

    Artisan::call('max:setup');

    Http::assertSent(function ($r) {
        if ($r->method() !== 'PATCH' || ! str_contains($r->url(), '/me/commands')) {
            return false;
        }
        $commands = json_decode($r->body(), true)['commands'] ?? null;

        return is_array($commands)
            && collect($commands)->contains(fn ($c) => ($c['command'] ?? null) === 'menu')
            && collect($commands)->contains(fn ($c) => ($c['command'] ?? null) === 'start');
    });
});
