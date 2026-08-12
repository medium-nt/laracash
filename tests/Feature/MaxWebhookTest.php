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

test('непривязанный пользователь получает сообщение о привязке с кнопкой Проверить', function () {
    // max_id 999 не привязан ни к одному юзеру
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxMessage(999, '/start'))
        ->assertOk();

    // POST /messages содержит текст привязки и inline-кнопку «Проверить» (cmd:recheck)
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'привяжи аккаунт')
            && str_contains(json_encode($data['attachments'] ?? []), 'cmd:recheck');
    });
});

test('callback cmd:recheck от привязанного пользователя открывает меню', function () {
    User::factory()->create(['max_id' => '42']);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxCallback(42, 'cmd:recheck'))
        ->assertOk();

    // sendMenu → сообщение-меню «Привет, … Карт: N.»
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'Привет')
            && str_contains($data['text'] ?? '', 'Карт:');
    });
});

test('выбор карты предлагает скриншот с указанием банка и номера карты', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '5469 5678',
        'color' => 'dark',
    ]);

    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxCallback(42, 'card:'.$card->id))
        ->assertOk();

    // Текст ожидания скрина содержит «по карте», название банка и номер
    Http::assertSent(function ($request) use ($bank, $card) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'по карте')
            && str_contains($data['text'] ?? '', $bank->title)
            && str_contains($data['text'] ?? '', $card->number);
    });
});

test('callback cmd:recheck от НЕпривязанного повторяет сообщение о привязке и снимает спиннер', function () {
    // max_id 999 не привязан — жмёт «Проверить» под bind-сообщением
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxCallback(999, 'cmd:recheck'))
        ->assertOk();

    // POST /answers вызван — крутилка с кнопки снята
    Http::assertSent(fn ($request) => str_contains($request->url(), '/answers'));
    // и повторно отправлено bind-сообщение с кнопкой «Проверить»
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'привяжи аккаунт')
            && str_contains(json_encode($data['attachments'] ?? []), 'cmd:recheck');
    });
});

// ============ Текстовый ввод кешбэка (альтернатива скриншоту) ============

/**
 * Сценарий «выбор карты → ввод категорий текстом → редактор» для MAX.
 * Создаёт карту и переводит юзера в await_photo (callback card:{id}).
 */
function maxSendTextList(int $userId, int $cardId, string $text): void
{
    $headers = ['X-Max-Bot-API-Secret' => 'SECRET'];

    test()->withHeaders($headers)->postJson('/api/max/webhook', realMaxCallback($userId, 'card:'.$cardId, 100, 'cb_card'))->assertOk();
    test()->withHeaders($headers)->postJson('/api/max/webhook', realMaxMessage($userId, $text))->assertOk();
}

test('в await_photo текст-список открывает редактор: своя категория ✅, новая 🆕', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);
    // «Аптеки» уже есть у юзера → ✅; «Кафе» нет → 🆕
    Category::create(['user_id' => $user->id, 'title' => 'Аптеки', 'keywords' => 'Аптеки']);

    maxSendTextList(42, $card->id, "Аптеки 5\nКафе 3,5");

    // Редактор: содержит заголовок и обе категории
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'Проверь распознанное')
            && str_contains($data['text'] ?? '', 'Аптеки')
            && str_contains($data['text'] ?? '', 'Кафе');
    });
});

test('текст-список с кривыми строками: валидные в редактор, невалидные — в подсказку', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);

    maxSendTextList(42, $card->id, "Аптеки 5\n--- билеты ---\nКафе 3,5");

    // Редактор получил валидные строки
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'Проверь распознанное')
            && str_contains($data['text'] ?? '', 'Аптеки')
            && str_contains($data['text'] ?? '', 'Кафе');
    });
    // Невалидная строка упомянута отдельной подсказкой
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'Не понял строки')
            && str_contains($data['text'] ?? '', 'билеты');
    });
});

test('полностью непонятный текст не открывает редактор, подсказывает формат', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);

    maxSendTextList(42, $card->id, "привет\nкак дела");

    // Подсказка о формате...
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'Не получилось распознать');
    });
    // ...а редактор НЕ открывался
    Http::assertNotSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'Проверь распознанное');
    });
});

test('e2e: текст-список → Сохранить(merge) пишет pivot и cashback_json', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);

    maxSendTextList(42, $card->id, "Аптеки 5\nКафе 3,5");

    // Сохранить (merge) → apply → pivot + cashback_json
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxCallback(42, 'merge', 100, 'cb_merge'))
        ->assertOk();

    expect(Cashback::where('card_id', $card->id)->count())->toBe(2);
    expect($card->fresh()->cashback_json)->toHaveCount(2);
});

test('после полностью невалидного ввода можно повторить — состояние сохранено в await_photo', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);

    $headers = ['X-Max-Bot-API-Secret' => 'SECRET'];

    $this->withHeaders($headers)->postJson('/api/max/webhook', realMaxCallback(42, 'card:'.$card->id, 100, 'cb_p5'))->assertOk();
    $this->withHeaders($headers)->postJson('/api/max/webhook', realMaxMessage(42, 'бред', 100, 'mid.510'))->assertOk();

    // редактор НЕ открыт...
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/messages') && str_contains($r->data()['text'] ?? '', 'Проверь распознанное'));

    // повторный валидный ввод → редактор открыт (await_photo сохранён)
    $this->withHeaders($headers)->postJson('/api/max/webhook', realMaxMessage(42, 'Аптеки 5', 100, 'mid.511'))->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/messages') && str_contains($r->data()['text'] ?? '', 'Проверь распознанное'));
});

test('невалидная строка со спецсимволами экранируется в HTML-подсказке', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);

    $headers = ['X-Max-Bot-API-Secret' => 'SECRET'];

    $this->withHeaders($headers)->postJson('/api/max/webhook', realMaxCallback(42, 'card:'.$card->id, 100, 'cb_p4'))->assertOk();
    // валидная + невалидная строка с угловыми скобками
    $this->withHeaders($headers)->postJson('/api/max/webhook', realMaxMessage(42, "Аптеки 5\n<b>мусор", 100, 'mid.520'))->assertOk();

    // невалидная строка экранирована в подсказке (HTML-entities, без сырых <>)
    Http::assertSent(function ($r) {
        if (! ($r->method() === 'POST' && str_contains($r->url(), '/messages'))) {
            return false;
        }
        $t = $r->data()['text'] ?? '';

        return str_contains($t, 'Не понял строки')
            && str_contains($t, '&lt;b&gt;мусор');
    });
});

// ============ Fuzzy-сопоставление категорий (текстовый ввод) ============

test('fuzzy: «Кафе» резолвится в существующую «Кафе и рестораны» без создания дубля', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);
    Category::create(['user_id' => $user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    maxSendTextList(42, $card->id, 'Кафе 5');

    // Редактор показал КАНОНИЧНОЕ название (✅), а не введённое «Кафе» как новое
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $data = $request->data();

        return str_contains($data['text'] ?? '', 'Проверь распознанное')
            && str_contains($data['text'] ?? '', 'Кафе и рестораны');
    });

    // Сохранить (merge) → apply
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxCallback(42, 'merge', 100, 'cb_merge_fuzzy'))
        ->assertOk();

    // Новая категория НЕ создана — осталась одна «Кафе и рестораны»
    expect(Category::where('user_id', $user->id)->count())->toBe(1);
    expect(Cashback::where('card_id', $card->id)->count())->toBe(1);
});

test('force_new: «+Кафе 5» создаёт отдельную «Кафе» рядом с «Кафе и рестораны»', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);
    Category::create(['user_id' => $user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    maxSendTextList(42, $card->id, '+Кафе 5');

    // Редактор показал отдельную «Кафе» (🆕), НЕ подменив на «Кафе и рестораны»
    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $text = $request->data()['text'] ?? '';

        return str_contains($text, 'Проверь распознанное')
            && str_contains($text, 'Кафе')
            && ! str_contains($text, 'Кафе и рестораны');
    });

    // Сохранить (merge) → apply
    $this->withHeaders(['X-Max-Bot-API-Secret' => 'SECRET'])
        ->postJson('/api/max/webhook', realMaxCallback(42, 'merge', 100, 'cb_merge_force'))
        ->assertOk();

    // Теперь ОБЕ категории: «Кафе и рестораны» + созданная «Кафе»
    expect(Category::where('user_id', $user->id)->count())->toBe(2)
        ->and(Category::where('user_id', $user->id)->where('title', 'Кафе')->exists())->toBeTrue()
        ->and(Cashback::where('card_id', $card->id)->count())->toBe(1);
});

test('buildEditorText: легенда ✅/🆕 НЕ выводится, когда все категории свои', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);
    Category::create(['user_id' => $user->id, 'title' => 'Аптеки', 'keywords' => '']);

    // Только существующая категория (✅) — легенды быть НЕ должно
    maxSendTextList(42, $card->id, 'Аптеки 5');

    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $text = $request->data()['text'] ?? '';

        return str_contains($text, 'Проверь распознанное')
            && ! str_contains($text, 'ваша категория');
    });
});

test('buildEditorText: легенда ✅/🆕 выводится внизу при наличии новой категории', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);
    Category::create(['user_id' => $user->id, 'title' => 'Аптеки', 'keywords' => '']);

    // Смешанный список: ✅ Аптеки + 🆕 Такси — легенда есть и идёт последней строкой
    maxSendTextList(42, $card->id, "Аптеки 5\nТакси 3");

    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $text = $request->data()['text'] ?? '';

        return str_contains($text, 'Проверь распознанное')
            && str_ends_with($text, '✅ — ваша категория, 🆕 — новая, будет создана');
    });
});

test('buildEditorText: дублирующая категория (один category_id у двух пунктов) помечается ⚠️', function () {
    $user = User::factory()->create(['max_id' => '42']);
    $bank = Bank::create(['title' => 'Сбер', 'user_id' => $user->id]);
    $card = Card::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'number' => '5469 5678', 'color' => 'dark']);
    Category::create(['user_id' => $user->id, 'title' => 'Аптеки', 'keywords' => '']);

    // «Аптека» (подстрока→Аптеки) и «Аптеки» (exact→Аптеки) — обе канонизируются в один category_id
    maxSendTextList(42, $card->id, "Аптека 5\nАптеки 7");

    Http::assertSent(function ($request) {
        if (! ($request->method() === 'POST' && str_contains($request->url(), '/messages'))) {
            return false;
        }
        $text = $request->data()['text'] ?? '';

        return str_contains($text, 'Проверь распознанное')
            && str_contains($text, '⚠️')
            && str_contains($text, 'дублирующая категория');
    });
});
