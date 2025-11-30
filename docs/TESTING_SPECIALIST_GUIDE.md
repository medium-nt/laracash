# Руководство Специалиста по тестированию LaraCash

## Роль и ответственность

Специалист по тестированию отвечает за обеспечение качества всего функционала проекта. Основные задачи:

- **Разработка тестов** - создание автоматических тестов для всей функциональности
- **Покрытие кода** - обеспечение высокого процента покрытия тестами
- **Тестирование функциональности** - проверка бизнес-логики и пользовательских сценариев
- **Интеграционное тестирование** - проверка взаимодействия компонентов
- **Нагрузочное тестирование** - проверка производительности при больших объемах данных

## Технологический стек тестирования

### Основные инструменты
- **PestPHP 3.7** - основной фреймворк тестирования
- **Laravel TestKit** - инструменты для тестирования Laravel приложений
- **SQLite In-Memory** - быстрая тестовая база данных
- **Laravel Factories** - генерация тестовых данных
- **Faker** - генерация случайных данных

### Типы тестов
```php
// Unit тесты - тестирование отдельных классов/методов
tests/Unit/
├── Models/
│   ├── UserTest.php
│   ├── CardTest.php
│   └── CashbackTest.php
├── Services/
└── Helpers/

// Feature тесты - тестирование функциональности
tests/Feature/
├── Authentication/
│   ├── LoginTest.php
│   ├── RegistrationTest.php
│   └── ProfileTest.php
├── Cards/
│   ├── CardManagementTest.php
│   ├── CardCreationTest.php
│   └── CardDeletionTest.php
├── Cashback/
│   ├── CashbackCalculationTest.php
│   ├── CashbackExportTest.php
│   └── CashbackSearchTest.php
└── Telegram/
    ├── MiniAppValidationTest.php
    └── WebhookTest.php
```

## Архитектура тестирования в LaraCash

### 1. Структура тестовой базы данных

```php
// tests/CreatesApplication.php
trait CreatesApplication
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Используем SQLite для быстроты тестов
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        return $app;
    }
}
```

### 2. Фабрики тестовых данных

```php
// database/factories/UserFactory.php
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role_id' => Role::where('name', 'user')->first()->id,
            'search_token' => Str::random(32),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::where('name', 'admin')->first()->id,
        ]);
    }
}

// database/factories/CardFactory.php
class CardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_id' => Bank::factory(),
            'number' => fake()->creditCardNumber(),
            'color' => fake()->hexColor(),
            'cashback_json' => null,
        ];
    }
}
```

### 3. Тестовые трейты для общих операций

```php
// tests/Traits/HasAuthenticatedUser.php
trait HasAuthenticatedUser
{
    protected User $user;

    protected function authenticateUser(?User $user = null): User
    {
        $this->user = $user ?? User::factory()->create();
        $this->actingAs($this->user);

        return $this->user;
    }

    protected function authenticateAdmin(): User
    {
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);

        return $this->user;
    }
}

// tests/Traits/HasTestCards.php
trait HasTestCards
{
    protected function createTestCards(int $count = 3, ?User $user = null): Collection
    {
        return Card::factory()
            ->count($count)
            ->create(['user_id' => $user ?? $this->user->id]);
    }

    protected function createTestCashbacks(int $count = 5, ?Card $card = null): Collection
    {
        return Cashback::factory()
            ->count($count)
            ->create(['card_id' => $card ?? $this->user->cards->first()]);
    }
}
```

## Паттерны тестирования

### 1. Тестирование CRUD операций

```php
// tests/Feature/Cards/CardManagementTest.php
class CardManagementTest extends TestCase
{
    use HasAuthenticatedUser;
    use HasTestCards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateUser();
    }

    test('user can view their cards', function () {
        $cards = $this->createTestCards(3);

        $response = $this->get(route('cards.index'));

        $response->assertStatus(200);
        $response->assertViewIs('cards.index');

        foreach ($cards as $card) {
            $response->assertSee($card->number);
        }
    });

    test('user cannot view other users cards', function () {
        $otherUser = User::factory()->create();
        $otherCard = Card::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->get(route('cards.index'));

        $response->assertStatus(200);
        $response->assertDontSee($otherCard->number);
    });

    test('user can create new card', function () {
        $bank = Bank::factory()->create(['user_id' => $this->user->id]);

        $cardData = [
            'number' => '4242424242424242',
            'bank_id' => $bank->id,
            'color' => '#FF0000'
        ];

        $response = $this->post(route('cards.store'), $cardData);

        $response->assertRedirect(route('cards.index'));
        $response->assertSessionHas('success', 'Карта добавлена');

        $this->assertDatabaseHas('cards', [
            'user_id' => $this->user->id,
            'number' => $cardData['number']
        ]);
    });

    test('card creation validates input', function () {
        $response = $this->post(route('cards.store'), [
            'number' => '',
            'bank_id' => '',
            'color' => ''
        ]);

        $response->assertSessionHasErrors(['number', 'bank_id', 'color']);
    });
}
```

### 2. Тестирование политик доступа

```php
// tests/Feature/Policies/CardPolicyTest.php
class CardPolicyTest extends TestCase
{
    use HasAuthenticatedUser;
    use HasTestCards;

    test('user can view their own cards', function () {
        $card = $this->createTestCards(1)->first();

        $this->assertTrue($this->user->can('view', $card));
        $this->assertTrue($this->user->can('update', $card));
        $this->assertTrue($this->user->can('delete', $card));
    });

    test('user cannot access other users cards', function () {
        $otherUser = User::factory()->create();
        $otherCard = Card::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->user->can('view', $otherCard));
        $this->assertFalse($this->user->can('update', $otherCard));
        $this->assertFalse($this->user->can('delete', $otherCard));
    });

    test('admin can view all cards', function () {
        $this->authenticateAdmin();
        $userCard = Card::factory()->create();

        $this->assertTrue($this->user->can('viewAny', Card::class));
        $this->assertTrue($this->user->can('view', $userCard));
    });
}
```

### 3. Тестирование API эндпоинтов

```php
// tests/Feature/Telegram/MiniAppValidationTest.php
class MiniAppValidationTest extends TestCase
{
    test('valid telegram webapp data is accepted', function () {
        $validInitData = $this->generateValidTelegramInitData();

        $response = $this->post('/api/mini-app-data', [
            'initData' => $validInitData['data'],
            'data' => ['test' => 'payload']
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    });

    test('invalid telegram webapp data is rejected', function () {
        $invalidInitData = 'invalid=data&hash=wrong_hash';

        $response = $this->post('/api/mini-app-data', [
            'initData' => $invalidInitData,
            'data' => ['test' => 'payload']
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized']);
    });

    protected function generateValidTelegramInitData(): array
    {
        $botToken = config('services.telegram.bot_token');
        $queryId = '123456789';
        $user = json_encode(['id' => 123, 'first_name' => 'Test']);
        $authDate = time();

        $dataCheckString = [
            "query_id={$queryId}",
            "user={$user}",
            "auth_date={$authDate}"
        ];

        $dataCheckString = implode("\n", $dataCheckString);
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $initData = implode('&', [
            "query_id={$queryId}",
            "user={$user}",
            "auth_date={$authDate}",
            "hash={$hash}"
        ]);

        return ['data' => $initData, 'hash' => $hash];
    }
}
```

### 4. Тестирование бизнес-логики кешбэков

```php
// tests/Feature/Cashback/CashbackCalculationTest.php
class CashbackCalculationTest extends TestCase
{
    use HasAuthenticatedUser;
    use HasTestCards;

    test('cashback calculation works correctly', function () {
        $card = $this->createTestCards(1)->first();
        $category = Category::factory()->create(['user_id' => $this->user->id]);

        // Создаем кешбэк: 5% для категории
        $cashback = Cashback::factory()->create([
            'card_id' => $card->id,
            'category_id' => $category->id,
            'cashback_percentage' => 5.0
        ]);

        $calculation = new CashbackCalculator();
        $result = $calculation->calculate($card, $category, 1000); // 1000 руб.

        $this->assertEquals(50, $result); // 5% от 1000 = 50
    });

    test('cashback priority system works', function () {
        $card = $this->createTestCards(1)->first();
        $category = Category::factory()->create(['user_id' => $this->user->id]);

        // Создаем два кешбэка
        $lowPriorityCashback = Cashback::factory()->create([
            'card_id' => $card->id,
            'category_id' => $category->id,
            'cashback_percentage' => 1.0
        ]);

        $highPriorityCashback = Cashback::factory()->create([
            'card_id' => $card->id,
            'category_id' => $category->id,
            'cashback_percentage' => 5.0
        ]);

        $calculator = new CashbackCalculator();
        $bestCashback = $calculator->getBestCashback($card, $category);

        $this->assertEquals($highPriorityCashback->id, $bestCashback->id);
    });
}
```

### 5. Тестирование производительности

```php
// tests/Performance/CashbackSearchPerformanceTest.php
class CashbackSearchPerformanceTest extends TestCase
{
    use HasAuthenticatedUser;

    test('search performs well with large datasets', function () {
        // Создаем большой объем данных
        User::factory()->count(100)->create();
        Category::factory()->count(500)->create();
        Cashback::factory()->count(5000)->create();

        $startTime = microtime(true);

        $response = $this->get('/search/test-token');

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $response->assertStatus(200);
        $this->assertLessThan(2.0, $executionTime, 'Search should complete in under 2 seconds');
    });

    test('cashback export handles large datasets efficiently', function () {
        $this->authenticateUser();
        $card = $this->createTestCards(1)->first();

        // Создаем много кешбэков для экспорта
        Cashback::factory()->count(1000)->create(['card_id' => $card->id]);

        $startTime = microtime(true);

        $response = $this->get(route('cashback.export'));

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $response->assertStatus(200);
        $this->assertLessThan(10.0, $executionTime, 'Export should complete in under 10 seconds');
    });
}
```

## Стратегия покрытия кода

### Целевые показатели покрытия
```php
// phpunit.xml
<coverage>
    <include>
        <directory>./app</directory>
    </include>
    <exclude>
        <directory>./app/Console</directory>
        <directory>./app/Exceptions</directory>
    </exclude>
    <report>
        <html outputDirectory="build/coverage"/>
    </report>
</coverage>
```

### Минимальные требования:
- **Models**: 95% покрытие
- **Controllers**: 90% покрытие
- **Services**: 85% покрытие
- **Overall**: 80% покрытие

### Проверка покрытия
```bash
# Запуск с анализом покрытия
./vendor/bin/pest --coverage --min=80

# Детальный отчет
./vendor/bin/pest --coverage --coverage-html=storage/coverage

# Проверка конкретного файла
./vendor/bin/pest --coverage --filter CardTest
```

## Процесс тестирования новых функций

### 1. Анализ задачи от главного разработчика
```bash
# Пример задания:
"@testing-specialist Протестировать функцию экспорта кешбэков:
- Функциональные тесты экспорта
- Тесты фильтрации по датам
- Тесты прав доступа
- Нагрузочные тесты
- Покрытие > 90%"
```

### 2. Декомпозиция тестов

**Функциональные тесты:**
- Успешный экспорт данных пользователя
- Экспорт с различными фильтрами
- Обработка пустых результатов
- Обработка некорректных параметров

**Тесты доступа:**
- Пользователь может экспортировать свои данные
- Пользователь не может экспортировать чужие данные
- Неавторизованный доступ отклоняется

**Производительность:**
- Экспорт большого объема данных
- Память при экспорте
- Время выполнения

### 3. Реализация тестов

```php
// tests/Feature/Cashback/CashbackExportTest.php
class CashbackExportTest extends TestCase
{
    use HasAuthenticatedUser;
    use HasTestCards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateUser();
    }

    test('user can export their cashbacks', function () {
        $card = $this->createTestCards(1)->first();
        $cashbacks = $this->createTestCashbacks(50, $card);

        $response = $this->get(route('cashback.export'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Проверяем, что это бинарный файл Excel
        $this->assertStringContainsString(
            'PK',
            $response->getContent()
        );
    });

    test('export respects date filters', function () {
        $card = $this->createTestCards(1)->first();

        // Старые кешбэки
        Cashback::factory()->count(10)->create([
            'card_id' => $card->id,
            'created_at' => now()->subDays(30)
        ]);

        // Новые кешбэки
        Cashback::factory()->count(5)->create([
            'card_id' => $card->id,
            'created_at' => now()->subDays(5)
        ]);

        $response = $this->get(route('cashback.export'), [
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->toDateString()
        ]);

        $response->assertStatus(200);
        // Проверяем, что в экспорте только новые данные
        $this->assertLessThan(50, strlen($response->getContent()));
    });

    test('export validates input parameters', function () {
        $response = $this->get(route('cashback.export'), [
            'start_date' => 'invalid-date',
            'end_date' => 'invalid-date'
        ]);

        $response->assertSessionHasErrors(['start_date', 'end_date']);
    });
}
```

### 4. Запуск и анализ покрытия

```bash
# Запуск всех тестов экспорта
./vendor/bin/pest --filter CashbackExportTest

# Анализ покрытия
./vendor/bin/pest --coverage --filter CashbackExportTest

# Проверка общего покрытия
./vendor/bin/pest --coverage --min=80
```

## Интеграционное тестирование

### Тестирование Livewire компонентов
```php
// tests/Feature/Livewire/SearchComponentTest.php
class SearchComponentTest extends TestCase
{
    use HasAuthenticatedUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateUser();
    }

    test('search component filters categories correctly', function () {
        Category::factory()->create([
            'user_id' => $this->user->id,
            'keywords' => 'супермаркет продукты'
        ]);

        Category::factory()->create([
            'user_id' => $this->user->id,
            'keywords' => 'бензин заправка'
        ]);

        $component = Livewire::test(SearchComponent::class);

        $component->set('search', 'супермаркет');
        $component->assertSee('супермаркет продукты');
        $component->assertDontSee('бензин заправка');
    });

    test('search respects debounce timing', function () {
        $component = Livewire::test(SearchComponent::class);

        $startTime = microtime(true);

        $component->set('search', 'test');
        $component->set('search', 'test2'); // должна отменить предыдущий поиск

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Debounce должен предотвратить мгновенные запросы
        $this->assertLessThan(0.5, $executionTime);
    });
}
```

## Автоматизация тестирования

### GitHub Actions для CI/CD
```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: testing
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

    steps:
    - uses: actions/checkout@v4

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
        extensions: mbstring, pdo_mysql
        coverage: xdebug

    - name: Install dependencies
      run: composer install --prefer-dist --no-progress

    - name: Copy environment file
      run: cp .env.testing .env

    - name: Generate key
      run: php artisan key:generate

    - name: Run migrations
      run: php artisan migrate --force

    - name: Run tests
      run: ./vendor/bin/pest --coverage

    - name: Upload coverage
      uses: codecov/codecov-action@v3
```

## Полезные команды

### Базовые команды тестирования
```bash
# Запуск всех тестов
./vendor/bin/pest

# Запуск с покрытием
./vendor/bin/pest --coverage

# Запуск конкретного теста
./vendor/bin/pest --filter CardCreationTest

# Запуск тестов для конкретного файла
./vendor/bin/pest tests/Feature/Cards

# Параллельный запуск
./vendor/bin/pest --parallel

# Генерация отчета
./vendor/bin/pest --coverage-html=storage/coverage
```

### Разработка тестов
```bash
# Создание теста
php artisan pest:test NewFeatureTest

# Создание теста для модели
php artisan pest:model User

# Создание теста для API
php artisan pest:test Api/ExampleApiTest
```

Это руководство поможет специалисту по тестированию обеспечить высокое качество кода в проекте LaraCash через систематическое и всестороннее тестирование всей функциональности.