<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('команда telegram:setup зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('telegram:setup');
});

test('telegram:setup вызывает setMyCommands с /menu и /start', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    config()->set('tg.token', 'TEST');

    Artisan::call('telegram:setup');

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/setMyCommands')) {
            return false;
        }
        $commands = $r->data()['commands'] ?? null;

        return is_array($commands)
            && collect($commands)->contains(fn ($c) => ($c['command'] ?? null) === 'menu')
            && collect($commands)->contains(fn ($c) => ($c['command'] ?? null) === 'start');
    });
});
