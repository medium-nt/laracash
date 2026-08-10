<?php

use App\Models\Category;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Активируем RefreshDatabase для этого файла
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__FILE__);

beforeEach(function () {
    // Создаём тестового пользователя
    $this->userId = 1;
});

test('точное совпадение по title заменяет категорию', function () {
    // Arrange
    Category::create([
        'title' => 'АЗС',
        'user_id' => $this->userId,
        'keywords' => 'бензин заправка топливо',
    ]);

    $recognized = [
        ['category' => 'АЗС', 'cashback' => 5.0],
        ['category' => 'Супермаркеты', 'cashback' => 3.0],
    ];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert
    expect($result[0]['category'])->toBe('АЗС')
        ->and($result[1]['category'])->toBe('Супермаркеты'); // Без изменений
});

test('совпадение по синониму keywords заменяет категорию', function () {
    // Arrange
    Category::create([
        'title' => 'АЗС',
        'user_id' => $this->userId,
        'keywords' => 'бензин заправка топливо',
    ]);

    $testCases = [
        'бензин' => 'АЗС',
        'заправка' => 'АЗС',
        'топливо' => 'АЗС',
    ];

    foreach ($testCases as $input => $expected) {
        $recognized = [['category' => $input, 'cashback' => 5.0]];

        // Act
        $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

        // Assert
        expect($result[0]['category'])->toBe($expected);
    }
});

test('совпадение по вхождению подстроки заменяет категорию', function () {
    // Arrange
    Category::create([
        'title' => 'Кино',
        'user_id' => $this->userId,
        'keywords' => 'Кинотеатры',
    ]);

    $testCases = [
        'Кинотеатры' => 'Кино', // needle содержит title
        'Кино' => 'Кино',       // title содержит needle (точное)
    ];

    foreach ($testCases as $input => $expected) {
        $recognized = [['category' => $input, 'cashback' => 5.0]];

        // Act
        $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

        // Assert
        expect($result[0]['category'])->toBe($expected);
    }
});

test('сходство >= порога заменяет категорию', function () {
    // Arrange
    Category::create([
        'title' => 'Такси',
        'user_id' => $this->userId,
        'keywords' => 'Яндекс Такси',
    ]);

    // "такси!" vs "Такси" - после нормализации "такси!" vs "такси"
    // similar_text сработает с высокой вероятностью
    $recognized = [
        ['category' => 'такси!', 'cashback' => 5.0],
        ['category' => 'такси ', 'cashback' => 3.0], // с пробелом
    ];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert
    expect($result[0]['category'])->toBe('Такси')
        ->and($result[1]['category'])->toBe('Такси');
});

test('сходство ниже порога оставляет категорию без изменений', function () {
    // Arrange
    Category::create([
        'title' => 'Одежда и Обувь',
        'user_id' => $this->userId,
        'keywords' => '',
    ]);

    // "Рестораны" vs "Одежда и Обувь" - низкое сходство
    $recognized = [['category' => 'Рестораны', 'cashback' => 5.0]];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert
    expect($result[0]['category'])->toBe('Рестораны'); // Без изменений
});

test('отсутствие совпадения оставляет категорию без изменений', function () {
    // Arrange
    Category::create([
        'title' => 'АЗС',
        'user_id' => $this->userId,
        'keywords' => 'бензин заправка',
    ]);

    Category::create([
        'title' => 'Супермаркеты',
        'user_id' => $this->userId,
        'keywords' => 'еда продукты',
    ]);

    $recognized = [['category' => 'Космос', 'cashback' => 10.0]];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert
    expect($result[0]['category'])->toBe('Космос'); // Без изменений
});

test('смешанный массив корректно сопоставляет категории', function () {
    // Arrange
    Category::create([
        'title' => 'АЗС',
        'user_id' => $this->userId,
        'keywords' => 'бензин заправка',
    ]);

    Category::create([
        'title' => 'Супермаркеты',
        'user_id' => $this->userId,
        'keywords' => 'еда продукты магнит',
    ]);

    Category::create([
        'title' => 'Кафе и рестораны',
        'user_id' => $this->userId,
        'keywords' => 'Столовая',
    ]);

    $recognized = [
        ['category' => 'АЗС', 'cashback' => 5.0],           // Точное совпадение
        ['category' => 'бензин', 'cashback' => 3.0],        // Keywords
        ['category' => 'Супермаркеты', 'cashback' => 2.0],  // Точное совпадение
        ['category' => 'продукты', 'cashback' => 1.5],      // Keywords
        ['category' => 'Столовая', 'cashback' => 4.0],      // Keywords
        ['category' => 'Космос', 'cashback' => 10.0],       // Нет совпадения
    ];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert
    expect($result[0]['category'])->toBe('АЗС')
        ->and($result[1]['category'])->toBe('АЗС')
        ->and($result[2]['category'])->toBe('Супермаркеты')
        ->and($result[3]['category'])->toBe('Супермаркеты')
        ->and($result[4]['category'])->toBe('Кафе и рестораны')
        ->and($result[5]['category'])->toBe('Космос'); // Без изменений
});

test('нормализация регистра и пробелов корректно сопоставляет', function () {
    // Arrange
    Category::create([
        'title' => 'АЗС',
        'user_id' => $this->userId,
        'keywords' => 'бензин заправка',
    ]);

    $testCases = [
        '  азс  ' => 'АЗС',     // Пробелы + нижний регистр
        'АЗС' => 'АЗС',          // Верхний регистр
        ' аЗс ' => 'АЗС',       // Смешанный регистр + пробелы
    ];

    foreach ($testCases as $input => $expected) {
        $recognized = [['category' => $input, 'cashback' => 5.0]];

        // Act
        $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

        // Assert
        expect($result[0]['category'])->toBe($expected);
    }
});

test('пустые категории пользователя не вызывают ошибок', function () {
    // Arrange - нет категорий пользователя
    $recognized = [
        ['category' => 'АЗС', 'cashback' => 5.0],
        ['category' => 'Супермаркеты', 'cashback' => 3.0],
    ];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert
    expect($result[0]['category'])->toBe('АЗС')
        ->and($result[1]['category'])->toBe('Супермаркеты');
});

test('невалидные элементы массива пропускаются', function () {
    // Arrange
    Category::create([
        'title' => 'АЗС',
        'user_id' => $this->userId,
        'keywords' => 'бензин',
    ]);

    $recognized = [
        ['category' => 'АЗС', 'cashback' => 5.0],
        ['cashback' => 3.0],               // Нет category
        ['category' => 'бензин'],          // Нет cashback
        null,                               // null вместо массива
        'строка',                           // Строка вместо массива
        ['category' => 'бензин', 'cashback' => 2.0],
    ];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert
    expect($result[0]['category'])->toBe('АЗС')
        ->and($result[5]['category'])->toBe('АЗС');
});

test('категории другого пользователя не влияют на сопоставление', function () {
    // Arrange
    Category::create([
        'title' => 'АЗС',
        'user_id' => 1,
        'keywords' => 'бензин',
    ]);

    Category::create([
        'title' => 'АЗС Другого',
        'user_id' => 2,  // Другой пользователь
        'keywords' => 'топливо',
    ]);

    $recognized = [['category' => 'бензин', 'cashback' => 5.0]];

    // Act - ищем для пользователя 1
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, 1]);

    // Assert - должно найтись АЗС пользователя 1
    expect($result[0]['category'])->toBe('АЗС');
});

test('короткая распознанная строка не матчится по подстроке', function () {
    // Arrange: союз «и» содержится в названии, но не должен матчить категорию.
    Category::create([
        'title' => 'Кафе и рестораны',
        'user_id' => $this->userId,
        'keywords' => 'Столовая',
    ]);

    $recognized = [['category' => 'и', 'cashback' => 5.0]];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert: короткая строка (< MIN_SUBSTRING_LENGTH) не прошла через шаг вхождения
    expect($result[0]['category'])->toBe('и'); // без изменений
});

test('конкурирующие названия матчатся точно, без ложного вхождения', function () {
    // Arrange: «Авто» содержится в «Автозапчасти» — возможен ложный матч по подстроке.
    Category::create(['title' => 'Авто', 'user_id' => $this->userId, 'keywords' => '']);
    Category::create(['title' => 'Автозапчасти', 'user_id' => $this->userId, 'keywords' => '']);

    $recognized = [
        ['category' => 'Автозапчасти', 'cashback' => 5.0], // точное -> «Автозапчасти»
        ['category' => 'Авто', 'cashback' => 3.0],         // точное -> «Авто»
    ];

    // Act
    $result = callPrivateMethod(AiService::class, 'mapToUserCategories', [$recognized, $this->userId]);

    // Assert: каждая строка матчачится со своей категорией, а не с чужой через подстроку
    expect($result[0]['category'])->toBe('Автозапчасти')
        ->and($result[1]['category'])->toBe('Авто');
});

test('recognize возвращает распознанный массив без auth-сессии', function () {
    // Arrange
    Category::create(['title' => 'Аптеки', 'user_id' => 1, 'keywords' => '']);

    // Create temporary test file
    $testFilePath = storage_path('app/test/photo.png');
    if (! file_exists(dirname($testFilePath))) {
        mkdir(dirname($testFilePath), 0755, true);
    }
    file_put_contents($testFilePath, 'fake image content');

    // Fake sequence: oauth → files upload → chat/completions
    Http::fakeSequence()
        ->push(['access_token' => 'test_token'], 200) // getToken - oauth
        ->push(['id' => 'file_123'], 200) // downloadFile - upload
        ->push([
            'choices' => [[
                'message' => ['content' => json_encode([['category' => 'Аптеки', 'cashback' => 5]])],
            ]],
        ], 200); // recognize - chat/completions

    // Act
    $result = callPrivateMethod(AiService::class, 'recognize', [1, $testFilePath]);

    // Assert
    expect($result)->not->toBeNull()
        ->and($result[0]['category'])->toBe('Аптеки')
        ->and($result[0]['cashback'])->toBe(5);

    // Cleanup
    if (file_exists($testFilePath)) {
        unlink($testFilePath);
    }
});

/**
 * Вспомогательная функция для вызова приватного static метода.
 */
function callPrivateMethod(string $class, string $method, array $args = [])
{
    $reflection = new ReflectionClass($class);
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs(null, $args);
}
