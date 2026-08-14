<?php

use App\Models\Bank;
use App\Models\Card;
use App\Models\Role;
use App\Models\User;
use App\Services\Bot\TelegramConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

test('callback edit работает как алиас cat (toggle active)', function () {
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

    // edit:{i} теперь алиас cat:{i} — устанавливает active=i (развёрнут)
    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm')
        ->and($state['active'])->toBe(0);
});

test('edit alias with non-zero indices sets correct active (catch substr bug)', function (string $callbackData, int $expectedActive) {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    // Подготовка: пользователь в await_confirm с 3 пунктами
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
            'id' => 'cb_edit',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => $callbackData,
        ],
    ]);

    // active должен быть равен индексу (НЕ 0 из-за бага substr)
    $state = Cache::get('bot.state.42');
    expect($state['active'])->toBe($expectedActive);
})->with([
    'edit:1' => ['edit:1', 1],
    'edit:2' => ['edit:2', 2],
]);

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

test('builds editor keyboard with one wide category row per item and global buttons', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');
    $kb = $method->invoke($service, [
        ['title' => 'Супермаркеты', 'percent' => 5.0, 'category_id' => 1, 'mcc' => ''],
        ['title' => 'Рестораны', 'percent' => 10.0, 'category_id' => null, 'mcc' => ''],
    ]);

    // Первая строка — «Добавить категорию»
    expect($kb[0][0]['text'])->toContain('Добавить категорию');
    expect($kb[0][0]['callback_data'])->toBe('add');

    // Каждый пункт — ОДНА кнопка в ряду, callback cat:{i}
    // Найдём кнопки по callback_data
    $flatButtons = collect($kb)->flatten(1)->keyBy('callback_data');

    expect($flatButtons->has('cat:0'))->toBeTrue();
    expect($flatButtons->get('cat:0')['text'])->toContain('Супермаркеты')->toContain('5%')->toContain('✅');

    expect($flatButtons->has('cat:1'))->toBeTrue();
    expect($flatButtons->get('cat:1')['text'])->toContain('Рестораны')->toContain('10%')->toContain('🆕');

    // Хвостовые глобальные кнопки без изменений
    expect($flatButtons->has('merge'))->toBeTrue();
    expect($flatButtons->has('replace'))->toBeTrue();
    expect($flatButtons->has('cancel'))->toBeTrue();

    // MARKUP: Пункты свёрнуты (active=null) → все cat:{i} содержат ▶
    expect($flatButtons->get('cat:0')['text'])->toContain('▶');
    expect($flatButtons->get('cat:1')['text'])->toContain('▶');
    // Свёрнутые пункты НЕ должны содержать ▼
    expect($flatButtons->get('cat:0')['text'])->not->toContain('▼');
    expect($flatButtons->get('cat:1')['text'])->not->toContain('▼');
});

test('expands the active item with field rows', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');
    $kb = $method->invoke($service, [
        ['title' => 'Рестораны', 'percent' => 10.0, 'category_id' => null, 'mcc' => ''],
    ], 0);

    $flatButtons = collect($kb)->flatten(1)->keyBy('callback_data');

    expect($flatButtons->has('cat:0'))->toBeTrue();          // широкая кнопка активного пункта
    expect($flatButtons->has('edt_t:0'))->toBeTrue();        // Название
    expect($flatButtons->has('edt_p:0'))->toBeTrue();        // Процент
    expect($flatButtons->has('note:0'))->toBeTrue();         // Примечание
    expect($flatButtons->has('del:0'))->toBeTrue();         // Удалить (рядом со Свернуть)

    // Последний ряд развёрнутого пункта — Удалить + Свернуть (2 кнопки), Свернуть = cat:0
    // найти ряд, где есть del:0 — он же содержит cat:0 (Свернуть)
    $delRow = array_values(array_filter($kb, fn ($r) => in_array('del:0', array_column($r, 'callback_data'), true)))[0];
    expect(array_column($delRow, 'callback_data'))->toBe(['del:0', 'cat:0']);

    // MARKUP: Широкая кнопка активного пункта (cat:0) содержит ▼ (не ▶)
    // Ищем кнопку с title и процентом, а не кнопку Свернуть
    $wideCatButton = collect($kb)->flatten(1)->first(fn ($btn) => ($btn['callback_data'] ?? null) === 'cat:0' &&
        str_contains($btn['text'] ?? '', mb_strtoupper('Рестораны')) &&
        str_contains($btn['text'] ?? '', '10%')
    );
    expect($wideCatButton['text'])->toContain('▼');
    expect($wideCatButton['text'])->not->toContain('▶');

    // UPPER: активная категория — название в верхнем регистре (визуальное выделение)
    expect($wideCatButton['text'])->toContain(mb_strtoupper('Рестораны'));

    // MARKUP: Поля активного пункта — кнопки-действия с новыми лейблами (без префиксов)
    expect($flatButtons->get('edt_t:0')['text'])->toBe('✏️ Изменить название');
    expect($flatButtons->get('edt_p:0')['text'])->toBe('％ Изменить процент');
    expect($flatButtons->get('note:0')['text'])->toBe('📝 Примечание');
    expect($flatButtons->get('del:0')['text'])->toBe('🗑 Удалить');

    // MARKUP: Кнопка Свернуть (cat:0) в последнем ряду содержит ✖
    $collapseButton = collect($delRow)->first(fn ($btn) => ($btn['callback_data'] ?? null) === 'cat:0');
    expect($collapseButton['text'])->toContain('✖');

    // GLOBALS HIDDEN: при развёрнутом пункте глобальные действия убраны (не отвлекают)
    expect($flatButtons->has('add'))->toBeFalse();
    expect($flatButtons->has('merge'))->toBeFalse();
    expect($flatButtons->has('replace'))->toBeFalse();
    expect($flatButtons->has('cancel'))->toBeFalse();

    // VALUES: значения полей развёрнутого пункта — в тексте сообщения (не в кнопках)
    $textMethod = new ReflectionMethod($service, 'buildEditorText');
    $text = $textMethod->invoke($service, [
        ['title' => 'Рестораны', 'percent' => 10.0, 'category_id' => null, 'mcc' => ''],
    ], 0);
    expect($text)->toContain('Название: Рестораны');
    expect($text)->toContain('Процент: 10%');
    expect($text)->toContain('Примечание: (пусто)');

    // FORMAT: активный пункт — жирным (<b>), подпункты — plain text с древовисными префиксами
    expect($text)->toContain('<b>');
    expect($text)->toContain('├ Название:');
    expect($text)->toContain('├ Процент:');
    expect($text)->toContain('└ Примечание:');
});

test('toggles active item via cat callback and collapses on second tap', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    // Подготовка: пользователь в await_confirm с 2 пунктами и msg_id
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
            ['title' => 'Кафе', 'percent' => 3.0],
        ],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    // Первый тап — разворот пункта 1
    $service->handle([
        'callback_query' => [
            'id' => 'cb1',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cat:1',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['active'])->toBe(1);

    // Повторный тап по cat:1 — сворачивание
    $service->handle([
        'callback_query' => [
            'id' => 'cb2',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cat:1',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['active'])->toBeNull();
});

test('resets active when deleting an item', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    // Подготовка: пользователь в await_confirm с 2 пунктами, msg_id и active=1
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [
            ['title' => 'Аптеки', 'percent' => 5.0],
            ['title' => 'Кафе', 'percent' => 3.0],
        ],
        'msg_id' => 1,
        'active' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    // Удаляем пункт 1 (развёрнутый)
    $service->handle([
        'callback_query' => [
            'id' => 'cb_del',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'del:1',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['active'])->toBeNull();
    expect($state['items'])->toHaveCount(1); // пункт удалён
    expect($state['items'][0]['title'])->toBe('Аптеки');
});

test('edt_t callback enters await_edit with field=title and updates title with re-match', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    // Создаём категорию «Аптеки» для пользователя
    \App\Models\Category::create([
        'user_id' => $user->id,
        'title' => 'Аптеки',
        'keywords' => 'аптека',
    ]);

    // Подготовка: пользователь в await_confirm с пунктом «Рестораны» (category_id=null, 🆕)
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Рестораны', 'percent' => 10.0, 'category_id' => null]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    // Тап «Название» пункта 0
    $service->handle([
        'callback_query' => [
            'id' => 'cb_edt_t',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'edt_t:0',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_edit');
    expect($state['index'])->toBe(0);
    expect($state['field'])->toBe('title');

    // Ввод нового названия «Аптеки» (существующая категория)
    $service->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'Аптеки',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm');
    expect($state['active'])->toBe(0);                  // пункт остался развёрнут
    expect($state['items'][0]['title'])->toBe('Аптеки');
    expect($state['items'][0]['category_id'])->not->toBeNull(); // категория пересопоставлена → ✅
});

test('edt_p callback enters await_edit with field=percent and updates percent', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    // Подготовка: пользователь в await_confirm
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    $service->handle([
        'callback_query' => [
            'id' => 'cb_edt_p',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'edt_p:0',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['field'])->toBe('percent');

    // Процент с запятой нормализуется
    $service->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => '3,5',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['items'][0]['percent'])->toBe(3.5);
});

test('edt_p callback parses integer percent correctly', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    $service->handle([
        'callback_query' => [
            'id' => 'cb_edt_p',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'edt_p:0',
        ],
    ]);

    // Целый процент
    $service->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => '15',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['items'][0]['percent'])->toBe(15.0);
});

test('rejects invalid percent input and stays in await_edit', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    $service->handle([
        'callback_query' => [
            'id' => 'cb_edt_p',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'edt_p:0',
        ],
    ]);

    $service->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'не число',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_edit'); // остались ждать корректного ввода
});

test('rejects invalid title input and stays in await_edit', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    $service->handle([
        'callback_query' => [
            'id' => 'cb_edt_t',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'edt_t:0',
        ],
    ]);

    // Пустой ввод
    $service->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => '   ',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_edit'); // остались ждать корректного ввода
});

test('await_note returns to await_confirm with the item expanded', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    // Подготовка: пользователь в await_confirm
    Cache::put('bot.state.42', [
        'name' => 'await_confirm',
        'card_id' => 123,
        'raw' => [],
        'image' => null,
        'items' => [['title' => 'Аптеки', 'percent' => 5.0]],
        'msg_id' => 1,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    // note:0 → await_note, index=0
    $service->handle([
        'callback_query' => [
            'id' => 'cb_note',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'note:0',
        ],
    ]);

    $service->handle([
        'message' => [
            'chat' => ['id' => 100],
            'from' => ['id' => 42],
            'text' => 'MCC5812',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['name'])->toBe('await_confirm');
    expect($state['active'])->toBe(0);                 // пункт развёрнут после правки примечания
    expect($state['items'][0]['mcc'])->toBe('MCC5812');
});

test('switches active from one item to another (cat:j with j≠i)', function () {
    Http::fake(['*api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    $user = User::factory()->create(['telegram_id' => '42']);

    // Подготовка: пользователь в await_confirm с 3 пунктами, active=0 (развёрнут первый)
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
        'active' => 0,
    ], now()->addSeconds(1800));

    $service = app(TelegramConversationService::class);

    // Тап по cat:2 → должен переключить active с 0 на 2
    $service->handle([
        'callback_query' => [
            'id' => 'cb_switch',
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100]],
            'data' => 'cat:2',
        ],
    ]);

    $state = Cache::get('bot.state.42');
    expect($state['active'])->toBe(2); // switched to item 2
});

test('collapsed items show ▶ and active item shows ▼ when multiple items exist', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');
    $kb = $method->invoke($service, [
        ['title' => 'Аптеки', 'percent' => 5.0, 'category_id' => 1, 'mcc' => ''],
        ['title' => 'Кафе', 'percent' => 3.0, 'category_id' => null, 'mcc' => ''],
        ['title' => 'Кино', 'percent' => 10.0, 'category_id' => 2, 'mcc' => ''],
    ], 1); // active=1 (Кафе)

    // MARKUP: Широкая кнопка активного пункта (кафе, i=1) содержит ▼
    $wideCatButton1 = collect($kb)->flatten(1)->first(fn ($btn) => ($btn['callback_data'] ?? null) === 'cat:1' &&
        str_contains($btn['text'] ?? '', mb_strtoupper('Кафе')) &&
        str_contains($btn['text'] ?? '', '3%')
    );
    expect($wideCatButton1['text'])->toContain('▼');
    expect($wideCatButton1['text'])->not->toContain('▶');

    // UPPER: активная категория — название в верхнем регистре (визуальное выделение)
    expect($wideCatButton1['text'])->toContain(mb_strtoupper('Кафе'));

    // MARKUP: Свёрнутые пункты (Аптеки i=0, Кино i=2) содержат ▶
    $wideCatButton0 = collect($kb)->flatten(1)->first(fn ($btn) => ($btn['callback_data'] ?? null) === 'cat:0' &&
        str_contains($btn['text'] ?? '', 'Аптеки') &&
        str_contains($btn['text'] ?? '', '5%')
    );
    expect($wideCatButton0['text'])->toContain('▶');
    expect($wideCatButton0['text'])->not->toContain('▼');

    $wideCatButton2 = collect($kb)->flatten(1)->first(fn ($btn) => ($btn['callback_data'] ?? null) === 'cat:2' &&
        str_contains($btn['text'] ?? '', 'Кино') &&
        str_contains($btn['text'] ?? '', '10%')
    );
    expect($wideCatButton2['text'])->toContain('▶');
    expect($wideCatButton2['text'])->not->toContain('▼');
});

test('truncates long title in button label to 30 chars with ellipsis', function () {
    $service = app(TelegramConversationService::class);
    $method = new ReflectionMethod($service, 'buildEditorKeyboard');

    // Title длиннее 30 символов (40 символов)
    $longTitle = 'Очень длинное название категории которое должно быть обрезано';
    $kb = $method->invoke($service, [
        ['title' => $longTitle, 'percent' => 5.0, 'category_id' => 1],
    ]);

    // Находим кнопку cat:0
    $flatButtons = collect($kb)->flatten(1)->keyBy('callback_data');
    $buttonText = $flatButtons->get('cat:0')['text'];

    // Проверяем наличие "…" в тексте кнопки
    expect($buttonText)->toContain('…');

    // Извлекаем title-часть (between mark and percent)
    preg_match('/^(?:▶|▼)\s*✅\s*(.+?)\s+5%$/', $buttonText, $matches);
    $titleInButton = $matches[1] ?? '';

    // Длина title в кнопке должна быть ≤ 31 (30 + "…" уже учтена в тексте)
    expect(mb_strlen($titleInButton))->toBeLessThanOrEqual(31);
});
