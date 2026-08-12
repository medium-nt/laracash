<?php

use App\Models\Bank;
use App\Models\Card;
use App\Models\Cashback;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Services\AiService;
use App\Services\Bot\CashbackImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__FILE__);

beforeEach(function () {
    // Создаём роли для тестов (factory использует rand(1, 2))
    Role::create(['name' => 'user']);
    Role::create(['name' => 'admin']);

    // Create test user
    $this->user = User::factory()->create();
    $this->userId = $this->user->id;

    // Create bank for cards
    $this->bank = Bank::create([
        'title' => 'Test Bank',
        'user_id' => $this->userId,
    ]);

    // Create test card
    $this->card = Card::create([
        'user_id' => $this->userId,
        'bank_id' => $this->bank->id,
        'number' => '1234',
        'color' => '#000000',
    ]);
    $this->cardId = $this->card->id;
});

test('import делит распознанное на saved/skipped по категориям юзера', function () {
    // Arrange: create user's category
    Category::create([
        'title' => 'Аптеки',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    // Mock AiService to return fixed results
    $this->mock(AiService::class, function (MockInterface $mock) {
        $mock->shouldReceive('recognizeForImport')
            ->once()
            ->with($this->userId, \Mockery::type('string'))
            ->andReturn([
                ['category' => 'Аптеки', 'cashback' => 5],
                ['category' => 'Супермаркеты', 'cashback' => 3], // Not in user's categories
            ]);
    });

    // Act
    $import = new CashbackImportService(app(AiService::class));
    $result = $import->import($this->userId, $this->cardId, ['/tmp/x.png']);

    // Assert
    expect($result['saved'])->toHaveCount(1)
        ->and($result['saved'][0]['title'])->toBe('Аптеки')
        ->and($result['saved'][0]['category_id'])->not->toBeNull()
        ->and($result['saved'][0]['percent'])->toBe(5.0)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['title'])->toBe('Супермаркеты')
        ->and($result['skipped'][0]['percent'])->toBe(3.0)
        ->and($result['raw'])->toHaveCount(2)
        ->and($result['card_id'])->toBe($this->cardId);
});

test('import обрабатывает null от AI сервиса', function () {
    // Arrange: create user's category
    Category::create([
        'title' => 'Аптеки',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    // Mock AiService to return null (error case)
    $this->mock(AiService::class, function (MockInterface $mock) {
        $mock->shouldReceive('recognizeForImport')
            ->once()
            ->with($this->userId, \Mockery::type('string'))
            ->andReturn(null);
    });

    // Act
    $import = new CashbackImportService(app(AiService::class));
    $result = $import->import($this->userId, $this->cardId, ['/tmp/x.png']);

    // Assert
    expect($result['saved'])->toBeEmpty()
        ->and($result['skipped'])->toBeEmpty()
        ->and($result['raw'])->toBeEmpty()
        ->and($result['card_id'])->toBe($this->cardId);
});

test('apply создаёт недостающие категории и сохраняет cashback_json + pivot', function () {
    // Arrange: существующая категория пользователя
    $category = Category::create([
        'title' => 'Аптеки',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    $raw = [
        ['category' => 'Аптеки', 'cashback' => 5],
        ['category' => 'Супермаркеты', 'cashback' => 3], // нет у пользователя — будет создана
    ];

    // Act
    $import = new CashbackImportService(app(AiService::class));
    $result = $import->apply($this->userId, $this->cardId, $raw);

    // Assert: apply сообщает о созданной категории
    expect($result['created'])->toBe(['Супермаркеты']);

    // Assert: card.cashback_json is saved
    $card = Card::query()->where('id', $this->cardId)->firstOrFail();
    expect($card->cashback_json)->toBe($raw);

    // Assert: pivot table has entry for Аптеки (существующая)
    $cashback = Cashback::query()
        ->where('card_id', $this->cardId)
        ->where('category_id', $category->id)
        ->first();

    expect($cashback)->not->toBeNull()
        ->and($cashback->cashback_percentage)->toBe(5.0)
        ->and($cashback->mcc)->toBe('');

    // Assert: Супермаркеты создана и pivot запись есть
    $supermarket = Category::query()
        ->where('user_id', $this->userId)
        ->where('title', 'Супермаркеты')
        ->first();

    expect($supermarket)->not->toBeNull()
        ->and($supermarket->keywords)->toBe('Супермаркеты');

    $superCashback = Cashback::query()
        ->where('card_id', $this->cardId)
        ->where('category_id', $supermarket->id)
        ->first();

    expect($superCashback)->not->toBeNull()
        ->and($superCashback->cashback_percentage)->toBe(3.0);
});

test('apply не создаёт дубль при повторе категории в raw', function () {
    $raw = [
        ['category' => 'Доставка', 'cashback' => 5],
        ['category' => 'Доставка', 'cashback' => 7],
    ];

    $import = new CashbackImportService(app(AiService::class));
    $result = $import->apply($this->userId, $this->cardId, $raw);

    expect(Category::query()->where('user_id', $this->userId)->where('title', 'Доставка')->count())->toBe(1)
        ->and($result['created'])->toBe(['Доставка']);
});

test('apply присоединяет к существующей категории по нормализованному совпадению (без дубля)', function () {
    Category::create(['title' => 'Аптеки', 'user_id' => $this->userId, 'keywords' => '']);

    $raw = [['category' => '  АПТЕКИ  ', 'cashback' => 5]]; // другой регистр + пробелы

    $import = new CashbackImportService(app(AiService::class));
    $result = $import->apply($this->userId, $this->cardId, $raw);

    // Не создан дубль — категория одна
    expect(Category::query()->where('user_id', $this->userId)->where('title', 'Аптеки')->count())->toBe(1)
        ->and($result['created'])->toBe([]);
});

test('apply сохраняет mcc (примечание) из raw в pivot', function () {
    // Arrange: существующая категория пользователя
    $category = Category::create([
        'title' => 'Аптеки',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    $raw = [
        ['category' => 'Аптеки', 'cashback' => 5, 'mcc' => '5912, только по будням'],
    ];

    // Act
    $import = new CashbackImportService(app(AiService::class));
    $import->apply($this->userId, $this->cardId, $raw);

    // Assert: примечание сохранено в pivot
    $cashback = Cashback::query()
        ->where('card_id', $this->cardId)
        ->where('category_id', $category->id)
        ->first();

    expect($cashback)->not->toBeNull()
        ->and($cashback->mcc)->toBe('5912, только по будням');
});

test('apply без mcc в raw сохраняет пустую строку (обратная совместимость)', function () {
    // Arrange: существующая категория пользователя
    $category = Category::create([
        'title' => 'Аптеки',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    $raw = [
        ['category' => 'Аптеки', 'cashback' => 5], // без mcc — как от AI/старых ботов
    ];

    // Act
    $import = new CashbackImportService(app(AiService::class));
    $import->apply($this->userId, $this->cardId, $raw);

    // Assert: mcc пустая строка (колонка NOT NULL)
    $cashback = Cashback::query()
        ->where('card_id', $this->cardId)
        ->where('category_id', $category->id)
        ->first();

    expect($cashback)->not->toBeNull()
        ->and($cashback->mcc)->toBe('');
});

test('apply с пустым raw не перезаписывает cashback_json', function () {
    // Arrange: set initial cashback_json
    $initialJson = [['category' => 'Такси', 'cashback' => 10]];
    $this->card->update(['cashback_json' => $initialJson]);

    // Act
    $raw = [];
    $import = new CashbackImportService(app(AiService::class));
    $import->apply($this->userId, $this->cardId, $raw);

    // Assert: cashback_json должен остаться без изменений (BUG-1 fix)
    $card = Card::query()->where('id', $this->cardId)->firstOrFail();
    expect($card->cashback_json)->toBe($initialJson);
});

test('import обрабатывает несколько фото', function () {
    // Arrange: create user's categories
    Category::create([
        'title' => 'Аптеки',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);
    Category::create([
        'title' => 'АЗС',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    // Mock AiService to return different results for each call
    $this->mock(AiService::class, function (MockInterface $mock) {
        $mock->shouldReceive('recognizeForImport')
            ->ordered()
            ->with($this->userId, '/tmp/photo1.png')
            ->andReturn([
                ['category' => 'Аптеки', 'cashback' => 5],
            ]);

        $mock->shouldReceive('recognizeForImport')
            ->ordered()
            ->with($this->userId, '/tmp/photo2.png')
            ->andReturn([
                ['category' => 'АЗС', 'cashback' => 10],
            ]);
    });

    // Act
    $import = new CashbackImportService(app(AiService::class));
    $result = $import->import($this->userId, $this->cardId, ['/tmp/photo1.png', '/tmp/photo2.png']);

    // Assert
    expect($result['saved'])->toHaveCount(2)
        ->and($result['raw'])->toHaveCount(2);
});

test('apply с чужим userId бросает исключение (защита чужой карты)', function () {
    // Arrange: create another user (User B)
    $userB = User::factory()->create();
    $userBId = $userB->id;

    // Create category for User A to make raw valid
    Category::create([
        'title' => 'Аптеки',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    $raw = [
        ['category' => 'Аптеки', 'cashback' => 5],
    ];

    // Act & Assert: вызываем apply с userId=User B, но cardId=User A
    $import = new CashbackImportService(app(AiService::class));
    expect(fn () => $import->apply($userBId, $this->cardId, $raw))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('import матчит категорию без учёта регистра (нормализованный матчинг)', function () {
    Category::create(['title' => 'Аптеки', 'user_id' => $this->userId, 'keywords' => '']);

    $this->mock(AiService::class, function (MockInterface $mock) {
        $mock->shouldReceive('recognizeForImport')
            ->once()
            ->andReturn([
                ['category' => '  АПТЕКИ  ', 'cashback' => 5], // другой регистр + пробелы
            ]);
    });

    $import = new CashbackImportService(app(AiService::class));
    $result = $import->import($this->userId, $this->cardId, ['/tmp/x.png']);

    expect($result['saved'])->toHaveCount(1)
        ->and($result['saved'][0]['title'])->toBe('Аптеки') // каноничный
        ->and($result['saved'][0]['category_id'])->not->toBeNull()
        ->and($result['skipped'])->toBeEmpty();
});
