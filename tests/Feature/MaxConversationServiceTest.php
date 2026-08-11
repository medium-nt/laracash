<?php

use App\Models\Bank;
use App\Models\Card;
use App\Models\Role;
use App\Models\User;
use App\Services\Bot\MaxConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'client']);
    Role::firstOrCreate(['name' => 'admin']);
    Http::fake([
        '*platform-api2.max.ru/*' => Http::response(['message' => ['body' => ['mid' => 'mid.1']]]),
    ]);
    config()->set('max.token', 'TEST');
    config()->set('app.name', 'Y-Cashback');
});

// Хелперы фикстур MAX-формата (реальная структура, снятая с живого API)
// message_created: chat_id в message.recipient.chat_id, автор в message.sender.user_id
function maxMessage(int $userId, string $text, int $chatId = 100, int $messageId = 500): array
{
    return [
        'update_type' => 'message_created',
        'message' => [
            'message_id' => $messageId,
            'recipient' => ['chat_id' => $chatId, 'chat_type' => 'dialog'],
            'body' => ['text' => $text],
            'sender' => ['user_id' => $userId],
        ],
    ];
}

// message_callback: callback_id/user/payload внутри callback.*, chat_id в message.recipient.chat_id
function maxCallback(int $userId, string $payload, int $chatId = 100, string $callbackId = 'cb1'): array
{
    return [
        'update_type' => 'message_callback',
        'callback' => [
            'callback_id' => $callbackId,
            'user' => ['user_id' => $userId],
            'payload' => $payload,
        ],
        'message' => [
            'recipient' => ['chat_id' => $chatId],
        ],
    ];
}

test('/start (bot_started) от привязанного юзера устанавливает idle', function () {
    User::factory()->create(['max_id' => '42', 'name' => 'Иван']);

    app(MaxConversationService::class)->handle([
        'update_type' => 'bot_started',
        'chat_id' => 100,
        'user' => ['user_id' => 42],
    ]);

    $state = Cache::get('bot.state.max.42');
    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('idle');
});

test('/start текстом от привязанного юзера устанавливает idle', function () {
    User::factory()->create(['max_id' => '42', 'name' => 'Иван']);

    app(MaxConversationService::class)->handle(maxMessage(42, '/start'));

    expect(Cache::get('bot.state.max.42')['name'])->toBe('idle');
});

test('сообщение от НЕ привязанного юзера шлёт ссылку привязки', function () {
    app(MaxConversationService::class)->handle(maxMessage(999, '/start'));

    expect(Cache::get('bot.state.max.999'))->toBeNull();
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/messages')
        && str_contains($r->data()['text'] ?? '', '/profile/max-link?max=999'));
});

test('callback cmd:update без карт устанавливает idle', function () {
    User::factory()->create(['max_id' => '42']);

    app(MaxConversationService::class)->handle(maxCallback(42, 'cmd:update'));

    expect(Cache::get('bot.state.max.42')['name'])->toBe('idle');
});

test('callback cmd:update с картами устанавливает await_card', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Сбербанк']);
    Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '1234', 'color' => '#000000', 'cashback_json' => null]);

    app(MaxConversationService::class)->handle(maxCallback(42, 'cmd:update'));

    expect(Cache::get('bot.state.max.42')['name'])->toBe('await_card');
});

test('callback с выбором карты переводит в await_photo', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Тинькофф']);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5678', 'color' => '#000000', 'cashback_json' => null]);

    app(MaxConversationService::class)->handle(maxCallback(42, 'card:'.$card->id));

    $state = Cache::get('bot.state.max.42');
    expect($state['name'])->toBe('await_photo')
        ->and($state['card_id'])->toBe($card->id);
});

test('callback с чужим card_id шлёт «Карта не найдена» и не меняет state', function () {
    $userA = User::factory()->create(['max_id' => '1']);
    $userB = User::factory()->create(['max_id' => '2']);
    $bankB = Bank::create(['user_id' => $userB->id, 'title' => 'Тинькофф']);
    $cardB = Card::create(['user_id' => $userB->id, 'bank_id' => $bankB->id, 'number' => '2222', 'color' => '#000000', 'cashback_json' => null]);

    Cache::put('bot.state.max.1', ['name' => 'idle'], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(1, 'card:'.$cardB->id));

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/messages')
        && str_contains($r->data()['text'] ?? '', 'Карта не найдена'));
    expect(Cache::get('bot.state.max.1')['name'])->toBe('idle');
});

test('callback merge применяет кешбэк из items', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '9999', 'color' => '#000000', 'cashback_json' => null]);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
            ['title' => 'Супермаркеты', 'percent' => 3.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'merge'));

    $card->refresh();
    expect($card->cashback_json)->toBeArray()
        ->and($card->cashback_json[0]['category'])->toBe('Аптеки')
        ->and((float) $card->cashback_json[0]['cashback'])->toBe(5.0);
});

test('callback cancel возвращает в idle', function () {
    User::factory()->create(['max_id' => '42']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'cancel'));

    expect(Cache::get('bot.state.max.42')['name'])->toBe('idle');
});

test('parseItem парсит название с пробелами и процент', function () {
    expect(MaxConversationService::parseItem('Аптеки 5'))->toBe(['title' => 'Аптеки', 'percent' => 5.0])
        ->and(MaxConversationService::parseItem('Кафе и рестораны 3.5'))->toBe(['title' => 'Кафе и рестораны', 'percent' => 3.5])
        ->and(MaxConversationService::parseItem('Кино'))->toBeNull()
        ->and(MaxConversationService::parseItem(''))->toBeNull();
});

test('callback del удаляет пункт и edit-ит редактор', function () {
    User::factory()->create(['max_id' => '42']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
            ['title' => 'Кафе', 'percent' => 3.0],
            ['title' => 'Кино', 'percent' => 10.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'del:1'));

    $state = Cache::get('bot.state.max.42');
    expect($state['items'])->toHaveCount(2)
        ->and($state['items'][0]['title'])->toBe('Аптеки')
        ->and($state['items'][1]['title'])->toBe('Кино');

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/messages'));
});

test('callback edit переводит в await_edit', function () {
    User::factory()->create(['max_id' => '42']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'edit:0'));

    $state = Cache::get('bot.state.max.42');
    expect($state['name'])->toBe('await_edit')
        ->and($state['index'])->toBe(0);
});

test('сообщение в await_edit обновляет элемент', function () {
    User::factory()->create(['max_id' => '42']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_edit',
        'index' => 0,
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxMessage(42, 'Супермаркеты 7'));

    $state = Cache::get('bot.state.max.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'][0]['title'])->toBe('Супермаркеты')
        ->and($state['items'][0]['percent'])->toBe(7.0);
});

test('сообщение в await_add добавляет элемент', function () {
    User::factory()->create(['max_id' => '42']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_add',
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxMessage(42, 'Кафе и рестораны 3.5'));

    $state = Cache::get('bot.state.max.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'])->toHaveCount(2)
        ->and($state['items'][1]['title'])->toBe('Кафе и рестораны')
        ->and($state['items'][1]['percent'])->toBe(3.5);
});

test('сообщение с неверным форматом возвращает ошибку', function () {
    User::factory()->create(['max_id' => '42']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_edit',
        'index' => 0,
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxMessage(42, 'неправильный формат'));

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/messages')
        && str_contains($r->data()['text'] ?? '', 'Не понял формат'));
    expect(Cache::get('bot.state.max.42')['name'])->toBe('await_edit');
});

test('callback replace удаляет старые категории и записывает новые', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Сбербанк']);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '1111', 'color' => '#000000', 'cashback_json' => null]);
    $category = \App\Models\Category::create(['user_id' => $user->id, 'title' => 'Аптеки', 'keywords' => 'аптека', 'icon' => '', 'color' => '#000000']);
    \App\Models\Cashback::create(['card_id' => $card->id, 'category_id' => $category->id, 'cashback_percentage' => 3.0, 'mcc' => '']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'replace'));

    expect(\App\Models\Cashback::where('card_id', $card->id)->where('cashback_percentage', 3.0)->count())->toBe(0);
    $card->refresh();
    expect((float) $card->cashback_json[0]['cashback'])->toBe(5.0);
});

test('callback merge НЕ удаляет старые категории', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Тинькофф']);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '2222', 'color' => '#000000', 'cashback_json' => null]);
    $category1 = \App\Models\Category::create(['user_id' => $user->id, 'title' => 'Кафе', 'keywords' => 'кафе', 'icon' => '', 'color' => '#000000']);
    \App\Models\Cashback::create(['card_id' => $card->id, 'category_id' => $category1->id, 'cashback_percentage' => 3.0, 'mcc' => '']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'merge'));

    $old = \App\Models\Cashback::where('card_id', $card->id)->where('category_id', $category1->id)->first();
    expect($old)->not->toBeNull()
        ->and($old->cashback_percentage)->toBe(3.0);
});

test('buildEditorKeyboard использует MAX-формат кнопок (type=callback, payload)', function () {
    $service = app(MaxConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');
    $kb = $method->invoke($service, [
        ['title' => 'Аптеки', 'percent' => 5.0],
        ['title' => 'Кафе', 'percent' => 3.0],
    ]);

    $buttons = collect($kb)->flatten(1)->keyBy('payload');

    expect($buttons->has('add'))->toBeTrue()
        ->and($buttons->has('merge'))->toBeTrue()
        ->and($buttons->has('replace'))->toBeTrue()
        ->and($buttons->has('cancel'))->toBeTrue()
        ->and($buttons->get('add')['type'])->toBe('callback')
        ->and($buttons->get('merge')['text'])->toBe('💾 Сохранить (добавить к старым)');
});

test('buildEditorKeyboard: «Добавить категорию» идёт первой строкой', function () {
    $service = app(MaxConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');
    $kb = $method->invoke($service, [
        ['title' => 'Аптеки', 'percent' => 5.0, 'category_id' => 1],
    ]);

    expect($kb[0][0]['payload'] ?? null)->toBe('add');
});

test('await_edit: transient-промпт удалён, сообщение юзера НЕ тронуто (MAX)', function () {
    User::factory()->create(['max_id' => '42']);

    Cache::put('bot.state.max.42', [
        'name' => 'await_edit',
        'index' => 0,
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
        'last_bot_msg' => 7,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxMessage(42, 'Кафе 3', 100, 99));

    // transient бота (7) удалён
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'message_id=7'));
    // сообщение юзера (99) НЕ удалено — MAX не позволяет
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'message_id=99'));
    // редактор edit-ится
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/messages'));

    $state = Cache::get('bot.state.max.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'][0]['title'])->toBe('Кафе')
        ->and($state['last_bot_msg'])->toBeNull();
});

test('повторный transient удаляет предыдущее сообщение бота', function () {
    User::factory()->create(['max_id' => '42', 'name' => 'Тест']);
    $svc = app(MaxConversationService::class);

    $svc->handle(maxMessage(42, '/start', 100, 501));
    $svc->handle(maxMessage(42, '/menu', 100, 502));

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'message_id=mid.1'));
});

test('текстовое сообщение пользователя НЕ удаляется (ограничение MAX)', function () {
    User::factory()->create(['max_id' => '42', 'name' => 'Тест']);

    app(MaxConversationService::class)->handle(maxMessage(42, '/menu', 100, 333));

    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'message_id=333'));
});

test('при сохранении редактор удалён, скрин пользователя НЕ удалён (MAX)', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '9999', 'color' => '#000000', 'cashback_json' => null]);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
        'photo_msg_id' => 777,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'merge'));

    // редактор (msg_id=1) удалён
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'message_id=1'));
    // скрин юзера (777) НЕ удалён
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'message_id=777'));
});

test('buildEditorText помечает существующие ✅ и новые 🆕', function () {
    $service = app(MaxConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorText');
    $text = $method->invoke($service, [
        ['title' => 'Супермаркеты', 'percent' => 5.0, 'category_id' => 1],
        ['title' => 'Доставка еды', 'percent' => 7.0, 'category_id' => null],
    ]);

    expect($text)->toContain('✅ Супермаркеты — 5%')
        ->and($text)->toContain('🆕 Доставка еды — 7%');
});

test('callback merge создаёт недостающую категорию и сообщает о ней', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '9999', 'color' => '#000000', 'cashback_json' => null]);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [['title' => 'Доставка еды', 'percent' => 7.0, 'category_id' => null]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'merge'));

    expect(\App\Models\Category::query()->where('user_id', $user->id)->where('title', 'Доставка еды')->exists())->toBeTrue();
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/messages')
        && str_contains($r->data()['text'] ?? '', '1 нов.: Доставка еды'));
});

test('buildEditorText экранирует спецсимволы (защита HTML)', function () {
    $service = app(MaxConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorText');
    $text = $method->invoke($service, [
        ['title' => 'Кафе & Рестораны <b>x</b>', 'percent' => 5.0, 'category_id' => 1],
    ]);

    expect($text)->toContain('Кафе &amp; Рестораны &lt;b&gt;x&lt;/b&gt;')
        ->and($text)->not->toContain('<b>');
});

test('фото вне состояния await_photo даёт подсказку и НЕ удаляется (MAX)', function () {
    User::factory()->create(['max_id' => '42']);

    app(MaxConversationService::class)->handle([
        'update_type' => 'message_created',
        'message' => [
            'message_id' => 555,
            'recipient' => ['chat_id' => 100, 'chat_type' => 'dialog'],
            'body' => ['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn/img.jpg']]]],
            'sender' => ['user_id' => 42],
        ],
    ]);

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/messages')
        && str_contains($r->data()['text'] ?? '', 'Сначала выбери карту'));
    // фото юзера (555) НЕ удалено
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'message_id=555'));
});

test('callback merge с пустыми items отвечает «Список пуст»', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '9999', 'color' => '#000000', 'cashback_json' => null]);

    Cache::put('bot.state.max.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(MaxConversationService::class)->handle(maxCallback(42, 'merge'));

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/messages')
        && str_contains($r->data()['text'] ?? '', 'Список пуст'));
    expect(Cache::get('bot.state.max.42')['name'])->toBe('await_confirm');
});

test('callback снимает «часики» через answerCallback (POST /answers)', function () {
    User::factory()->create(['max_id' => '42']);

    app(MaxConversationService::class)->handle(maxCallback(42, 'cmd:update', 100, 'cb_xyz'));

    Http::assertSent(fn ($r) => str_contains($r->url(), '/answers')
        && ($r->data()['callback_id'] ?? null) === 'cb_xyz');
});
