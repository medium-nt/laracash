# Руководство Фулстэк-разработчика LaraCash

## Роль и ответственность

Фулстэк-разработчик отвечает за реализацию полной функциональности - от backend логики до frontend интерфейса. Основные задачи:

- **Backend разработка** - Laravel контроллеры, модели, API
- **Frontend разработка** - Blade шаблоны, Livewire компоненты, стили
- **База данных** - миграции, связи, оптимизация запросов
- **Интеграция** - связывание всех компонентов в единую систему
- **Качество кода** - следование стандартам и паттернам Laravel

## Технический стек проекта

### Backend
- **Laravel 11** - основной фреймворк
- **Eloquent ORM** - работа с базой данных
- **Livewire 3.6** - интерактивность без JavaScript
- **PestPHP 3.7** - тестирование
- **Laravel Policies** - авторизация и контроль доступа

### Frontend
- **Blade Templates** - основа шаблонизации
- **Bootstrap 5.2** - CSS фреймворк
- **AdminLTE 3.14** - админ панель и компоненты
- **Vite 6.0** - сборка фронтенда
- **JavaScript/Alpine.js** - интерактивность

### Database
- **MySQL** - основная база данных
- **Eloquent Relationships** - связи между моделями
- **User-scoped данные** - изоляция данных пользователей

## Структура приложения

```
LaraCash/
├── app/
│   ├── Http/Controllers/
│   │   ├── CardController.php      # Управление картами
│   │   ├── BankController.php      # Управление банками
│   │   ├── CategoryController.php  # Управление категориями
│   │   ├── CashbackController.php  # Управление кешбэками
│   │   └── SearchController.php    # Поиск и PWA
│   ├── Models/
│   │   ├── User.php                # Пользователь
│   │   ├── Bank.php                # Банк
│   │   ├── Card.php                # Карта
│   │   ├── Category.php            # Категория
│   │   └── Cashback.php            # Кешбэк
│   ├── Livewire/
│   │   ├── SearchComponent.php     # Живой поиск
│   │   └── CategorySearchComponent.php
│   └── Policies/                   # Политики доступа
├── database/
│   ├── migrations/                 # Миграции БД
│   └── seeders/                   # Тестовые данные
└── resources/
    ├── views/
    │   ├── cards/                 # Интерфейс карт
    │   ├── banks/                 # Интерфейс банков
    │   ├── categories/            # Интерфейс категорий
    │   └── cashback/              # Интерфейс кешбэков
    └── sass/                      # SCSS стили
```

## Ключевые архитектурные паттерны

### 1. User-Scoped Data Pattern
Все данные в системе привязаны к пользователям:

```php
// Всегда фильтруем по user_id
$cards = Card::where('user_id', auth()->user()->id)->get();

// В контроллерах
public function index()
{
    return view('cards.index', [
        'cards' => Card::query()->where('user_id', auth()->user()->id)->paginate(10)
    ]);
}
```

### 2. Policy-Based Authorization
Используем Laravel Policies для контроля доступа:

```php
// routes/web.php
Route::prefix('/cards')->middleware('auth')->group(function () {
    Route::get('', [CardController::class, 'index'])
        ->can('viewAny', Card::class);
    Route::post('', [CardController::class, 'store'])
        ->can('create', Card::class);
});
```

### 3. Livewire Reactivity Pattern
Интерактивные компоненты без перезагрузки страницы:

```php
// app/Livewire/SearchComponent.php
class SearchComponent extends Component
{
    public $search = '';
    public $results = [];

    protected $rules = [
        'search' => 'required|min:2|max:255'
    ];

    public function updatedSearch()
    {
        if (strlen($this->search) >= 2) {
            $this->results = Category::where('keywords', 'like', "%{$this->search}%")
                ->where('user_id', auth()->user()->id)
                ->limit(10)
                ->get();
        }
    }

    public function render()
    {
        return view('livewire.search-component');
    }
}
```

### 4. Telegram Mini App Integration
Валидация WebApp данных по официальной спецификации:

```php
// app/Http/Controllers/MiniAppController.php
private function validateTelegramInitData(string $initData, string $botToken): bool
{
    // Manual parsing для сохранения "+" символов
    $pairs = explode('&', $initData);
    $params = [];
    $hash = null;

    foreach ($pairs as $pair) {
        $eqPos = strpos($pair, '=');
        if ($eqPos === false) continue;

        $rawKey = substr($pair, 0, $eqPos);
        $rawVal = substr($pair, $eqPos + 1);

        $key = rawurldecode($rawKey); // NOT parse_str()
        $val = rawurldecode($rawVal);

        if ($key === 'hash') {
            $hash = $val;
            continue;
        }

        $params[$key] = $val;
    }

    // HMAC-SHA256 validation
    ksort($params);
    $dataCheckString = implode("\n", array_map(
        fn($k, $v) => $k . '=' . $v,
        array_keys($params),
        $params
    ));

    $secretKey = hash('sha256', $botToken, true);
    $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

    return hash_equals($calculatedHash, $hash);
}
```

## Стандарты разработки

### Naming Conventions
```php
// Контроллеры: ResourceController
class CardController extends Controller

// Модели: singular
class Bank extends Model

// Методы: camelCase
public function updateCashbackPercentage()

// Переменные: camelCase
$selectedCards = [];

// База данных: snake_case
$table->string('card_number');
$table->foreignId('user_id');
```

### Validation Patterns
```php
// Всегда валидируем входные данные
public function store(Request $request)
{
    $validated = $request->validate([
        'number' => 'required|string|max:255|min:2',
        'bank_id' => 'required|exists:banks,id',
        'color' => 'required|string|max:10|min:2',
    ]);

    Card::create($validated);
}
```

### Database Patterns
```php
// Миграции с правильными типами и индексами
Schema::create('cards', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('bank_id')->constrained()->onDelete('cascade');
    $table->string('number');
    $table->string('color');
    $table->json('cashback_json')->nullable();

    $table->index(['user_id', 'bank_id']);
    $table->timestamps();
});

// Модели с правильными связями
class Card extends Model
{
    protected $fillable = ['user_id', 'bank_id', 'number', 'color'];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'card_category_cashback')
            ->withPivot('cashback_percentage', 'mcc', 'updated_at');
    }
}
```

## Процесс работы с задачами

### 1. Анализ ТЗ от главного разработчика
```bash
# Пример задания:
"@fullstack-developer Реализовать экспорт кешбэков в Excel:
- Создать GET /cashback/export/{user}
- Кнопка экспорта в интерфейсе
- Фильтрация по датам и картам
- Использовать Laravel Excel"
```

### 2. Декомпозиция на подзадачи

**Backend часть:**
- Создать контроллер/метод для экспорта
- Реализовать фильтрацию данных
- Настроить Excel генерацию
- Добавить валидацию и права доступа

**Frontend часть:**
- Добавить кнопку в интерфейс кешбэков
- Создать форму фильтров
- Стилизация с Bootstrap
- Обработка ответа от сервера

**Интеграция:**
- Тестирование полного цикла
- Проверка прав доступа
- Обработка ошибок

### 3. Порядок реализации

**Шаг 1: Backend**
```php
// routes/web.php
Route::get('/cashback/export', [CashbackController::class, 'export'])
    ->can('viewAny', Cashback::class)
    ->name('cashback.export');

// app/Http/Controllers/CashbackController.php
public function export(Request $request)
{
    $request->validate([
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date|after:start_date',
        'cards' => 'nullable|array',
        'cards.*' => 'exists:cards,id'
    ]);

    // Экспорт логика
}
```

**Шаг 2: Frontend**
```blade
<!-- resources/views/cashback/index.blade.php -->
<button class="btn btn-success" onclick="showExportModal()">
    <i class="fas fa-file-excel"></i> Экспорт в Excel
</button>
```

**Шаг 3: Интеграция и тестирование**
- Проверить полный функционал
- Тестировать различные сценарии
- Проверить производительность

## Качество кода

### Code Review Checklist
- [ ] Следование Laravel Conventions
- [ ] Правильная валидация данных
- [ ] User-scoped запросы к БД
- [ ] Обработка ошибок
- [ ] Адекватная обработка больших объемов данных
- [ ] Безопасность (XSS, CSRF, SQL Injection)
- [ ] Правильное использование HTTP методов
- [ ] Читаемость и поддерживаемость

### Performance Guidelines
```php
// ✅ Хорошо: оптимизированные запросы
$cards = Card::with(['bank', 'categories'])
    ->where('user_id', auth()->user()->id)
    ->get();

// ❌ Плохо: N+1 проблема
$cards = Card::where('user_id', auth()->user()->id)->get();
foreach ($cards as $card) {
    $bank = $card->bank; // N+1 запрос
}

// ✅ Хорошо: пагинация
$cashbacks = Cashback::where('user_id', auth()->user()->id)
    ->paginate(20);
```

### Security Guidelines
```php
// Всегда проверяем права доступа
if ($card->user_id !== auth()->user()->id) {
    abort(403);
}

// Используем Laravel Policies
$this->authorize('update', $card);

// Защита от XSS в шаблонах
{{ $userInput }} // автоматическое экранирование
{!! $trustedHtml !!} // только для доверенного HTML
```

## Тестирование функционала

### Базовые тесты для новой функциональности
```php
// tests/Feature/CashbackExportTest.php
test('user can export cashbacks to excel', function () {
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->get(route('cashback.export'));

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('user cannot export other users cashbacks', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)
        ->get(route('cashback.export'));

    $response->assertStatus(200); // но только свои данные
});
```

## Интеграция с существующим кодом

### Добавление новых контроллеров
1. Создать контроллер: `php artisan make:controller NewFeatureController`
2. Добавить роуты в `routes/web.php` с проверками прав доступа
3. Создать политики доступа в `app/Policies/`
4. Создать Blade шаблоны в `resources/views/`
5. Добавить навигацию в существующие layout

### Расширение существующих моделей
```php
// Добавление связи
class Card extends Model
{
    public function newFeature(): HasMany
    {
        return $this->hasMany(NewFeature::class);
    }
}

// Добавление метода
public function exportableCashbacks()
{
    return $this->cashbacks()->where('exportable', true);
}
```

## Полезные команды

### Генерация кода
```bash
# Создание контроллера
php artisan make:controller NewFeatureController --resource

# Создание модели с миграцией и фабрикой
php artisan make:model NewFeature -m -f

# Создание политики
php artisan make:policy NewFeaturePolicy --model=NewFeature

# Создание миграции
php artisan make:migration add_new_field_to_table
```

### Тестирование
```bash
# Запуск всех тестов
./vendor/bin/pest

# Запуск конкретного теста
./vendor/bin/pest --filter ExportTest

# Анализ покрытия кода
./vendor/bin/pest --coverage
```

### Фронтенд
```bash
# Разработка
npm run dev

# Сборка
npm run build

# Форматирование
npm run lint
```

Это руководство поможет фулстэк-разработчику эффективно работать с проектом LaraCash, создавая качественный и поддерживаемый код.