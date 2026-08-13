<?php

use App\Models\Bank;
use App\Models\Card;
use App\Models\Role;
use App\Models\User;
use App\Services\Bot\TelegramConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

// Создаём роль для всех тестов
beforeEach(function () {
    Role::firstOrCreate(['name' => 'client']);
    Role::firstOrCreate(['name' => 'admin']);
    Http::fake([
        '*api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
    config()->set('tg.token', 'TEST');
});

test('/start от привязанного юзера устанавливает состояние idle', function () {
    User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);

    app(TelegramConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/start'],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('idle');
});

test('/start от НЕ привязанного юзера не устанавливает состояние', function () {
    app(TelegramConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 999], 'text' => '/start'],
    ]);

    // Состояние НЕ должно устанавливаться для несуществующего юзера
    $state = Cache::get('bot.state.999');
    expect($state)->toBeNull();
});

test('/menu работает так же как /start', function () {
    User::factory()->create(['telegram_id' => '42', 'name' => 'Тест']);

    app(TelegramConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/menu'],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('idle');
});

test('callback cmd:update без карт устанавливает состояние idle', function () {
    User::factory()->create(['telegram_id' => '42']);

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb123',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cmd:update',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('idle');
});

test('callback cmd:update с картами устанавливает состояние await_card', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Сбербанк']);
    Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '1234',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb123',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cmd:update',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('await_card');
});

test('callback с выбором карты переводит в состояние await_photo', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Тинькофф']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '5678',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb456',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'card:'.$card->id,
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('await_photo')
        ->and($state['card_id'])->toBe($card->id);
});

test('callback merge (и алиас save) применяет кешбэк из items', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '9999',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    // Устанавливаем состояние await_confirm с items
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'raw' => [], // raw больше не используется для apply
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
            ['title' => 'Супермаркеты', 'percent' => 3.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    // Тестируем новый callback 'merge'
    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb789',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'merge',
        ],
    ]);

    $card->refresh();
    expect($card->cashback_json)->not->toBeNull()
        ->and($card->cashback_json)->toBeArray()
        ->and($card->cashback_json[0]['category'])->toBe('Аптеки')
        ->and((float) $card->cashback_json[0]['cashback'])->toBe(5.0);

    // Также проверяем алиас 'save' для обратной совместимости
    $card->update(['cashback_json' => null]);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Кафе', 'percent' => 7.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb790',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'save',
        ],
    ]);

    $card->refresh();
    expect($card->cashback_json[0]['category'])->toBe('Кафе')
        ->and($card->cashback_json[0]['cashback'])->toBe(7);
});

test('callback cancel отменяет применение', function () {
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb000',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cancel',
        ],
    ]);

    // После отмены состояние должно вернуться в idle
    $state = Cache::get('bot.state.42');
    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('idle');
});

test('клавиатура карт пуста если у юзера нет карт', function () {
    User::factory()->create(['telegram_id' => '42']);

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb111',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cmd:update',
        ],
    ]);

    // Проверяем что состояние вернулось в idle после сообщения об отсутствии карт
    $state = Cache::get('bot.state.42');
    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('idle');
});

test('callback card с чужим card_id шлёт "Карта не найдена" и не меняет state', function () {
    // Arrange: создаем User A (с telegram_id='1')
    $userA = User::factory()->create(['telegram_id' => '1']);
    $bankA = Bank::create(['user_id' => $userA->id, 'title' => 'Сбербанк']);
    $cardA = Card::create([
        'user_id' => $userA->id,
        'bank_id' => $bankA->id,
        'number' => '1111',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    // Создаем User B с другой картой Card B
    $userB = User::factory()->create(['telegram_id' => '2']);
    $bankB = Bank::create(['user_id' => $userB->id, 'title' => 'Тинькофф']);
    $cardB = Card::create([
        'user_id' => $userB->id,
        'bank_id' => $bankB->id,
        'number' => '2222',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    // Устанавливаем начальное состояние User A в idle
    Cache::put('bot.state.1', ['name' => 'idle'], now()->addSeconds(1800));

    // Act: callback от User A с card_id принадлежащим User B
    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb999',
            'from' => ['id' => 1],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'card:'.$cardB->id, // card_id от User B
        ],
    ]);

    // Assert: проверяем что отправлено сообщение с текстом "Карта не найдена"
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'sendMessage')
            && str_contains($request->data()['text'] ?? '', 'Карта не найдена');
    });

    // Assert: состояние User A НЕ изменилось на await_photo
    $state = Cache::get('bot.state.1');
    expect($state)->not->toBeNull()
        ->and($state['name'])->not->toBe('await_photo')
        ->and($state['name'])->toBe('idle'); // состояние должно остаться idle
});

test('parseItem парсит название с пробелами и процент', function () {
    expect(App\Services\Bot\TelegramConversationService::parseItem('Аптеки 5'))->toBe([
        'title' => 'Аптеки',
        'percent' => 5.0,
        'mcc' => '',
        'force_new' => false,
    ]);

    // Маркер «+» → принудительно новая категория (force_new=true, сам «+» срезан)
    expect(App\Services\Bot\TelegramConversationService::parseItem('+Кафе 5'))->toBe([
        'title' => 'Кафе',
        'percent' => 5.0,
        'mcc' => '',
        'force_new' => true,
    ]);

    expect(App\Services\Bot\TelegramConversationService::parseItem('Кафе и рестораны 3.5'))->toBe([
        'title' => 'Кафе и рестораны',
        'percent' => 3.5,
        'mcc' => '',
        'force_new' => false,
    ]);

    expect(App\Services\Bot\TelegramConversationService::parseItem('Кино'))->toBeNull();

    expect(App\Services\Bot\TelegramConversationService::parseItem(''))->toBeNull();
});

test('parseItem разбирает примечание через пробел (процент = первое число)', function () {
    // Примечание с цифрами (MCC «03») не путается с процентом — процент это первое число
    expect(App\Services\Bot\TelegramConversationService::parseItem('Аптеки 5 только 03'))->toBe([
        'title' => 'Аптеки',
        'percent' => 5.0,
        'mcc' => 'только 03',
        'force_new' => false,
    ]);

    // Десятичный процент + примечание
    expect(App\Services\Bot\TelegramConversationService::parseItem('Кафе 3,5 по будням'))->toBe([
        'title' => 'Кафе',
        'percent' => 3.5,
        'mcc' => 'по будням',
        'force_new' => false,
    ]);

    // «%» после числа не мешает примечанию
    expect(App\Services\Bot\TelegramConversationService::parseItem('Аптеки 5% только 03'))->toBe([
        'title' => 'Аптеки',
        'percent' => 5.0,
        'mcc' => 'только 03',
        'force_new' => false,
    ]);

    // Без примечания — mcc=''
    expect(App\Services\Bot\TelegramConversationService::parseItem('Кафе 3')['mcc'])->toBe('');
});

test('parseItem не пропускает перенос строки в названии (защита inline-кнопки)', function () {
    // Перенос внутри названия → null (иначе \n попал бы в текст кнопки и сломал рендер)
    expect(App\Services\Bot\TelegramConversationService::parseItem("Аптеки\nсамые дешёвые 5"))->toBeNull();
    // Перенос между названием и процентом — допустим (title без \n)
    expect(App\Services\Bot\TelegramConversationService::parseItem("Аптеки\n5"))->toBe([
        'title' => 'Аптеки',
        'percent' => 5.0,
        'mcc' => '',
        'force_new' => false,
    ]);
});

test('callback del удаляет пункт', function () {
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
            ['title' => 'Кафе', 'percent' => 3.0],
            ['title' => 'Кино', 'percent' => 10.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_del',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'del:1',
        ],
    ]);

    // Проверяем, что элемент удалён
    $state = Cache::get('bot.state.42');
    expect($state['items'])->toHaveCount(2)
        ->and($state['items'][0]['title'])->toBe('Аптеки')
        ->and($state['items'][1]['title'])->toBe('Кино');

    // Проверяем, что editMessageText был вызван
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'editMessageText');
    });
});

test('callback edit переводит в await_edit', function () {
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_edit',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'edit:0',
        ],
    ]);

    // Проверяем, что состояние изменилось на await_edit
    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_edit')
        ->and($state['index'])->toBe(0);
});

test('сообщение в состоянии await_edit обновляет элемент', function () {
    $user = User::factory()->create(['telegram_id' => '42']);

    // Устанавливаем состояние await_edit с данными из await_confirm
    Cache::put('bot.state.42', [
        'name' => 'await_edit',
        'index' => 0,
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    // Отправляем сообщение с новым названием и процентом
    app(TelegramConversationService::class)->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'Супермаркеты 7',
        ],
    ]);

    // Проверяем, что элемент обновлён
    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'][0]['title'])->toBe('Супермаркеты')
        ->and($state['items'][0]['percent'])->toBe(7.0);
});

test('сообщение в состоянии await_add добавляет элемент', function () {
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_add',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'Кафе и рестораны 3.5',
        ],
    ]);

    // Проверяем, что элемент добавлен
    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'])->toHaveCount(2)
        ->and($state['items'][1]['title'])->toBe('Кафе и рестораны')
        ->and($state['items'][1]['percent'])->toBe(3.5);
});

test('сообщение с неверным форматом возвращает ошибку', function () {
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_edit',
        'index' => 0,
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'неправильный формат',
        ],
    ]);

    // Проверяем, что отправлено сообщение об ошибке
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'sendMessage')
            && str_contains($request->data()['text'] ?? '', 'Не понял формат');
    });

    // Состояние должно остаться await_edit
    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_edit');
});

test('callback replace удаляет старые категории и записывает новые', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Сбербанк']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '1111',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    // Создаём категорию
    $category = \App\Models\Category::create([
        'user_id' => $user->id,
        'title' => 'Аптеки',
        'keywords' => 'аптека,лекарства,фармация',
    ]);

    // Создаём существующую запись Cashback для карты
    \App\Models\Cashback::create([
        'card_id' => $card->id,
        'category_id' => $category->id,
        'cashback_percentage' => 3.0,
        'mcc' => '',
    ]);

    // Устанавливаем состояние await_confirm с НОВОЙ категорией и тем же title
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0], // Та же категория, другой процент
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    // Вызываем callback replace
    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_replace',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'replace',
        ],
    ]);

    // Проверяем, что СТАРАЯ запись удалена (запись с 3.0%)
    expect(\App\Models\Cashback::where('card_id', $card->id)
        ->where('category_id', $category->id)
        ->where('cashback_percentage', 3.0)
        ->count())->toBe(0);

    // Проверяем, что применяется НОВАЯ запись из items
    $card->refresh();
    expect($card->cashback_json)->not->toBeNull()
        ->and($card->cashback_json)->toBeArray()
        ->and($card->cashback_json[0]['category'])->toBe('Аптеки')
        ->and((float) $card->cashback_json[0]['cashback'])->toBe(5.0);
});

test('callback merge НЕ удаляет старые категории', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Тинькофф']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '2222',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    // Создаём категорию
    $category1 = \App\Models\Category::create([
        'user_id' => $user->id,
        'title' => 'Кафе',
        'keywords' => 'кафе,ресторан,еда',
    ]);

    $category2 = \App\Models\Category::create([
        'user_id' => $user->id,
        'title' => 'Аптеки',
        'keywords' => 'аптека,лекарства',
    ]);

    // Создаём существующую запись Cashback (старая категория)
    \App\Models\Cashback::create([
        'card_id' => $card->id,
        'category_id' => $category1->id,
        'cashback_percentage' => 3.0,
        'mcc' => '',
    ]);

    // Устанавливаем состояние await_confirm с НОВОЙ категорией
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0], // Новая категория
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    // Вызываем callback merge
    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_merge',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'merge',
        ],
    ]);

    // Проверяем, что СТАРАЯ запись ОСТАЛАСЬ (merge не удаляет)
    $oldCashback = \App\Models\Cashback::where('card_id', $card->id)
        ->where('category_id', $category1->id)
        ->first();

    expect($oldCashback)->not->toBeNull()
        ->and($oldCashback->cashback_percentage)->toBe(3.0);

    // Проверяем, что НОВАЯ запись добавлена
    $card->refresh();
    expect($card->cashback_json)->not->toBeNull()
        ->and($card->cashback_json)->toBeArray()
        ->and($card->cashback_json[0]['category'])->toBe('Аптеки')
        ->and((float) $card->cashback_json[0]['cashback'])->toBe(5.0);
});

test('buildEditorKeyboard использует понятный нейминг без дубля «Добавить»', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');
    $kb = $method->invoke($service, [
        ['title' => 'Аптеки', 'percent' => 5.0],
        ['title' => 'Кафе', 'percent' => 3.0],
    ]);

    // Кнопки сохранения — каждая на всю ширину своей строки
    $buttons = collect($kb)->flatten(1)->keyBy('callback_data');

    expect($buttons->has('add'))->toBeTrue()
        ->and($buttons->has('merge'))->toBeTrue()
        ->and($buttons->has('replace'))->toBeTrue()
        ->and($buttons->has('cancel'))->toBeTrue()
        ->and($buttons->get('add')['text'])->toBe('➕ Добавить категорию')
        ->and($buttons->get('merge')['text'])->toBe('💾 Сохранить (добавить к старым)')
        ->and($buttons->get('replace')['text'])->toBe('♻️ Заменить (удалить старые)');
});

test('buildEditorKeyboard: «Добавить категорию» идёт первой строкой', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');
    $kb = $method->invoke($service, [
        ['title' => 'Аптеки', 'percent' => 5.0, 'category_id' => 1],
        ['title' => 'Кафе', 'percent' => 3.0, 'category_id' => 2],
    ]);

    // Первая строка клавиатуры — кнопка «Добавить категорию»
    expect($kb[0][0]['callback_data'] ?? null)->toBe('add');
});

test('обработка ввода в await_edit удаляет сообщение пользователя и transient-промпт (cleanup)', function () {
    User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_edit',
        'index' => 0,
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
        'last_bot_msg' => 7,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'message' => [
            'message_id' => 99,
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'Кафе 3',
        ],
    ]);

    // Сообщение пользователя (99) и transient-промпт (7) удалены
    Http::assertSent(fn ($r) => str_contains($r->url(), '/deleteMessage')
        && (int) ($r->data()['message_id'] ?? 0) === 99);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/deleteMessage')
        && (int) ($r->data()['message_id'] ?? 0) === 7);

    // Редактор обновлён in-place
    Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText'));

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'][0]['title'])->toBe('Кафе')
        ->and($state['last_bot_msg'])->toBeNull();
});

test('повторный transient удаляет предыдущее сообщение бота — меню не копится', function () {
    User::factory()->create(['telegram_id' => '42', 'name' => 'Тест']);
    $svc = app(TelegramConversationService::class);

    // /start → меню (last_bot_msg запомнен в state)
    $svc->handle(['message' => ['message_id' => 501, 'chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/start']]);
    // /menu → новый transient должен удалить предыдущее сообщение бота (msg_id=1 из fake)
    $svc->handle(['message' => ['message_id' => 502, 'chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/menu']]);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/deleteMessage')
        && (int) ($r->data()['message_id'] ?? 0) === 1);
});

test('текстовое сообщение пользователя удаляется (команды/ввод чистятся)', function () {
    User::factory()->create(['telegram_id' => '42', 'name' => 'Тест']);

    app(TelegramConversationService::class)->handle([
        'message' => ['message_id' => 333, 'chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/menu'],
    ]);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/deleteMessage')
        && (int) ($r->data()['message_id'] ?? 0) === 333);
});

test('при сохранении кешбэка удаляется скрин (photo_msg_id)', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '9999',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
        'photo_msg_id' => 777,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb1',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'merge',
        ],
    ]);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/deleteMessage')
        && (int) ($r->data()['message_id'] ?? 0) === 777);
});

test('buildEditorText помечает существующие ✅ и новые 🆕', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorText');
    $text = $method->invoke($service, [
        ['title' => 'Супермаркеты', 'percent' => 5.0, 'category_id' => 1],
        ['title' => 'Доставка еды', 'percent' => 7.0, 'category_id' => null],
    ]);

    expect($text)->toContain('✅ Супермаркеты — 5%')
        ->and($text)->toContain('🆕 Доставка еды — 7%');
});

test('callback merge создаёт недостающую категорию из items и сообщает о ней', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '9999',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Доставка еды', 'percent' => 7.0, 'category_id' => null], // 🆕
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'merge',
        ],
    ]);

    // Категория создана
    expect(\App\Models\Category::query()->where('user_id', $user->id)->where('title', 'Доставка еды')->exists())->toBeTrue();

    // Сообщение упоминает новую категорию
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
        && str_contains($r->data()['text'] ?? '', '1 нов.: Доставка еды'));
});

test('buildEditorText экранирует спецсимволы в названии (защита HTML-парсинга)', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorText');
    $text = $method->invoke($service, [
        ['title' => 'Кафе & Рестораны <b>x</b>', 'percent' => 5.0, 'category_id' => 1],
    ]);

    expect($text)->toContain('Кафе &amp; Рестораны &lt;b&gt;x&lt;/b&gt;')
        ->and($text)->not->toContain('<b>');
});

test('фото вне состояния await_photo даёт подсказку и удаляется', function () {
    User::factory()->create(['telegram_id' => '42']);

    app(TelegramConversationService::class)->handle([
        'message' => [
            'message_id' => 555,
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'photo' => [['file_id' => 'x', 'width' => 1, 'height' => 1]],
        ],
    ]);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
        && str_contains($r->data()['text'] ?? '', 'Сначала выбери карту'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/deleteMessage')
        && (int) ($r->data()['message_id'] ?? 0) === 555);
});

test('callback merge с пустыми items отвечает «Список пуст» и остаётся в редакторе', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '9999',
        'color' => '#000000',
        'cashback_json' => null,
    ]);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'merge',
        ],
    ]);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
        && str_contains($r->data()['text'] ?? '', 'Список пуст'));

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm');
});

test('callback note переводит в await_note с index', function () {
    User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_note',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'note:0',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_note')
        ->and($state['index'])->toBe(0);
});

test('сообщение в await_note сохраняет примечание в item', function () {
    User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_note',
        'index' => 0,
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0, 'category_id' => null]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => '5912, только по будням',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'][0]['mcc'])->toBe('5912, только по будням');
});

test('/skip в await_note убирает примечание', function () {
    User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_note',
        'index' => 0,
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0, 'mcc' => 'старое примечание']],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => '/skip',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['items'][0]['mcc'])->toBe('');
});

test('правка в await_edit без примечания сохраняет прежнее (mcc не затирается)', function () {
    User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_edit',
        'index' => 0,
        'card_id' => 123,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0, 'mcc' => '5912']],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'Аптеки 7', // без «|» — mcc должен сохраниться
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['items'][0]['percent'])->toBe(7.0)
        ->and($state['items'][0]['mcc'])->toBe('5912');
});

test('buildEditorText показывает примечание в той же строке через «·»', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorText');
    $text = $method->invoke($service, [
        ['title' => 'Аптеки', 'percent' => 5.0, 'category_id' => 1, 'mcc' => '5912'],
        ['title' => 'Кафе', 'percent' => 3.0, 'category_id' => null, 'mcc' => ''],
    ]);

    // Примечание в той же строке, что и процент (через «·»), без переноса
    expect($text)->toContain('✅ Аптеки — 5% · 📝 5912')
        ->and($text)->toContain('🆕 Кафе — 3%');
    // 📝 ровно один — только у Аптек (у Кафе mcc пустой)
    expect(substr_count($text, '📝'))->toBe(1);
    // Пункты разделены пустой строкой
    expect($text)->toContain("5912\n\n2.");
});

test('callback merge сохраняет примечание items в pivot', function () {
    $user = User::factory()->create(['telegram_id' => '42']);
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'Альфа']);
    $card = Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '9999',
        'color' => '#000000',
        'cashback_json' => null,
    ]);
    $category = \App\Models\Category::create([
        'user_id' => $user->id,
        'title' => 'Аптеки',
        'keywords' => '',
    ]);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => $card->id,
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0, 'mcc' => '5912']],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'merge',
        ],
    ]);

    $cashback = \App\Models\Cashback::query()
        ->where('card_id', $card->id)
        ->where('category_id', $category->id)
        ->first();

    expect($cashback)->not->toBeNull()
        ->and($cashback->mcc)->toBe('5912');
});

test('/start генерирует search_token и отправляет меню с URL-кнопкой', function () {
    URL::forceRootUrl('https://example.com');

    $user = User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);

    app(TelegramConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/start'],
    ]);

    // search_token сгенерирован
    $user->refresh();
    expect($user->search_token)->not->toBeEmpty();

    // Проверяем, что sendMessage отправлен с URL-кнопкой
    Http::assertSent(function ($request) use ($user) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        // Ищем URL-кнопку с текстом "📂 Мои кешбэки"
        foreach ($inlineKeyboard as $row) {
            foreach ($row as $button) {
                if (isset($button['url']) && str_contains($button['url'], '/search/')) {
                    return str_contains($button['url'], $user->search_token)
                        && str_contains($button['url'], 'example.com');
                }
            }
        }

        return false;
    });
});

test('ИНВАРИАНТ: /start НЕ перегенерирует существующий search_token', function () {
    URL::forceRootUrl('https://example.com');

    $user = User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);
    $user->search_token = 'fixedtoken123';
    $user->save();

    app(TelegramConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/start'],
    ]);

    // search_token НЕ изменился
    $user->refresh();
    expect($user->search_token)->toBe('fixedtoken123');

    // URL-кнопка содержит старый токен
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        foreach ($inlineKeyboard as $row) {
            foreach ($row as $button) {
                if (isset($button['url']) && str_contains($button['url'], '/search/fixedtoken123')) {
                    return true;
                }
            }
        }

        return false;
    });
});

test('callback cmd:link генерирует search_token и отправляет ссылку', function () {
    URL::forceRootUrl('https://example.com');

    $user = User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_link',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cmd:link',
        ],
    ]);

    // search_token сгенерирован
    $user->refresh();
    expect($user->search_token)->not->toBeEmpty();

    // Проверяем, что отправлено сообщение с ссылкой и URL-кнопкой
    Http::assertSent(function ($request) use ($user) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $text = $data['text'] ?? '';

        // Текст содержит /search/
        if (! str_contains($text, '/search/')) {
            return false;
        }

        // URL-кнопка содержит токен
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        foreach ($inlineKeyboard as $row) {
            foreach ($row as $button) {
                if (isset($button['url']) && str_contains($button['url'], $user->search_token)) {
                    return true;
                }
            }
        }

        return false;
    });
});

test('ИНВАРИАНТ: cmd:link НЕ перегенерирует существующий search_token', function () {
    URL::forceRootUrl('https://example.com');

    $user = User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);
    $user->search_token = 'fixedtoken456';
    $user->save();

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_link',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cmd:link',
        ],
    ]);

    // search_token НЕ изменился
    $user->refresh();
    expect($user->search_token)->toBe('fixedtoken456');

    // Сообщение и кнопка содержат старый токен
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $text = $data['text'] ?? '';

        // Текст содержит /search/fixedtoken456
        if (! str_contains($text, '/search/fixedtoken456')) {
            return false;
        }

        // URL-кнопка содержит старый токен
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        foreach ($inlineKeyboard as $row) {
            foreach ($row as $button) {
                if (isset($button['url']) && str_contains($button['url'], '/search/fixedtoken456')) {
                    return true;
                }
            }
        }

        return false;
    });
});

test('/start на localhost НЕ добавляет URL-кнопку, но меню отправляется', function () {
    // БЕЗ override app.url — по умолчанию localhost:8000 (не публичный)
    $user = User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);

    app(TelegramConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/start'],
    ]);

    // search_token сгенерирован
    $user->refresh();
    expect($user->search_token)->not->toBeEmpty();

    // Сообщение "Привет..." отправлено
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
        && str_contains($r->data()['text'] ?? '', 'Привет'));

    // URL-кнопки НЕТ — проверяем через reply_markup
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        // Проверяем, что НЕТ кнопки с url
        foreach ($inlineKeyboard as $row) {
            foreach ($row as $button) {
                if (isset($button['url'])) {
                    return false; // Нашли URL-кнопку — провал
                }
            }
        }

        return true;
    });

    // Callback-кнопка "Прислать ссылку" (cmd:link) ЕСТЬ
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        foreach ($inlineKeyboard as $row) {
            foreach ($row as $button) {
                if (isset($button['callback_data']) && $button['callback_data'] === 'cmd:link') {
                    return true;
                }
            }
        }

        return false;
    });
});

test('cmd:link на localhost присылает ссылку текстом БЕЗ URL-кнопки', function () {
    // БЕЗ override app.url — по умолчанию localhost:8000 (не публичный)
    $user = User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);

    app(TelegramConversationService::class)->handle([
        'callback_query' => [
            'id' => 'cb_link',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cmd:link',
        ],
    ]);

    // search_token сгенерирован
    $user->refresh();
    expect($user->search_token)->not->toBeEmpty();

    // Текст сообщения содержит /search/
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();

        return str_contains($data['text'] ?? '', '/search/');
    });

    // URL-кнопки НЕТ
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        // Проверяем, что НЕТ кнопки с url
        foreach ($inlineKeyboard as $row) {
            foreach ($row as $button) {
                if (isset($button['url'])) {
                    return false;
                }
            }
        }

        return true;
    });
});

test('isPublicUrl: URL-кнопка в меню зависит от публичности APP_URL', function (string $appUrl, bool $shouldHaveUrlButton) {
    User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);

    URL::forceRootUrl($appUrl);

    app(TelegramConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/start'],
    ]);

    // Меню в любом случае отправлено
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
        && str_contains($r->data()['text'] ?? '', 'Привет'));

    // Проверяем наличие/отсутствие URL-кнопки
    Http::assertSent(function ($request) use ($shouldHaveUrlButton) {
        if (! str_contains($request->url(), 'sendMessage')) {
            return false;
        }

        $data = $request->data();
        $replyMarkup = json_decode($data['reply_markup'] ?? '{}', true);
        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? [];

        // Собираем все кнопки плоско
        $allButtons = collect($inlineKeyboard)->flatten(1)->all();

        $hasUrlButton = false;
        foreach ($allButtons as $button) {
            if (isset($button['url']) && str_contains($button['url'], '/search/')) {
                $hasUrlButton = true;
                break;
            }
        }

        return $shouldHaveUrlButton ? $hasUrlButton : ! $hasUrlButton;
    });
})->with([
    'публичный https://example.com' => ['https://example.com', true],
    'локальный http://localhost' => ['http://localhost', false],
    '127.0.0.1' => ['http://127.0.0.1', false],
    '192.168.1.1' => ['http://192.168.1.1', false],
    '10.0.0.5' => ['http://10.0.0.5', false],
    '172.17.0.1 (Docker)' => ['http://172.17.0.1', false],
    '172.217.20.46 (публичный 172.*)' => ['http://172.217.20.46', true],
    'example.test TLD' => ['http://example.test', false],
    'LOCALHOST uppercase' => ['http://LOCALHOST:8000', false],
]);
