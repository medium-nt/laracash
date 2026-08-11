<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('команда max:setwebhook зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('max:setwebhook');
});

test('max:setwebhook подписывается через POST /subscriptions с url/secret/update_types', function () {
    Http::fake(['*platform-api2.max.ru/*' => Http::response(['success' => true], 200)]);
    config()->set('max.token', 'TEST');
    config()->set('max.webhook_secret', 'SECRET');
    config()->set('app.url', 'https://max.example.com');

    $exit = Artisan::call('max:setwebhook');

    expect($exit)->toBe(0);

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/subscriptions')) {
            return false;
        }
        $body = json_decode($r->body(), true);

        return ($body['url'] ?? null) === 'https://max.example.com/api/max/webhook'
            && ($body['secret'] ?? null) === 'SECRET'
            && in_array('message_created', $body['update_types'] ?? [], true)
            && in_array('message_callback', $body['update_types'] ?? [], true);
    });
});

test('max:setwebhook принудительно поднимает http APP_URL до https', function () {
    Http::fake(['*platform-api2.max.ru/*' => Http::response(['success' => true], 200)]);
    config()->set('max.token', 'TEST');
    config()->set('max.webhook_secret', 'SECRET');
    config()->set('app.url', 'http://max.example.com');

    Artisan::call('max:setwebhook');

    Http::assertSent(function ($r) {
        $body = json_decode($r->body(), true);

        return str_starts_with(($body['url'] ?? ''), 'https://max.example.com');
    });
});

test('max:setwebhook падает без конфигурации и не шлёт запрос', function () {
    config()->set('max.token', null);
    config()->set('max.webhook_secret', null);
    config()->set('app.url', 'http://localhost');

    expect(Artisan::call('max:setwebhook'))->toBe(1);

    Http::assertNothingSent();
});
