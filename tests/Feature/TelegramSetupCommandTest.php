<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('команда telegram:setup зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('telegram:setup');
});

test('telegram:setup вызывает setMyCommands только с /menu', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(null, 200)]);
    config()->set('tg.token', 'TEST');

    Artisan::call('telegram:setup');

    Http::assertSent(function ($r) {
        if ($r->method() !== 'POST' || ! str_contains($r->url(), '/setMyCommands')) {
            return false;
        }
        $commands = $r->data()['commands'] ?? null;

        return is_array($commands)
            && collect($commands)->contains(fn ($c) => ($c['command'] ?? null) === 'menu')
            && ! collect($commands)->contains(fn ($c) => ($c['command'] ?? null) === 'start');
    });
});

test('telegram:setup возвращает FAILURE при ошибке API', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(null, 500)]);
    config()->set('tg.token', 'TEST');

    expect(Artisan::call('telegram:setup'))
        ->toBe(\Symfony\Component\Console\Command\Command::FAILURE);
});
