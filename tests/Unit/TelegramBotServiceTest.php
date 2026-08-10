<?php

use App\Services\Telegram\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

test('downloadPhoto сохраняет файл и возвращает путь', function () {
    Storage::fake('local');

    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file.png']]),
        'api.telegram.org/file/*' => Http::response('binarycontent', 200),
    ]);
    config()->set('tg.token', 'TEST');

    $bot = new TelegramBotService;
    $path = $bot->downloadPhoto('FILE_ID');

    expect($path)->not->toBeNull()
        ->and(str_contains($path, 'temp/tg'))->toBeTrue();
});

test('sendMessage шлёт POST на sendMessage', function () {
    Http::fake(['api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);
    config()->set('tg.token', 'TEST');

    (new TelegramBotService)->sendMessage(123, 'hi');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage'));
});

test('deleteMessage шлёт POST на deleteMessage', function () {
    Http::fake(['api.telegram.org/bot*/deleteMessage' => Http::response(['ok' => true])]);
    config()->set('tg.token', 'TEST');

    (new TelegramBotService)->deleteMessage(123, 456);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/deleteMessage')
        && (int) ($r->data()['message_id'] ?? 0) === 456);
});

test('sendChatAction шлёт POST на sendChatAction', function () {
    Http::fake(['api.telegram.org/bot*/sendChatAction' => Http::response(['ok' => true])]);
    config()->set('tg.token', 'TEST');

    (new TelegramBotService)->sendChatAction(123);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendChatAction')
        && ($r->data()['action'] ?? null) === 'typing');
});

test('setMyCommands шлёт POST на setMyCommands с командами', function () {
    Http::fake(['api.telegram.org/bot*/setMyCommands' => Http::response(['ok' => true, 'result' => true])]);
    config()->set('tg.token', 'TEST');

    $result = (new TelegramBotService)->setMyCommands([['command' => 'menu', 'description' => 'Меню']]);

    expect($result)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/setMyCommands'));
});
