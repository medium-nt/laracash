<?php

use App\Models\Bank;
use App\Models\Card;
use App\Models\Cashback;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Настройка для всех тестов
beforeEach(function () {
    // Создаём роли
    Role::firstOrCreate(['name' => 'client']);
    Role::firstOrCreate(['name' => 'admin']);

    config()->set('tg.token', 'TEST_TOKEN');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
});

test('webhook отклоняет запрос без секретного токена', function () {
    // Устанавливаем секрет в конфиге для теста
    config()->set('tg.webhook_secret', 'SECRET');

    $this->postJson('/api/telegram/webhook', ['update_id' => 1])
        ->assertForbidden();
});

test('webhook отклоняет запрос с неверным секретом', function () {
    config()->set('tg.webhook_secret', 'SECRET');

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'WRONG'])
        ->postJson('/api/telegram/webhook', ['update_id' => 1])
        ->assertForbidden();
});

test('webhook с верным секретом делегирует в conversation и возвращает 200', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    // Создаём пользователя с telegram_id
    $user = User::factory()->create(['telegram_id' => '42']);

    // Фейкаем HTTP запросы к Telegram API
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', [
            'update_id' => 100,
            'message' => [
                'chat' => ['id' => 1, 'type' => 'private'],
                'from' => ['id' => 42],
                'text' => '/start',
            ],
        ])
        ->assertOk();

    // Проверяем, что был отправлен запрос к Telegram API
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'sendMessage');
    });
});

test('webhook с пустым заголовком блокирует запрос', function () {
    config()->set('tg.webhook_secret', 'SECRET');

    // Отправляем запрос без заголовка вообще (заголовок будет null)
    $this->postJson('/api/telegram/webhook', ['update_id' => 1])
        ->assertForbidden();
});

test('webhook с null секретом блокирует все запросы', function () {
    // Устанавливаем null как секрет - fail-closed
    config()->set('tg.webhook_secret', null);

    // Отправляем запрос без заголовка - должно быть 403
    $this->postJson('/api/telegram/webhook', ['update_id' => 1])
        ->assertForbidden();
});

test('webhook с верным секретом обрабатывает callback query', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    User::factory()->create(['telegram_id' => '42']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', [
            'update_id' => 101,
            'callback_query' => [
                'id' => 'callback_id',
                'message' => ['chat' => ['id' => 1, 'type' => 'private']],
                'from' => ['id' => 42],
                'data' => 'cmd:update',
            ],
        ])
        ->assertOk();

    // Проверяем, что ответили на callback
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'answerCallbackQuery');
    });
});

test('webhook идемпотентен: повторный update_id не обрабатывается повторно', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');
    User::factory()->create(['telegram_id' => '42']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $payload = [
        'update_id' => 999,
        'message' => ['chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/start'],
    ];

    // Первый запрос — обрабатывается
    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', $payload)
        ->assertOk();

    // Второй раз с тем же update_id — пропускается (дубль доставки)
    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', $payload)
        ->assertOk();

    // sendMessage вызван ровно 1 раз (только при первой обработке)
    $count = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'))
        ->count();

    expect($count)->toBe(1);
});

test('непривязанный пользователь получает сообщение о привязке с кнопкой Проверить', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    // telegram_id 999 не привязан ни к одному юзеру
    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', [
            'update_id' => 200,
            'message' => [
                'chat' => ['id' => 1, 'type' => 'private'],
                'from' => ['id' => 999],
                'text' => '/start',
            ],
        ])
        ->assertOk();

    // sendMessage содержит текст привязки и inline-кнопку «Проверить» (cmd:recheck)
    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'привяжи аккаунт')
            && str_contains($data['reply_markup'] ?? '', 'cmd:recheck');
    });
});

test('callback cmd:recheck от привязанного пользователя открывает меню', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    User::factory()->create(['telegram_id' => '42']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', [
            'update_id' => 201,
            'callback_query' => [
                'id' => 'cb_recheck',
                'message' => ['chat' => ['id' => 1, 'type' => 'private']],
                'from' => ['id' => 42],
                'data' => 'cmd:recheck',
            ],
        ])
        ->assertOk();

    // sendMenu → сообщение-меню «Привет, … Карт: N.»
    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'Привет')
            && str_contains($data['text'] ?? '', 'Карт:');
    });
});

test('выбор карты предлагает скриншот с указанием банка и номера карты', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['title' => 'Тинькофф', 'user_id' => $user->id]);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '4276 1234',
        'color' => 'dark',
    ]);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', [
            'update_id' => 202,
            'callback_query' => [
                'id' => 'cb_card',
                'message' => ['chat' => ['id' => 1, 'type' => 'private']],
                'from' => ['id' => 42],
                'data' => 'card:'.$card->id,
            ],
        ])
        ->assertOk();

    // Текст ожидания скрина содержит «по карте», название банка и номер
    Http::assertSent(function ($request) use ($bank, $card) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'по карте')
            && str_contains($data['text'] ?? '', $bank->title)
            && str_contains($data['text'] ?? '', $card->number);
    });
});

test('callback cmd:recheck от НЕпривязанного повторяет сообщение о привязке и снимает спиннер', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    // telegram_id 999 не привязан — жмёт «Проверить» под bind-сообщением
    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', [
            'update_id' => 203,
            'callback_query' => [
                'id' => 'cb_unbound',
                'message' => ['chat' => ['id' => 1, 'type' => 'private']],
                'from' => ['id' => 999],
                'data' => 'cmd:recheck',
            ],
        ])
        ->assertOk();

    // answerCallbackQuery вызван — крутилка с кнопки снята
    Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
    // и повторно отправлено bind-сообщение с кнопкой «Проверить»
    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'привяжи аккаунт')
            && str_contains($data['reply_markup'] ?? '', 'cmd:recheck');
    });
});

// ============ Текстовый ввод кешбэка (альтернатива скриншоту) ============

/**
 * Сценарий «выбор карты → ввод категорий текстом → редактор».
 * Создаёт карту и переводит юзера в await_photo (callback card:{id}).
 */
function tgSendTextList(int $userId, int $cardId, string $text): void
{
    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'];

    test()->withHeaders($headers)->postJson('/api/telegram/webhook', [
        'update_id' => 300,
        'callback_query' => [
            'id' => 'cb_card',
            'message' => ['chat' => ['id' => 1, 'type' => 'private']],
            'from' => ['id' => $userId],
            'data' => 'card:'.$cardId,
        ],
    ])->assertOk();

    test()->withHeaders($headers)->postJson('/api/telegram/webhook', [
        'update_id' => 301,
        'message' => [
            'chat' => ['id' => 1, 'type' => 'private'],
            'from' => ['id' => $userId],
            'message_id' => 555,
            'text' => $text,
        ],
    ])->assertOk();
}

test('в await_photo текст-список открывает редактор: своя категория ✅, новая 🆕', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['title' => 'Тинькофф', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '4276 1234', 'color' => 'dark']);
    // «Аптеки» уже есть у юзера → ✅; «Кафе» нет → 🆕
    Category::create(['user_id' => $user->id, 'title' => 'Аптеки', 'keywords' => 'Аптеки']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    tgSendTextList(42, $card->id, "Аптеки 5\nКафе 3,5");

    // Редактор: содержит заголовок и обе категории
    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'Проверь распознанное')
            && str_contains($data['text'] ?? '', 'Аптеки')
            && str_contains($data['text'] ?? '', 'Кафе');
    });
});

test('текст-список с кривыми строками: валидные в редактор, невалидные — в подсказку', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['title' => 'Тинькофф', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '4276 1234', 'color' => 'dark']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    tgSendTextList(42, $card->id, "Аптеки 5\n--- билеты ---\nКафе 3,5");

    // Редактор получил валидные строки
    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'Проверь распознанное')
            && str_contains($data['text'] ?? '', 'Аптеки')
            && str_contains($data['text'] ?? '', 'Кафе');
    });
    // Невалидная строка упомянута отдельной подсказкой
    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'Не понял строки')
            && str_contains($data['text'] ?? '', 'билеты');
    });
});

test('полностью непонятный текст не открывает редактор, подсказывает формат', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['title' => 'Тинькофф', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '4276 1234', 'color' => 'dark']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    tgSendTextList(42, $card->id, "привет\nкак дела");

    // Подсказка о формате...
    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'Не получилось распознать');
    });
    // ...а редактор НЕ открывался
    Http::assertNotSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'sendMessage')
            && str_contains($data['text'] ?? '', 'Проверь распознанное');
    });
});

test('e2e: текст-список → Сохранить(merge) пишет pivot и cashback_json', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['title' => 'Тинькофф', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '4276 1234', 'color' => 'dark']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    tgSendTextList(42, $card->id, "Аптеки 5\nКафе 3,5");

    // Сохранить (merge) → apply → pivot + cashback_json
    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/api/telegram/webhook', [
            'update_id' => 302,
            'callback_query' => [
                'id' => 'cb_merge',
                'message' => ['chat' => ['id' => 1, 'type' => 'private']],
                'from' => ['id' => 42],
                'data' => 'merge',
            ],
        ])
        ->assertOk();

    // 2 категории в pivot, cashback_json записан
    expect(Cashback::where('card_id', $card->id)->count())->toBe(2);
    expect($card->fresh()->cashback_json)->toHaveCount(2);
});

test('после полностью невалидного ввода можно повторить — состояние сохранено в await_photo', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['title' => 'Тинькофф', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '4276 1234', 'color' => 'dark']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'];

    // карта → await_photo
    $this->withHeaders($headers)->postJson('/api/telegram/webhook', [
        'update_id' => 400,
        'callback_query' => ['id' => 'cb_p5', 'message' => ['chat' => ['id' => 1, 'type' => 'private']], 'from' => ['id' => 42], 'data' => 'card:'.$card->id],
    ])->assertOk();

    // полностью невалидный ввод
    $this->withHeaders($headers)->postJson('/api/telegram/webhook', [
        'update_id' => 401,
        'message' => ['chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 42], 'message_id' => 600, 'text' => 'бред'],
    ])->assertOk();

    // редактор НЕ открыт...
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains($r->data()['text'] ?? '', 'Проверь распознанное'));

    // повторный валидный ввод → редактор открыт (await_photo сохранён)
    $this->withHeaders($headers)->postJson('/api/telegram/webhook', [
        'update_id' => 402,
        'message' => ['chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 42], 'message_id' => 601, 'text' => 'Аптеки 5'],
    ])->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains($r->data()['text'] ?? '', 'Проверь распознанное'));
});

test('невалидная строка со спецсимволами экранируется в HTML-подсказке', function () {
    config()->set('tg.webhook_secret', 'SECRET');
    config()->set('tg.token', 'TEST_TOKEN');

    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['title' => 'Тинькофф', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '4276 1234', 'color' => 'dark']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'];

    $this->withHeaders($headers)->postJson('/api/telegram/webhook', [
        'update_id' => 410,
        'callback_query' => ['id' => 'cb_p4', 'message' => ['chat' => ['id' => 1, 'type' => 'private']], 'from' => ['id' => 42], 'data' => 'card:'.$card->id],
    ])->assertOk();

    // валидная + невалидная строка с угловыми скобками
    $this->withHeaders($headers)->postJson('/api/telegram/webhook', [
        'update_id' => 411,
        'message' => ['chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 42], 'message_id' => 610, 'text' => "Аптеки 5\n<b>мусор"],
    ])->assertOk();

    // невалидная строка экранирована в подсказке (HTML-entities, без сырых <>)
    Http::assertSent(function ($r) {
        $t = $r->data()['text'] ?? '';

        return str_contains($r->url(), 'sendMessage')
            && str_contains($t, 'Не понял строки')
            && str_contains($t, '&lt;b&gt;мусор');
    });
});
