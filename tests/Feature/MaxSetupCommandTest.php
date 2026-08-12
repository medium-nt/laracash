<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('команда max:setup зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('max:setup');
});

test('max:setup вызывает PATCH /me/commands только с /menu (поле name)', function () {
    Http::fake(['*platform-api2.max.ru/*' => Http::response(null, 200)]);
    config()->set('max.token', 'TEST');

    Artisan::call('max:setup');

    Http::assertSent(function ($r) {
        if ($r->method() !== 'PATCH' || ! str_contains($r->url(), '/me/commands')) {
            return false;
        }
        $commands = json_decode($r->body(), true)['commands'] ?? null;

        return is_array($commands)
            && collect($commands)->contains(fn ($c) => ($c['name'] ?? null) === '/menu')
            && ! collect($commands)->contains(fn ($c) => ($c['name'] ?? null) === '/start');
    });
});

test('max:setup возвращает FAILURE при ошибке API', function () {
    Http::fake(['*platform-api2.max.ru/*' => Http::response(null, 500)]);
    config()->set('max.token', 'TEST');

    expect(Artisan::call('max:setup'))
        ->toBe(\Symfony\Component\Console\Command\Command::FAILURE);
});
