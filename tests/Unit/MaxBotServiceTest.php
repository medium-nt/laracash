<?php

use App\Services\Max\MaxBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

beforeEach(function () {
    config()->set('max.token', 'TEST');
    config()->set('max.api_base', 'https://platform-api2.max.ru');
});

test('downloadPhoto сохраняет файл и возвращает путь', function () {
    Storage::fake('local');

    Http::fake([
        'example.com/*' => Http::response('binarycontent', 200),
    ]);

    $path = (new MaxBotService)->downloadPhoto('https://example.com/img.jpg');

    expect($path)->not->toBeNull()
        ->and(str_contains($path, 'temp/max'))->toBeTrue();
});

test('downloadPhoto конвертирует WebP в JPEG (GigaChat не принимает WebP)', function () {
    Storage::fake('local');

    // Генерируем валидный WebP через GD
    $im = imagecreatetruecolor(10, 10);
    ob_start();
    imagewebp($im);
    $webp = ob_get_clean();
    imagedestroy($im);

    Http::fake([
        'example.com/*' => Http::response($webp, 200),
    ]);

    $path = (new MaxBotService)->downloadPhoto('https://example.com/img.webp');

    expect($path)->not->toBeNull();
    // Файл должен стать JPEG (магические байты FF D8 FF), а не остаться WebP (RIFF)
    $head = file_get_contents($path, false, null, 0, 3);
    expect(str_starts_with($head, "\xFF\xD8\xFF"))->toBeTrue();
});

test('sendMessage шлёт POST на /messages с chat_id в query и возвращает mid', function () {
    Http::fake([
        'platform-api2.max.ru/messages*' => Http::response(['message' => ['body' => ['mid' => 'mid.000042']]]),
    ]);

    $id = (new MaxBotService)->sendMessage(123, 'hi');

    expect($id)->toBe('mid.000042');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
        && str_contains($r->url(), 'chat_id=123'));
});

test('sendMessage c клавиатурой вкладывает inline_keyboard в attachments', function () {
    Http::fake([
        'platform-api2.max.ru/messages*' => Http::response(['message' => ['body' => ['mid' => 'mid.1']]]),
    ]);

    (new MaxBotService)->sendMessage(1, 'x', [[['type' => 'callback', 'text' => 'OK', 'payload' => 'go']]]);

    Http::assertSent(function ($r) {
        $body = json_decode($r->body(), true);

        return $body['attachments'][0]['type'] === 'inline_keyboard'
            && isset($body['attachments'][0]['payload']['buttons']);
    });
});

test('editMessageText шлёт PUT на /messages', function () {
    Http::fake([
        'platform-api2.max.ru/messages*' => Http::response(['message' => ['body' => ['mid' => 'mid.1']]]),
    ]);

    (new MaxBotService)->editMessageText(123, 456, 'edited');

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/messages')
        && str_contains($r->url(), 'message_id=456'));
});

test('deleteMessage шлёт DELETE на /messages с message_id', function () {
    Http::fake([
        'platform-api2.max.ru/messages*' => Http::response(null, 200),
    ]);

    (new MaxBotService)->deleteMessage(123, 456);

    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && str_contains($r->url(), '/messages')
        && str_contains($r->url(), 'message_id=456'));
});

test('answerCallback шлёт POST на /answers', function () {
    Http::fake([
        'platform-api2.max.ru/answers*' => Http::response(null, 200),
    ]);

    (new MaxBotService)->answerCallback('cb1', 'done');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/answers'));
});

test('setMyCommands шлёт PATCH на /me/commands и возвращает bool', function () {
    Http::fake([
        'platform-api2.max.ru/me/commands*' => Http::response(null, 200),
    ]);

    $result = (new MaxBotService)->setMyCommands([['command' => 'menu', 'description' => 'Меню']]);

    expect($result)->toBeTrue();
    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/me/commands'));
});

test('sendMessage шлёт header Authorization с токеном', function () {
    Http::fake([
        'platform-api2.max.ru/messages*' => Http::response(['message' => ['body' => ['mid' => 'mid.1']]]),
    ]);

    (new MaxBotService)->sendMessage(1, 'x');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'TEST'));
});
