<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('команда telegram:setwebhook зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('telegram:setwebhook');
});

test('telegram:setwebhook вызывает setWebhook с url и secret_token', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true, 'result' => true, 'description' => 'Webhook was set'])]);
    config()->set('tg.token', 'TEST');
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('app.url', 'https://laracash.example.com');

    $exit = Artisan::call('telegram:setwebhook');

    expect($exit)->toBe(0);

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/setWebhook')) {
            return false;
        }
        $data = $r->data();

        return ($data['url'] ?? null) === 'https://laracash.example.com/api/telegram/webhook'
            && ($data['secret_token'] ?? null) === 'SECRET';
    });
});

test('telegram:setwebhook принудительно поднимает http APP_URL до https', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    config()->set('tg.token', 'TEST');
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('app.url', 'http://laracash.example.com');

    Artisan::call('telegram:setwebhook');

    Http::assertSent(function ($r) {
        return str_contains($r->url(), '/setWebhook')
            && str_starts_with(($r->data()['url'] ?? ''), 'https://laracash.example.com');
    });
});

test('telegram:setwebhook падает без конфигурации и не шлёт запрос', function () {
    config()->set('tg.token', null);
    config()->set('tg.webhook_secret', null);
    config()->set('app.url', 'http://localhost');

    expect(Artisan::call('telegram:setwebhook'))->toBe(1);

    Http::assertNothingSent();
});
