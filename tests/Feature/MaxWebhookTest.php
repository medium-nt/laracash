<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Реальный формат MAX update (снят с живого вебхука 2026-08-11).
function realMaxMessage(int $userId, string $text, int $chatId = 100, string $mid = 'mid.500'): array
{
    return [
        'update_type' => 'message_created',
        'timestamp' => 1786463484432,
        'message' => [
            'recipient' => ['chat_id' => $chatId, 'chat_type' => 'dialog', 'user_id' => 367005426],
            'body' => ['mid' => $mid, 'seq' => 1, 'text' => $text],
            'sender' => ['user_id' => $userId],
        ],
    ];
}

function realMaxCallback(int $userId, string $payload, int $chatId = 100, string $callbackId = 'cb1'): array
{
    return [
        'update_type' => 'message_callback',
        'callback' => [
            'callback_id' => $callbackId,
            'user' => ['user_id' => $userId],
            'payload' => $payload,
        ],
        'message' => ['recipient' => ['chat_id' => $chatId]],
    ];
}

beforeEach(function () {
    Role::firstOrCreate(['name' => 'client']);
    Role::firstOrCreate(['name' => 'admin']);

    config()->set('max.token', 'TEST_TOKEN');
    config()->set('max.webhook_secret', 'SECRET');
    Http::fake(['*platform-api2.max.ru/*' => Http::response(['message' => ['body' => ['mid' => 'mid.1']]])]);
});

test('webhook отклоняет запрос без секретного токена', function () {
    $this->postJson('/api/max/webhook', ['update_type' => 'message_created'])
        ->assertForbidden();
});

test('webhook отклоняет запрос с неверным секретом', function () {
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'WRONG'])
        ->postJson('/api/max/webhook', realMaxMessage(42, '/start'))
        ->assertForbidden();
});

test('webhook с пустым секретом в конфиге блокирует всё (fail-closed)', function () {
    config()->set('max.webhook_secret', null);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxMessage(42, '/start'))
        ->assertForbidden();
});

test('webhook с верным секретом делегирует message_created и возвращает 200', function () {
    User::factory()->create(['max_id' => '42']);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxMessage(42, '/start'))
        ->assertOk();

    // /start → sendMenu → отправлено сообщение-меню
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/messages'));
});

test('webhook обрабатывает message_callback: answerCallback + реальная ветка payload', function () {
    User::factory()->create(['max_id' => '42']);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxCallback(42, 'cmd:update'))
        ->assertOk();

    // ответ на callback (POST /answers) + cmd:update отработал (POST /messages — клавиатура карт)
    Http::assertSent(fn ($r) => str_contains($r->url(), '/answers'));
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/messages'));
});

test('webhook идемпотентен: повторный callback_id не обрабатывается повторно', function () {
    User::factory()->create(['max_id' => '42']);
    $payload = realMaxCallback(42, 'cmd:update', 100, 'cb-unique');

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])->postJson('/api/max/webhook', $payload)->assertOk();
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])->postJson('/api/max/webhook', $payload)->assertOk();

    // sendMessage вызван ровно 1 раз — второй запрос пропущен по идемпотентности callback_id
    $count = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/messages'))
        ->count();

    expect($count)->toBe(1);
});
