# Telegram-бот распознавания кешбэка — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Telegram-бот, который по скриншоту распознаёт категории кешбэка (через существующий GigaChat) и сохраняет их в pivot `Cashback` выбранной карты с подтверждением пользователя.

**Architecture:** Слои изолированы — транспорт (TG raw HTTP), диалог (state-machine), домен (`CashbackImportService`, без TG), AI (`AiService`, отвязан от `auth()`). Веб — источник правды, бот пишет в ту же БД (pivot `Cashback` + буфер `card.cashback_json`). Переиспользуем `AiService` (рефактор сигнатур) и `CashbackService::updateCard` (запись в pivot).

**Tech Stack:** Laravel 11, PHP 8.2+, PestPHP 3.7, GigaChat (существующий), Telegram Bot API (raw `Http`).

## Global Constraints

- PHP 8.2+, Laravel 11.x, PestPHP 3.7, MySQL (тесты — `RefreshDatabase`).
- Каждый новый метод — PHPDoc `/** ... */`.
- User-scoping везде: `where('user_id', $userId)`. Никогда не доверять `telegram_id` без поиска владельца.
- Секреты только в `.env` / `config/services.php`. **Не хардкодить** токены (правило `gigachat-secret-in-env`).
- `git commit` — только по явному подтверждению пользователя (Tier 3 проекта).
- LLM вызывается **только** на распознавание (1 вызов на 1 фото). Повторных запросов к модели на один скрин нет.
- Существующие 11 тестов `AiServiceTest` (`mapToUserCategories`) должны оставаться зелёными после рефактора.

---

## File Structure

**Create:**
- `database/migrations/2026_08_09_000001_add_telegram_id_to_users_table.php` — связь user↔tg.
- `app/Services/Bot/CashbackImportService.php` — фото→распознать→{saved,skipped}→apply (domain, без TG).
- `app/Services/Bot/BotConversationService.php` — state-machine диалога (cache по `telegram_id`).
- `app/Services/Telegram/TelegramBotService.php` — raw HTTP к TG Bot API (send/download).
- `app/Http/Controllers/TelegramWebhookController.php` — приём update от TG (вне auth).
- `app/Http/Controllers/BotLinkController.php` — привязка `telegram_id` в ЛК (auth).
- `app/Console/Commands/TelegramPollCommand.php` — long-polling для локалки.
- `tests/Unit/CashbackImportServiceTest.php`, `tests/Unit/AiServiceTest.php` (extend), `tests/Unit/TelegramBotServiceTest.php`, `tests/Feature/TelegramWebhookTest.php`, `tests/Feature/BotLinkControllerTest.php`.
- `resources/views/profile/bot_link.blade.php` — страница привязки.

**Modify:**
- `app/Models/User.php` — `$fillable += ['telegram_id']`.
- `app/Services/AiService.php` — отвязка от `auth()`: `getPrompt($userId)`, `downloadFile($filePath,$name)`, `recognize($userId,$filePath)`.
- `app/Services/CashbackService.php` — без изменений (переиспользуем `updateCard`).
- `config/services.php` + `.env.example` — секция `telegram`.
- `routes/web.php` — `/telegram/webhook` (вне auth) + `/profile/bot-link` (auth).
- `resources/views/profile/...` (или AdminLTE меню) — кнопка «Привязать Telegram».

---

## Task 1: Поле `telegram_id` у пользователя

**Files:**
- Create: `database/migrations/2026_08_09_000001_add_telegram_id_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Unit/UserTelegramIdTest.php`

**Interfaces:**
- Produces: колонка `users.telegram_id` (nullable string, unique); `User->telegram_id` mass-assignable.

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/UserTelegramIdTest.php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

test('user можно создать с telegram_id', function () {
    $user = User::factory()->create(['telegram_id' => '123456789']);
    expect($user->telegram_id)->toBe('123456789');
});

test('telegram_id уникален', function () {
    User::factory()->create(['telegram_id' => '111']);
    User::factory()->create(['telegram_id' => '111']);
})->throws(\Illuminate\Database\QueryException::class);
```

- [ ] **Step 2: Run — verify fail**

Run: `./vendor/bin/pest tests/Unit/UserTelegramIdTest.php`
Expected: FAIL — колонка `telegram_id` не существует.

- [ ] **Step 3: Migration + fillable**

```php
// database/migrations/2026_08_09_000001_add_telegram_id_to_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_id', 32)->nullable()->unique()->after('id');
        });
    }
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telegram_id');
        });
    }
};
```

```php
// app/Models/User.php — в $fillable добавить 'telegram_id'
protected $fillable = ['name', 'email', 'password', 'telegram_id'];
```

- [ ] **Step 4: Run — verify pass**

Run: `./vendor/bin/pest tests/Unit/UserTelegramIdTest.php`
Expected: PASS.

- [ ] **Step 5: Commit** (после подтверждения пользователя)

```bash
git add database/migrations/2026_08_09_000001_add_telegram_id_to_users_table.php app/Models/User.php tests/Unit/UserTelegramIdTest.php
git commit -m "feat(bot): add telegram_id to users"
```

---

## Task 2: Config + env для Telegram

**Files:**
- Modify: `config/services.php`, `.env.example`

**Interfaces:**
- Produces: `config('services.telegram.bot_token')`, `config('services.telegram.webhook_secret')`, `config('services.telegram.bot_username')`.

- [ ] **Step 1: Add config block**

```php
// config/services.php — в return массив
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
],
```

- [ ] **Step 2: Add to .env.example**

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
TELEGRAM_BOT_USERNAME=
```

- [ ] **Step 3: Smoke-verify config loads**

Run: `php artisan tinker --execute="echo config('services.telegram.api_base');"`
Expected: `https://api.telegram.org`

- [ ] **Step 4: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat(bot): telegram config"
```

---

## Task 3: Рефактор `AiService` — отвязка от `auth()`

Цель: `recognize(int $userId, string $filePath)` работает без сессии. Веб сохраняет поведение через тонкую обёртку `getRecognizedCashback(Card)`.

**Files:**
- Modify: `app/Services/AiService.php`
- Test: `tests/Unit/AiServiceTest.php` (добавить кейсы)

**Interfaces:**
- Produces:
  - `AiService::getPrompt(int $userId): string`
  - `AiService::downloadFile(string $filePath, string $originalName): string` — загрузка произвольного файла в GigaChat → file_id.
  - `AiService::recognize(int $userId, string $filePath): ?array` — распознать + `mapToUserCategories` → массив `[{category, cashback}]` или `null`.
  - `AiService::getRecognizedCashback(Card $card): bool` — веб-обёртка: `recognize($card->user_id, storage_path(...))` → запись `card->cashback_json`.
- Consumes: `Card->cashback_image`, `Card->user_id` (существуют).

- [ ] **Step 1: Write failing test (Http::fake для recognize)**

```php
// tests/Unit/AiServiceTest.php — добавить в конец файла
use App\Models\Category;
use Illuminate\Support\Facades\Http;

test('recognize возвращает распознанный массив без auth-сессии', function () {
    Category::create(['title' => 'Аптеки', 'user_id' => 1, 'keywords' => '']);

    Http::fakeSequence()
        ->push('{}', 200) // /files upload → id
        ->push([
            'choices' => [[
                'message' => ['content' => json_encode([['category' => 'Аптеки', 'cashback' => 5]])],
            ]],
        ], 200); // /chat/completions

    $result = (new \App\Services\AiService())->recognize(1, storage_path('app/test/photo.png'));

    expect($result)->not->toBeNull()
        ->and($result[0]['category'])->toBe('Аптеки')
        ->and($result[0]['cashback'])->toBe(5);
});
```

- [ ] **Step 2: Run — verify fail**

Run: `./vendor/bin/pest tests/Unit/AiServiceTest.php --filter='recognize возвращает'`
Expected: FAIL — метод `recognize` не существует / сигнатура без userId.

- [ ] **Step 3: Refactor AiService (точечно, не переписывая токен-логику)**

Изменить сигнатуры (тела — переиспользовать существующие, параметризовать):

```php
// getPrompt(int $userId) — заменить auth()->user()->id на $userId
private static function getPrompt(int $userId): string
{
    $categories = Category::query()
        ->where('user_id', $userId)
        ->get(['title', 'keywords'])
        ->map(function (Category $category) {
            $keywords = trim((string) $category->keywords);
            return $keywords !== ''
                ? "«{$category->title}» (синонимы: {$keywords})"
                : "«{$category->title}»";
        })
        ->implode(', ');
    return "На картинке скриншот категорий кешбека и их размера в процентах. ..."; // текст промпта БЕЗ ИЗМЕНЕНИЙ
}

// downloadFile(string $filePath, string $originalName) — обобщение (вместо Card)
private static function downloadFile(string $filePath, string $originalName): string
{
    if (! file_exists($filePath)) {
        Log::channel('ai_api')->error('Файл скриншота не найден', ['path' => $filePath]);
        return '';
    }
    $response = Http::withHeaders(['Authorization' => 'Bearer '.self::getToken()])
        ->withOptions(['verify' => false, 'timeout' => 90])
        ->attach('file', file_get_contents($filePath), $originalName)
        ->post('https://gigachat.devices.sberbank.ru/api/v1/files', ['purpose' => 'general']);
    if (! $response->successful()) {
        Log::channel('ai_api')->error('Ошибка загрузки файла', ['status' => $response->status(), 'body' => $response->body()]);
    }
    return $response->json('id', '');
}

// recognize(int $userId, string $filePath): ?array  — отвязан от auth и Card
private static function recognize(int $userId, string $filePath): ?array
{
    try {
        // ТЕЛО — из recognizeGigaChat, но:
        //  - getPrompt($userId) вместо getPrompt()
        //  - downloadFile($filePath, basename($filePath)) вместо downloadFile($card)
        //  - НЕ писать card->cashback_json здесь
        //  - вернуть mapToUserCategories($decoded, $userId) или null при ошибке/пустом ответе
        // ... (перенос логики recognizeGigaChat с подстановками) ...
    } catch (Exception $e) {
        Log::channel('ai_api')->error('Не удалось распознать кешбек. Ошибка: '.$e->getMessage());
        return null;
    }
}
```

Веб-обёртка сохраняет старое поведение:

```php
public function getRecognizedCashback(Card $card): bool
{
    if (empty($card->cashback_image)) {
        return false;
    }
    $path = storage_path('app/public/card_cashback_image/'.$card->cashback_image);
    $recognized = self::recognize($card->user_id, $path);
    if ($recognized === null) {
        return false;
    }
    $card->cashback_json = $recognized;
    $card->save();
    return true;
}
```

> Удалить старый `recognizeGigaChat(Card)` — его логика перенесена в `recognize($userId, $filePath)`. Проверить, что `mapToUserCategories`, `matchCategory`, `normalize`, `keywordsTokens`, `getToken`, `refreshToken` НЕ меняются.

- [ ] **Step 4: Run — verify pass + существующие тесты зелёные**

Run: `./vendor/bin/pest tests/Unit/AiServiceTest.php`
Expected: PASS всех тестов (включая 11 исходных по `mapToUserCategories` — сигнатура этого метода не менялась).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AiService.php tests/Unit/AiServiceTest.php
git commit -m "refactor(ai): decouple AiService::recognize from auth session"
```

---

## Task 4: `CashbackImportService` (domain)

Оркестрация: фото → распознать → классифицировать saved/skipped → применить. Без TG.

**Files:**
- Create: `app/Services/Bot/CashbackImportService.php`
- Test: `tests/Unit/CashbackImportServiceTest.php`

**Interfaces:**
- Consumes: `AiService::recognize(int $userId, string $filePath): ?array`, `CashbackService::updateCard(Card $card, array $categories): void`, `Category` (titles+ids юзера).
- Produces:
  - `CashbackImportService::import(int $userId, int $cardId, array $photoPaths): array` → `['saved' => [['category_id'=>int,'title'=>str,'percent'=>float], ...], 'skipped' => [str,...], 'raw' => array]`.
  - `CashbackImportService::apply(int $cardId, array $raw): void` — пишет `cashback_json` + pivot через `CashbackService::updateCard`.

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/CashbackImportServiceTest.php
use App\Models\Card;
use App\Models\Category;
use App\Services\Bot\CashbackImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

test('import делит распознанное на saved/skipped по категориям юзера', function () {
    $card = Card::factory()->create();
    Category::create(['title' => 'Аптеки', 'user_id' => $card->user_id, 'keywords' => '']);
    // Category::create(['title' => 'Супермаркеты', ...]) — НЕ создаём → попадёт в skipped

    $import = new CashbackImportService();

    // Подменяем AiService::recognize через контейнер
    app()->bind(CashbackImportService::class, function () {
        $mock = Mockery::mock(CashbackImportService::class)->makePartial();
        $mock->shouldReceive('recognizeViaAi')->andReturn([
            ['category' => 'Аптеки', 'cashback' => 5],
            ['category' => 'Супермаркеты', 'cashback' => 3],
        ]);
        return $mock;
    });

    $result = app(CashbackImportService::class)->import($card->user_id, $card->id, ['/tmp/x.png']);

    expect($result['saved'])->toHaveCount(1)
        ->and($result['saved'][0]['title'])->toBe('Аптеки')
        ->and($result['skipped'])->toContain('Супермаркеты');
})->skip(); // заменить на реальный stub AiService при реализации
```

> Примечание реализатору: чище — вынести вызов AI в защищённый метод `recognizeViaAi(int $userId, string $path)`, который вызывает `AiService::recognize`, и мокать его. Либо инжектить `AiService` в конструктор и подменять через `app()->bind(AiService::class, ...)`.

- [ ] **Step 2: Run — verify fail**

Run: `./vendor/bin/pest tests/Unit/CashbackImportServiceTest.php`
Expected: FAIL — класс `CashbackImportService` не существует.

- [ ] **Step 3: Implement**

```php
// app/Services/Bot/CashbackImportService.php
namespace App\Services\Bot;

use App\Models\Card;
use App\Models\Category;
use App\Services\AiService;
use App\Services\CashbackService;
use Illuminate\Support\Collection;

class CashbackImportService
{
    public function __construct(private AiService $ai) {}

    /**
     * Распознаёт фото и делит результат на категории юзера (saved) и прочие (skipped).
     *
     * @param  string[]  $photoPaths  Абсолютные пути к фото на диске.
     * @return array{saved: list<array{category_id:int,title:string,percent:mixed}>, skipped: list<string>, raw: array}
     */
    public function import(int $userId, int $cardId, array $photoPaths): array
    {
        $userTitles = Category::query()->where('user_id', $userId)->pluck('id', 'title'); // title => id

        $saved = [];
        $skipped = [];
        $raw = [];

        foreach ($photoPaths as $path) {
            $recognized = $this->ai->recognize($userId, $path);
            if ($recognized === null) {
                continue;
            }
            foreach ($recognized as $item) {
                $raw[] = $item;
                $title = (string) ($item['category'] ?? '');
                if (isset($userTitles[$title])) {
                    $saved[] = [
                        'category_id' => $userTitles[$title],
                        'title' => $title,
                        'percent' => $item['cashback'] ?? 0,
                    ];
                } elseif ($title !== '' && !in_array($title, $skipped, true)) {
                    $skipped[] = $title;
                }
            }
        }

        return ['saved' => $saved, 'skipped' => $skipped, 'raw' => $raw, 'card_id' => $cardId];
    }

    /**
     * Применяет распознанный кешбэк: буфер card.cashback_json + pivot через CashbackService::updateCard.
     *
     * @param  array  $raw  Распознанный массив из import()['raw'].
     */
    public function apply(int $cardId, array $raw): void
    {
        $card = Card::query()->where('id', $cardId)->firstOrFail();
        $card->cashback_json = $raw;
        $card->save();

        // pivot только для категорий, которые есть у юзера (по title)
        $userTitles = Category::query()->where('user_id', $card->user_id)->pluck('id', 'title');
        $categories = [];
        foreach ($raw as $item) {
            $title = (string) ($item['category'] ?? '');
            if (isset($userTitles[$title])) {
                $categories[$userTitles[$title]] = ['percent' => $item['cashback'] ?? 0, 'mcc' => ''];
            }
        }
        CashbackService::updateCard($card, $categories);
    }
}
```

- [ ] **Step 4: Run — verify pass**

Run: `./vendor/bin/pest tests/Unit/CashbackImportServiceTest.php`
Expected: PASS (исправить stub на инжект `AiService` и `app()->bind`).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bot/CashbackImportService.php tests/Unit/CashbackImportServiceTest.php
git commit -m "feat(bot): CashbackImportService — recognize → saved/skipped → apply"
```

---

## Task 5: `TelegramBotService` (transport, raw HTTP)

**Files:**
- Create: `app/Services/Telegram/TelegramBotService.php`
- Test: `tests/Unit/TelegramBotServiceTest.php`

**Interfaces:**
- Produces:
  - `TelegramBotService::sendMessage(int|string $chatId, string $text, array $keyboard = []): void`
  - `TelegramBotService::answerCallback(string $callbackId, ?string $text = null): void`
  - `TelegramBotService::downloadPhoto(string $fileId): ?string` — абсолютный путь к скачанному фото во `storage_path('app/temp/tg/')` или `null`.

- [ ] **Step 1: Write failing test (Http::fake)**

```php
// tests/Unit/TelegramBotServiceTest.php
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('downloadPhoto сохраняет файл и возвращает путь', function () {
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file.png']]),
        'api.telegram.org/file/*' => Http::response('binarycontent', 200),
    ]);
    config()->set('services.telegram.bot_token', 'TEST');

    $bot = new TelegramBotService();
    $path = $bot->downloadPhoto('FILE_ID');

    expect($path)->not->toBeNull()
        ->and(file_exists($path))->toBeTrue();
});

test('sendMessage шлёт POST на sendMessage', function () {
    Http::fake(['api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);
    config()->set('services.telegram.bot_token', 'TEST');

    (new TelegramBotService())->sendMessage(123, 'hi');

    Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/botTEST/sendMessage');
});
```

- [ ] **Step 2: Run — verify fail**

Run: `./vendor/bin/pest tests/Unit/TelegramBotServiceTest.php`
Expected: FAIL — класс не существует.

- [ ] **Step 3: Implement**

```php
// app/Services/Telegram/TelegramBotService.php
namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TelegramBotService
{
    private function base(): string
    {
        $token = config('services.telegram.bot_token');
        $api = rtrim((string) config('services.telegram.api_base'), '/');
        return "{$api}/bot{$token}";
    }

    /** Отправляет текстовое сообщение (опционально с inline-клавиатурой). */
    public function sendMessage(int|string $chatId, string $text, array $keyboard = []): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }
        Http::post("{$this->base()}/sendMessage", $payload);
    }

    /** Подтверждает callback-запрос (снимает «часики» на кнопке). */
    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        Http::post("{$this->base()}/answerCallbackQuery", array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
        ], fn ($v) => $v !== null));
    }

    /** Скачивает фото по file_id → абсолютный путь во storage/app/temp/tg/. null при ошибке. */
    public function downloadPhoto(string $fileId): ?string
    {
        $meta = Http::get("{$this->base()}/getFile", ['file_id' => $fileId])->json();
        $filePath = $meta['result']['file_path'] ?? null;
        if (!$filePath) {
            return null;
        }
        $token = config('services.telegram.bot_token');
        $contents = Http::get(rtrim((string) config('services.telegram.api_base'), '/')."/file/bot{$token}/{$filePath}")->body();
        $local = 'temp/tg/'.uniqid('ph_', true).'.png';
        Storage::disk('local')->put($local, $contents);
        return Storage::disk('local')->path($local);
    }
}
```

- [ ] **Step 4: Run — verify pass**

Run: `./vendor/bin/pest tests/Unit/TelegramBotServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Telegram/TelegramBotService.php tests/Unit/TelegramBotServiceTest.php
git commit -m "feat(bot): TelegramBotService — raw HTTP transport"
```

---

## Task 6: Webhook-роут + контроллер

**Files:**
- Create: `app/Http/Controllers/TelegramWebhookController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TelegramWebhookTest.php`

**Interfaces:**
- Consumes: `BotConversationService` (Task 7) — `handle(array $update): void`.
- Produces: `POST /telegram/webhook` (вне auth), проверка `X-Telegram-Bot-Api-Secret-Token`.

- [ ] **Step 1: Write failing test**

```php
// tests/Feature/TelegramWebhookTest.php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

test('webhook отклоняет запрос без секретного токена', function () {
    $this->postJson('/telegram/webhook', ['update_id' => 1])
        ->assertForbidden();
});

test('webhook со секретом и текстом /start от известного tg возвращает 200', function () {
    config()->set('services.telegram.webhook_secret', 'SECRET');
    User::factory()->create(['telegram_id' => '42']);

    // BotConversationService мокаем (Task 7 ещё нет) — bind noop
    app()->bind(\App\Services\Bot\BotConversationService::class, function () {
        $m = \Mockery::mock(\App\Services\Bot\BotConversationService::class);
        $m->shouldReceive('handle');
        return $m;
    });

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
        ->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => 1], 'from' => ['id' => 42], 'text' => '/start']])
        ->assertOk();
});
```

- [ ] **Step 2: Run — verify fail**

Run: `./vendor/bin/pest tests/Feature/TelegramWebhookTest.php`
Expected: FAIL — роут/контроллер не существует.

- [ ] **Step 3: Implement controller + route**

```php
// app/Http/Controllers/TelegramWebhookController.php
namespace App\Http\Controllers;
use App\Services\Bot\BotConversationService;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(private BotConversationService $conversation) {}

    public function __invoke(Request $request): \Illuminate\Http\Response
    {
        if ($request->header('X-Telegram-Bot-Api-Secret-Token') !== config('services.telegram.webhook_secret')) {
            abort(403);
        }
        $this->conversation->handle($request->json()->all());
        return response('OK', 200);
    }
}
```

```php
// routes/web.php — ДО auth-группы (webhook публичный)
Route::post('/telegram/webhook', App\Http\Controllers\TelegramWebhookController::class)
    ->name('telegram.webhook')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
```

> VerifyCsrfToken: в Laravel 11 middleware-стек включен глобально. `withoutMiddleware` для CSRF на конкретном роуте — проверить, что `$middleware->validateCsrfTokens(except: [...])` в bootstrap/app.php не нужен; если `withoutMiddleware` не снимает — добавить исключение в `bootstrap/app.php`.

- [ ] **Step 4: Run — verify pass** (после Task 7, либо с моком как в тесте)

Run: `./vendor/bin/pest tests/Feature/TelegramWebhookTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TelegramWebhookController.php routes/web.php tests/Feature/TelegramWebhookTest.php bootstrap/app.php
git commit -m "feat(bot): telegram webhook route + controller"
```

---

## Task 7: `BotConversationService` (state machine)

**Files:**
- Create: `app/Services/Bot/BotConversationService.php`
- Test: `tests/Feature/BotConversationServiceTest.php` (или Unit)

**Interfaces:**
- Consumes: `TelegramBotService`, `CashbackImportService`, `User`, `Card`, `Cache`.
- Produces: `BotConversationService::handle(array $update): void` — единая точка входа для любого update.

Состояния (cache по ключу `bot.state.{telegram_id}`): `idle`, `await_card`, `await_photo` (в нём храним `card_id`), `await_confirm` (в нём храним `card_id` + `raw` + `chat_id`).

- [ ] **Step 1: Write failing test (текстовый сценарий /start)**

```php
// tests/Feature/BotConversationServiceTest.php
use App\Models\User;
use App\Services\Bot\BotConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

test('/start от привязанного юзера шлёт меню', function () {
    Http::fake(['api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);
    config()->set('services.telegram.bot_token', 'TEST');
    User::factory()->create(['telegram_id' => '42', 'name' => 'Иван']);

    app(BotConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 42], 'text' => '/start'],
    ]);

    Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Иван'));
});

test('/start от НЕ привязанного юзера шлёт ссылку на привязку', function () {
    Http::fake(['api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);
    config()->set('services.telegram.bot_token', 'TEST');

    app(BotConversationService::class)->handle([
        'message' => ['chat' => ['id' => 100], 'from' => ['id' => 999], 'text' => '/start'],
    ]);

    Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '/profile/bot-link'));
});
```

- [ ] **Step 2: Run — verify fail**

Run: `./vendor/bin/pest tests/Feature/BotConversationServiceTest.php`
Expected: FAIL — класс не существует.

- [ ] **Step 3: Implement (каркас)**

```php
// app/Services/Bot/BotConversationService.php
namespace App\Services\Bot;

use App\Models\Card;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Cache;

class BotConversationService
{
    private const STATE_TTL = 1800; // 30 мин

    public function __construct(
        private TelegramBotService $bot,
        private CashbackImportService $import,
    ) {}

    /** Единая точка входа update от Telegram. */
    public function handle(array $update): void
    {
        $chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
        $tgId = (string) ($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? '');
        if ($chatId === null || $tgId === '') return;

        $user = User::query()->where('telegram_id', $tgId)->first();
        if (! $user) {
            $url = config('app.url').'/profile/bot-link?tg='.$tgId;
            $this->bot->sendMessage($chatId, "Сначала привяжи аккаунт LaraCash: {$url}");
            return;
        }

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $chatId, $user);
            return;
        }
        $this->handleMessage($update['message'], $chatId, $user);
    }

    private function handleMessage(array $message, int|string $chatId, User $user): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $state = $this->state($user->telegram_id);

        if ($text === '/start' || $text === '/menu') {
            $this->sendMenu($chatId, $user);
            return;
        }
        if ($text === 'Обновить кешбэк' || $state === 'await_card') {
            $this->sendCardKeyboard($chatId, $user);
            return;
        }
        if ($state === 'await_photo' && isset($message['photo'])) {
            $this->processPhotos($message, $chatId, $user);
            return;
        }
        $this->bot->sendMessage($chatId, 'Не понял. /menu — меню.');
    }

    private function sendMenu(int|string $chatId, User $user): void
    {
        $this->bot->sendMessage($chatId, "Привет, {$user->name}! Карт: {$user->cards()->count()}.", [
            [['text' => 'Обновить кешбэк', 'callback_data' => 'cmd:update']],
        ]);
        $this->setState((string) $user->telegram_id, 'idle');
    }

    private function sendCardKeyboard(int|string $chatId, User $user): void
    {
        $cards = Card::query()->where('user_id', $user->id)->get();
        if ($cards->isEmpty()) {
            $this->bot->sendMessage($chatId, 'У тебя нет карт. Добавь карту в ЛК LaraCash.');
            return;
        }
        $keyboard = $cards->map(fn (Card $c) => [['text' => $c->bank->title.' '.$c->number, 'callback_data' => 'card:'.$c->id]])->values()->all();
        $this->bot->sendMessage($chatId, 'Выбери карту:', $keyboard);
        $this->setState((string) $user->telegram_id, 'await_card');
    }

    private function handleCallback(array $cb, int|string $chatId, User $user): void
    {
        $this->bot->answerCallback($cb['id']);
        $data = $cb['data'] ?? '';

        if ($data === 'cmd:update') { $this->sendCardKeyboard($chatId, $user); return; }
        if (str_starts_with($data, 'card:')) {
            $cardId = (int) substr($data, 5);
            $this->setState((string) $user->telegram_id, 'await_photo', ['card_id' => $cardId]);
            $this->bot->sendMessage($chatId, 'Пришли скриншот категорий кешбэка (можно несколько).');
            return;
        }
        if ($data === 'apply' || $data === 'cancel') {
            $this->handleConfirm($data, $chatId, $user);
        }
    }

    private function processPhotos(array $message, int|string $chatId, User $user): void
    {
        $state = $this->state($user->telegram_id);
        $cardId = $state['card_id'] ?? null;
        if (! $cardId) { $this->sendMenu($chatId, $user); return; }

        // Берём самое крупное разрешение
        $photo = end($message['photo']);
        $path = $this->bot->downloadPhoto($photo['file_id']);
        if (! $path) { $this->bot->sendMessage($chatId, 'Не удалось скачать фото.'); return; }

        $result = $this->import->import($user->id, $cardId, [$path]);
        @unlink($path);

        $savedTxt = collect($result['saved'])->map(fn ($s) => "{$s['title']} {$s['percent']}%")->implode(', ') ?: 'ничего';
        $skippedTxt = $result['skipped'] ? "\n⚠️ Нет в ЛК: ".implode(', ', $result['skipped']) : '';
        $this->bot->sendMessage($chatId, "✅ Сохраню: {$savedTxt}.{$skippedTxt}", [
            [['text' => 'Применить', 'callback_data' => 'apply'], ['text' => 'Отмена', 'callback_data' => 'cancel']],
        ]);
        $this->setState((string) $user->telegram_id, 'await_confirm', [
            'card_id' => $cardId, 'raw' => $result['raw'],
        ]);
    }

    private function handleConfirm(string $action, int|string $chatId, User $user): void
    {
        $state = $this->state($user->telegram_id);
        if ($action === 'apply' && isset($state['card_id'], $state['raw'])) {
            $this->import->apply((int) $state['card_id'], $state['raw']);
            $this->bot->sendMessage($chatId, 'Готово! Кешбэк применён.');
        } else {
            $this->bot->sendMessage($chatId, 'Отменено.');
        }
        $this->setState((string) $user->telegram_id, 'idle');
    }

    private function state(string $tgId): array { return Cache::get("bot.state.{$tgId}", ['name' => 'idle']); }
    private function setState(string $tgId, string $name, array $extra = []): void
    {
        Cache::put("bot.state.{$tgId}", array_merge(['name' => $name], $extra), now()->addSeconds(self::STATE_TTL));
    }
}
```

> Примечание: `$user->cards()` — проверить наличие связи в `User`. Если связи нет — `Card::where('user_id', $user->id)->count()`. Состояние хранит `['name'=>..., 'card_id'=>..., 'raw'=>...]`.

- [ ] **Step 4: Run — verify pass**

Run: `./vendor/bin/pest tests/Feature/BotConversationServiceTest.php`
Expected: PASS (дополнить тестами на выбор карты → фото → apply по аналогии).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bot/BotConversationService.php tests/Feature/BotConversationServiceTest.php
git commit -m "feat(bot): conversation state machine"
```

---

## Task 8: Привязка бота в ЛК (`BotLinkController` + кнопка)

**Files:**
- Create: `app/Http/Controllers/BotLinkController.php`, `resources/views/profile/bot_link.blade.php`
- Modify: `routes/web.php` (добавить в `/profile` auth-группу)
- Test: `tests/Feature/BotLinkControllerTest.php`

**Interfaces:**
- Produces: `GET /profile/bot-link?tg=...` (auth) → форма; `POST /profile/bot-link` (auth) → сохраняет `telegram_id`.

- [ ] **Step 1: Write failing test**

```php
// tests/Feature/BotLinkControllerTest.php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

test('гость перенаправляется на логин', function () {
    $this->get('/profile/bot-link?tg=42')->assertRedirect('/login');
});

test('авторизованный сохраняет telegram_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/profile/bot-link', ['telegram_id' => '42'])
        ->assertRedirect();
    expect($user->fresh()->telegram_id)->toBe('42');
});
```

- [ ] **Step 2: Run — verify fail**

Run: `./vendor/bin/pest tests/Feature/BotLinkControllerTest.php`
Expected: FAIL — роут/контроллер не существует.

- [ ] **Step 3: Implement**

```php
// app/Http/Controllers/BotLinkController.php
namespace App\Http\Controllers;
use Illuminate\Http\Request;

class BotLinkController extends Controller
{
    public function show(Request $request)
    {
        return view('profile.bot_link', ['tg' => $request->query('tg')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['telegram_id' => ['required', 'string', 'max:32']]);
        $request->user()->update(['telegram_id' => $data['telegram_id']]);
        return redirect()->route('profile')->with('success', 'Telegram привязан.');
    }
}
```

```php
// routes/web.php — внутри Route::prefix('/profile') в auth-группе:
Route::get('/bot-link', [App\Http\Controllers\BotLinkController::class, 'show'])->name('profile.bot_link.show');
Route::post('/bot-link', [App\Http\Controllers\BotLinkController::class, 'store'])->name('profile.bot_link.store');
```

```blade
{{-- resources/views/profile/bot_link.blade.php --}}
@extends('adminlte::page') {{-- или базовый layout проекта --}}
@section('content')
  <div class="card">
    <div class="card-body">
      <p>Привязать Telegram к вашему аккаунту LaraCash?</p>
      <form method="POST" action="{{ route('profile.bot_link.store') }}">
        @csrf
        <input type="hidden" name="telegram_id" value="{{ $tg }}">
        <button class="btn btn-primary" type="submit">Привязать</button>
      </form>
    </div>
  </div>
@endsection
```

- [ ] **Step 4: Run — verify pass**

Run: `./vendor/bin/pest tests/Feature/BotLinkControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Добавить кнопку «Привязать Telegram» в профиль** (в существующий вид профиля или AdminLTE-меню) со ссылкой `t.me/<bot_username>?start=link` — финальный текст ссылки согласовать с пользователем.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BotLinkController.php resources/views/profile/bot_link.blade.php routes/web.php tests/Feature/BotLinkControllerTest.php
git commit -m "feat(bot): link telegram account in profile"
```

---

## Task 9: Команда `telegram:poll` (локалка)

**Files:**
- Create: `app/Console/Commands/TelegramPollCommand.php`

**Interfaces:**
- Consumes: `TelegramBotService::handle`-эквивалент через `getUpdates`.

- [ ] **Step 1: Implement**

```php
// app/Console/Commands/TelegramPollCommand.php
namespace App\Console\Commands;
use App\Services\Bot\BotConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll';
    protected $description = 'Long-polling Telegram getUpdates (локальная разработка).';

    public function handle(BotConversationService $conv): int
    {
        $base = rtrim((string) config('services.telegram.api_base'), '/').'/bot'.config('services.telegram.bot_token');
        $offset = 0;
        $this->info('Polling... Ctrl+C to stop.');
        while (true) {
            $resp = Http::get("{$base}/getUpdates", ['offset' => $offset, 'timeout' => 30]);
            foreach ($resp->json('result', []) as $update) {
                $offset = $update['update_id'] + 1;
                $conv->handle($update);
            }
        }
    }
}
```

- [ ] **Step 2: Smoke-verify** (вручную, с реальным токеном): `php artisan telegram:poll` — получать `/start` от тестового аккаунта.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/TelegramPollCommand.php
git commit -m "feat(bot): telegram:poll command for local dev"
```

---

## Приёмка (по памяти проекта: acceptance-gate)

После всех задач: `php artisan accept` (если установлен) или вручную:
- `./vendor/bin/pest` — все тесты зелёные.
- `./vendor/bin/pint` — форматирование.
- Пробный прогон распознавания на реальных скринах 2–3 банков (риск #2 из spec).

## Риски (из spec, проверить при исполнении)
1. `apply` через `updateCard` пишет `0` вместо DELETE — убедиться, что не плодит нули и не затирает ненулевые ручные записи.
2. Точность GigaChat на реальных мобильных скринах — пробный прогон перед сдачей.
3. Webhook на проде требует публичного HTTPS + `setWebhook` с `secret_token`; локально — `telegram:poll`.
4. CSRF на `/telegram/webhook` — убедиться, что запрос от TG проходит (исключение в `bootstrap/app.php`).
