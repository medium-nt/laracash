# Правила и стандарты разработки LaraCash

## Общие принципы

### 1. Философия разработки
- **Простота превыше сложности** - выбираем самые простые работающие решения
- **Качество превыше скорости** - пишем чистый, поддерживаемый код
- **Безопасность превыше удобства** - всегда защищаем данные пользователей
- **Тестируемость превыше фич** - любой функционал должен быть протестирован

### 2. Принципы архитектуры
- **User-scoped данные** - все пользовательские данные изолированы
- **Policy-based доступ** - контроль доступа через Laravel Policies
- **Thin контроллеры** - минимальная логика в контроллерах
- **Fat модели** - основная бизнес-логика в моделях
- **DRY (Don't Repeat Yourself)** - избегаем дублирования кода

## Стандарты кодирования

### 1. Laravel Conventions

#### Контроллеры
```php
// ✅ Хорошо: Resource Controller с правильными методами
class CardController extends Controller
{
    public function index(): View
    {
        return view('cards.index', [
            'title' => 'Карты',
            'cards' => Card::query()
                ->where('user_id', auth()->user()->id)
                ->with('bank')
                ->paginate(10)
        ]);
    }

    public function create(): View
    {
        return view('cards.create', [
            'title' => 'Добавить карту',
            'banks' => Bank::query()
                ->where('user_id', auth()->user()->id)
                ->get()
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'number' => 'required|string|max:255|min:2',
            'bank_id' => 'required|exists:banks,id',
            'color' => 'required|string|max:10|min:2',
        ]);

        Card::create([
            'user_id' => auth()->user()->id,
            ...$validated
        ]);

        return redirect()
            ->route('cards.index')
            ->with('success', 'Карта добавлена');
    }
}

// ❌ Плохо: сложная логика в контроллере
class CardController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $card = new Card();
        $card->user_id = auth()->user()->id;
        $card->number = $request->input('number');
        $card->bank_id = $request->input('bank_id');
        $card->color = $request->input('color');

        if ($card->number == '4242424242424242') {
            // Сложная логика проверки
        }

        $card->save();

        return redirect()->route('cards.index');
    }
}
```

#### Модели
```php
// ✅ Хорошо: правильные отношения и fillable
class Card extends Model
{
    protected $fillable = [
        'user_id',
        'bank_id',
        'number',
        'color',
        'cashback_json'
    ];

    protected $casts = [
        'cashback_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public $timestamps = false;

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'card_category_cashback')
            ->withPivot('cashback_percentage', 'mcc', 'updated_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getFormattedNumberAttribute(): string
    {
        return '**** **** **** ' . substr($this->number, -4);
    }

    // Scopes
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeWithBank($query)
    {
        return $query->with('bank');
    }
}

// ❌ Плохо: неправильная организация
class Card extends Model
{
    protected $fillable = ['*']; // Небезопасно

    public function getCard()
    {
        return Card::find($this->id); // Избыточно
    }
}
```

### 2. Валидация

#### Всегда валидируем входные данные
```php
// ✅ Хорошо: строгая валидация
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'title' => 'required|string|max:255|min:3',
        'description' => 'nullable|string|max:1000',
        'bank_id' => 'required|exists:banks,id',
        'cards' => 'required|array|min:1',
        'cards.*' => 'exists:cards,id',
        'start_date' => 'nullable|date|before:end_date',
        'end_date' => 'nullable|date|after:start_date'
    ]);

    // Работаем только с проверенными данными
    $category = Category::create([
        'user_id' => auth()->user()->id,
        ...$validated
    ]);

    return redirect()
        ->route('categories.index')
        ->with('success', 'Категория создана');
}

// ❌ Плохо: отсутствие валидации
public function store(Request $request): RedirectResponse
{
    $category = new Category();
    $category->title = $request->title; // Не проверено
    $category->user_id = auth()->user()->id;
    $category->save();
}
```

### 3. User-Scoped Data Pattern

#### Всегда фильтруем по пользователю
```php
// ✅ Хорошо: явная фильтрация
class CardController extends Controller
{
    public function index(): View
    {
        return view('cards.index', [
            'cards' => Card::query()
                ->where('user_id', auth()->user()->id) // Обязательно
                ->with('bank')
                ->paginate(10)
        ]);
    }

    public function update(Request $request, Card $card): RedirectResponse
    {
        // Дополнительная проверка на всякий случай
        if ($card->user_id !== auth()->user()->id) {
            abort(403);
        }

        $card->update($request->validated());
        return redirect()->route('cards.index');
    }
}

// ❌ Плохо: нет фильтрации
class CardController extends Controller
{
    public function index(): View
    {
        return view('cards.index', [
            'cards' => Card::all() // Получает ВСЕ карты!
        ]);
    }
}
```

#### Policies для контроля доступа
```php
// ✅ Хорошо: использование Policies
// routes/web.php
Route::prefix('/cards')->middleware('auth')->group(function () {
    Route::get('', [CardController::class, 'index'])
        ->can('viewAny', Card::class);
    Route::get('/{card}', [CardController::class, 'show'])
        ->can('view', 'card');
    Route::post('', [CardController::class, 'store'])
        ->can('create', Card::class);
    Route::put('/{card}', [CardController::class, 'update'])
        ->can('update', 'card');
    Route::delete('/{card}', [CardController::class, 'destroy'])
        ->can('delete', 'card');
});

// app/Policies/CardPolicy.php
class CardPolicy
{
    public function view(User $user, Card $card): bool
    {
        return $card->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true; // Все аутентифицированные пользователи могут создавать
    }

    public function update(User $user, Card $card): bool
    {
        return $card->user_id === $user->id;
    }

    public function delete(User $user, Card $card): bool
    {
        return $card->user_id === $user->id;
    }

    public function viewAny(User $user): bool
    {
        return true; // Все могут просматривать свои карты
    }
}
```

### 4. Оптимизация запросов

#### Избегаем N+1 проблем
```php
// ✅ Хорошо: eager loading
class CardController extends Controller
{
    public function index(): View
    {
        return view('cards.index', [
            'cards' => Card::query()
                ->where('user_id', auth()->user()->id)
                ->with(['bank', 'categories']) // Загружаем связи заранее
                ->paginate(10)
        ]);
    }
}

// ❌ Плохо: N+1 проблема
class CardController extends Controller
{
    public function index(): View
    {
        $cards = Card::where('user_id', auth()->user()->id)->get();

        foreach ($cards as $card) {
            $card->bank; // Отдельный запрос для каждой карты!
        }

        return view('cards.index', compact('cards'));
    }
}
```

#### Оптимизация для больших данных
```php
// ✅ Хорошо: пагинация и ограничения
class CashbackController extends Controller
{
    public function index(): View
    {
        return view('cashback.index', [
            'cashbacks' => Cashback::query()
                ->where('user_id', auth()->user()->id)
                ->with(['card.bank', 'category'])
                ->orderBy('created_at', 'desc')
                ->paginate(50) // Ограничиваем количество записей
        ]);
    }

    public function export(Request $request)
    {
        // Для экспорта используем chunk для больших объемов
        $filename = 'cashbacks_' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($request) {
            Cashback::query()
                ->where('user_id', auth()->user()->id)
                ->when($request->start_date, fn($q) => $q->whereDate('created_at', '>=', $request->start_date))
                ->when($request->end_date, fn($q) => $q->whereDate('created_at', '<=', $request->end_date))
                ->chunk(1000, function ($cashbacks) {
                    // Обрабатываем по 1000 записей за раз
                    foreach ($cashbacks as $cashback) {
                        // Запись в файл
                    }
                });
        }, $filename);
    }
}
```

### 5. Livewire паттерны

#### Реактивность без перезагрузки
```php
// ✅ Хорошо: оптимальная работа с Livewire
class SearchComponent extends Component
{
    public $search = '';
    public $results = [];
    public $loading = false;

    protected $rules = [
        'search' => 'required|min:2|max:255'
    ];

    // Debounce для уменьшения нагрузки
    protected $queryString = [
        'search' => ['except' => '']
    ];

    public function updatedSearch($value)
    {
        $this->loading = true;

        if (strlen($value) >= 2) {
            $this->results = Category::query()
                ->where('user_id', auth()->user()->id)
                ->where('keywords', 'like', "%{$value}%")
                ->limit(10)
                ->get();
        } else {
            $this->results = [];
        }

        $this->loading = false;
    }

    public function render()
    {
        return view('livewire.search-component');
    }
}

// resources/views/livewire/search-component.blade.php
<div>
    <input
        type="text"
        wire:model.debounce.500ms="search"
        placeholder="Поиск категорий..."
        class="form-control"
    >

    @if($loading)
        <div class="spinner-border spinner-border-sm" role="status">
            <span class="visually-hidden">Поиск...</span>
        </div>
    @endif

    @if($results->isNotEmpty())
        <ul class="list-group mt-2">
            @foreach($results as $result)
                <li class="list-group-item">{{ $result->title }}</li>
            @endforeach
        </ul>
    @endif
</div>
```

### 6. Безопасность

#### Защита от XSS и инъекций
```php
// ✅ Хорошо: безопасная работа с данными
// В Blade шаблонах используем автоматическое экранирование
<div>
    <h3>{{ $card->title }}</h3> // {{ }} автоматически экранирует
    <p>{!! $trustedHtml !!}</p> // {!! !!} только для доверенного HTML
    <a href="{{ $card->url }}">{{ $card->url }}</a>
</div>

// В контроллерах валидируем и очищаем данные
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'title' => 'required|string|max:255|strip_tags',
        'description' => 'nullable|string|max:1000|sanitize',
        'url' => 'required|url'
    ]);

    // Дополнительно очищаем HTML если нужно
    $validated['description'] = clean($validated['description']);

    Category::create([
        'user_id' => auth()->user()->id,
        ...$validated
    ]);
}

// SQL инъекции - используем Eloquent/Query Builder
Card::where('user_id', auth()->user()->id)
    ->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower($search) . '%'])
    ->get();

// ❌ Плохо: прямые запросы без экранирования
DB::select("SELECT * FROM cards WHERE user_id = " . auth()->user()->id);
```

#### Telegram WebApp валидация
```php
// ✅ Правильная валидация по спецификации
private function validateTelegramInitData(string $initData, string $botToken): bool
{
    if ($initData === '' || $botToken === '') {
        return false;
    }

    $pairs = explode('&', $initData);
    $params = [];
    $hash = null;

    foreach ($pairs as $pair) {
        $eqPos = strpos($pair, '=');
        if ($eqPos === false) continue;

        $rawKey = substr($pair, 0, $eqPos);
        $rawVal = substr($pair, $eqPos + 1);

        // Используем rawurldecode для сохранения "+" символов
        $key = rawurldecode($rawKey);
        $val = rawurldecode($rawVal);

        if ($key === 'hash') {
            $hash = $val;
            continue;
        }

        $params[$key] = $val;
    }

    if (!$hash) return false;

    // Сортировка по ASCII
    ksort($params);

    $dataCheckLines = [];
    foreach ($params as $k => $v) {
        $dataCheckLines[] = $k . '=' . $v;
    }
    $dataCheckString = implode("\n", $dataCheckLines);

    // HMAC-SHA256 проверка
    $secretKey = hash('sha256', $botToken, true);
    $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

    return hash_equals($calculatedHash, $hash);
}
```

## Стандарты фронтенда

### 1. Blade шаблоны

#### Организация и чистота
```blade
{{-- ✅ Хорошо: чистая организация --}}
@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <h1>{{ $title }}</h1>

            @if(session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif

            @if($cards->count() > 0)
                <div class="card">
                    <div class="card-header">
                        <h3>Мои карты</h3>
                    </div>
                    <div class="card-body">
                        @foreach($cards as $card)
                            <div class="mb-3">
                                <h5>{{ $card->bank->title }}</h5>
                                <span class="badge" style="background-color: {{ $card->color }}">
                                    {{ $card->formatted_number }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="alert alert-info">
                    У вас пока нет карт. <a href="{{ route('cards.create') }}">Добавить</a>
                </div>
            @endif

            {{ $cards->links() }}
        </div>

        <div class="col-md-4">
            @include('partials.sidebar')
        </div>
    </div>
</div>
@endsection

{{-- ❌ Плохо: смешение логики и представления --}}
@extends('layouts.app')

@section('content')
<div>
    <?php if(Auth::user()->cards->count() > 0): ?>
        <?php foreach(Auth::user()->cards as $card): ?>
            <div><?php echo $card->title; ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
@endsection
```

#### Директивы и компоненты
```blade
{{-- ✅ Хорошо: использование директив --}}
@auth
    <a href="{{ route('profile') }}">Профиль</a>
@endauth

@guest
    <a href="{{ route('login') }}">Войти</a>
@endguest

@can('update', $card)
    <a href="{{ route('cards.edit', $card) }}">Редактировать</a>
@endcan

@isset($card->bank)
    <span>{{ $card->bank->title }}</span>
@endisset

{{-- Компоненты --}}
<x-card.card :card="$card" />
<x-modal.show title="Новая карта">
    <x-card.form :banks="$banks" />
</x-modal.show>
```

### 2. JavaScript и интерактивность

#### Alpine.js для реактивности
```html
<!-- ✅ Хорошо: Alpine.js для простых интерактивов -->
<div x-data="{ open: false }">
    <button @click="open = !open" class="btn btn-primary">
        <span x-show="!open">Показать детали</span>
        <span x-show="open">Скрыть детали</span>
    </button>

    <div x-show="open" x-transition>
        <div class="card">
            <div class="card-body">
                Контент для отображения...
            </div>
        </div>
    </div>
</div>

<!-- ✅ Комплексная логика через Livewire -->
<div>
    <input
        type="text"
        wire:model.debounce.500ms="search"
        placeholder="Поиск..."
        class="form-control"
    >

    <div wire:loading>
        <div class="spinner-border spinner-border-sm"></div>
        Поиск...
    </div>

    <div wire:target="search">
        @if($results->isNotEmpty())
            <ul class="list-group">
                @foreach($results as $result)
                    <li class="list-group-item">{{ $result->title }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
```

### 3. CSS и Bootstrap

#### Следование Bootstrap конвенциям
```html
<!-- ✅ Хорошо: правильное использование Bootstrap -->
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-credit-card me-2"></i>
                        Мои карты
                    </h4>
                </div>
                <div class="card-body">
                    @if($cards->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Банк</th>
                                        <th>Номер</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($cards as $card)
                                        <tr>
                                            <td>{{ $card->bank->title }}</td>
                                            <td>
                                                <span class="badge text-bg-secondary">
                                                    {{ $card->formatted_number }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="{{ route('cards.edit', $card) }}"
                                                       class="btn btn-outline-primary btn-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form action="{{ route('cards.destroy', $card) }}"
                                                          method="POST"
                                                          style="display: inline;">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                                class="btn btn-outline-danger btn-sm"
                                                                onclick="return confirm('Удалить карту?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="alert alert-info d-flex align-items-center" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            <div>
                                У вас пока нет карт.
                                <a href="{{ route('cards.create') }}" class="alert-link">
                                    Добавить первую карту
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Быстрые действия</h5>
                </div>
                <div class="card-body">
                    <a href="{{ route('cards.create') }}" class="btn btn-success w-100 mb-2">
                        <i class="fas fa-plus me-2"></i>
                        Добавить карту
                    </a>
                    <a href="{{ route('banks.create') }}" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-university me-2"></i>
                        Добавить банк
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
```

## Тестирование стандартов

### 1. Code Review Checklist

#### Для каждой новой функции:
- [ ] **Архитектура**: Следует ли Laravel Conventions
- [ ] **Безопасность**: Валидация всех входных данных
- [ ] **Изоляция данных**: User-scoped запросы к БД
- [ ] **Производительность**: Отсутствие N+1 проблем
- [ ] **Обработка ошибок**: Граничные случаи и исключения
- [ ] **Тестируемость**: Написаны ли тесты для функционала
- [ ] **Документация**: Обновлена ли документация

#### Для кода:
- [ ] **Читаемость**: Понятные имена переменных и функций
- [ ] **Дублирование**: Нет ли повторения кода
- [ ] **Размер**: Не слишком ли большие классы/методы
- [ ] **Зависимости**: Правильное использование DI
- [ ] **Форматирование**: Следует PSR стандартам

### 2. Автоматическая проверка

#### Включаем в CI/CD:
```bash
# Форматирование кода
./vendor/bin/pint

# Анализ статический
./vendor/bin/phpstan analyse

# Запуск тестов
./vendor/bin/pest

# Покрытие кода
./vendor/bin/pest --coverage --min=80

# Проверка стиля JavaScript
npm run lint

# Проверка безопасности
./vendor/bin/pest --group security
```

## Процесс работы с задачами

### 1. Анализ требований
- Понять бизнес-цель задачи
- Определить технические требования
- Выделить крайние случаи (edge cases)
- Оценить сложность и время

### 2. Планирование
- Декомпозиция на маленькие шаги
- Определение порядка реализации
- Планирование тестирования
- Определение зависимостей

### 3. Реализация
- Создание базовой структуры
- Добавление бизнес-логики
- Создание фронтенда
- Интеграция компонентов

### 4. Тестирование
- Написание Unit тестов
- Написание Feature тестов
- Проверка производительности
- Тестирование безопасности

### 5. Code Review
- Само-проверка по checklist
- Проверка соответствия требованиям
- Оптимизация и рефакторинг
- Финализация кода

### 6. Документация
- Обновление API документации
- Добавление комментариев при необходимости
- Обновление CLAUDE.md
- Создание инструкций для пользователей

Эти правила помогут поддерживать высокое качество кода, безопасность и поддерживаемость проекта LaraCash.