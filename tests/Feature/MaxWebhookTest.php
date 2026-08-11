<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'client']);
    Role::firstOrCreate(['name' => 'admin']);

    config()->set('max.token', 'TEST_TOKEN');
    config()->set('max.webhook_secret', 'SECRET');
    Http::fake(['*platform-api2.max.ru/*' => Http::response(['message' => ['message_id' => 1]])]);
});

test('webhook отклоняет запрос без секретного токена', function () {
    $this->postJson('/api/max/webhook', ['update_type' => 'message_created'])
        ->assertForbidden();
});

test('webhook отклоняет запрос с неверным секретом', function () {
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'WRONG'])
        ->postJson('/api/max/webhook', ['update_type' => 'message_created'])
        ->assertForbidden();
});

test('webhook с пустым секретом в конфиге блокирует всё (fail-closed)', function () {
    config()->set('max.webhook_secret', null);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', ['update_type' => 'message_created'])
        ->assertForbidden();
});

test('webhook с верным секретом делегирует в conversation и возвращает 200', function () {
    User::factory()->create(['max_id' => '42']);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', [
            'update_type' => 'message_created',
            'chat_id' => 100,
            'user' => ['user_id' => 42],
            'message' => ['message_id' => 1, 'body' => ['text' => '/start']],
        ])
        ->assertOk();

    // Сообщение отправлено в MAX (меню)
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/messages'));
});

test('webhook с message_callback отвечает через /answers', function () {
    User::factory()->create(['max_id' => '42']);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', [
            'update_type' => 'message_callback',
            'chat_id' => 100,
            'user' => ['user_id' => 42],
            'callback_id' => 'cb1',
            'payload' => 'cmd:update',
        ])
        ->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/answers'));
});

test('webhook идемпотентен: повторный message_id не обрабатывается повторно', function () {
    User::factory()->create(['max_id' => '42']);

    $payload = [
        'update_type' => 'message_created',
        'chat_id' => 100,
        'user' => ['user_id' => 42],
        'message' => ['message_id' => 999, 'body' => ['text' => '/start']],
    ];

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])->postJson('/api/max/webhook', $payload)->assertOk();
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])->postJson('/api/max/webhook', $payload)->assertOk();

    // sendMessage (меню) вызван ровно 1 раз — второй запрос пропущен по идемпотентности
    $count = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/messages'))
        ->count();

    expect($count)->toBe(1);
});
