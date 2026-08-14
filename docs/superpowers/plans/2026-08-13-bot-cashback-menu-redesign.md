# Редизайн меню распознанных кешбэков в ботах — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Широкая кнопка-категория во весь ряд; тап разворачивает «аккордеон» с раздельной правкой полей (Название / Процент / Примечание / Удалить / Свернуть) в редакторе распознанных кешбэков ботов TG и MAX.

**Architecture:** Вся логика редактора живёт в `AbstractBotConversationService` (Template Method); оба адаптера (TG/MAX) наследуют клавиатуру без перекрытия. Разворот хранится как `active`-индекс в state `await_confirm` (без нового состояния); правка полей — через существующее `await_edit` + флаг `field` в extra. `callback_data` — плоские строки, работают на обеих платформах без правок адаптеров.

**Tech Stack:** PHP 8.4, Laravel 11, Livewire 3 (не затрагивается), Pest 3. Схема БД не меняется (`mcc` уже поддерживается).

**Spec:** `docs/superpowers/specs/2026-08-13-bot-cashback-menu-redesign-design.md`

## Global Constraints

- **PHP 8.4 / Laravel 11 / Pest 3.** Контрольные точки: `./vendor/bin/pest` и `vendor/bin/pint --dirty`.
- **Коммиты — только по явному подтверждению пользователя (правило проекта, Tier 3).** На каждом commit-шаге исполнитель СТОП и спрашивает пользователя; НЕ коммитить автоматически.
- **Тесты — Pest, зеркально для TG и MAX.** Обновляются существующие Reflection-проверки `buildEditorKeyboard`/`buildEditorText` (TG ~647/667/775/826, MAX ~343/361/439/472 — строки могли сместиться, ориентироваться по имени теста) + добавляются новые кейсы. Генерация/правка тестов может делегироваться агенту `laravel-test-generator`; код тестов ниже — контракт.
- **`e()` обязателен для пользовательского текста в новых лейблах кнопок** (HTML-парсинг TG: `<`,`>`,`&` ломают рендер).
- **Схема БД не меняется.** Миграций нет.
- **Адаптеры `TelegramConversationService` / `MaxConversationService` НЕ правятся** (новых хуков нет).
- **`buildEditorText` НЕ трогается** (текст сообщения без изменений по спеке).
- **`parseItem` НЕ трогается** (используется в `await_add` и остаётся как legacy-путь).

### Соглашения об именах (сверять во всех задачах)

| Имя | Сигнатура |
|---|---|
| `buildEditorKeyboard` | `protected function buildEditorKeyboard(array $items, ?int $active = null): array` |
| `renderEditor` | `protected function renderEditor(int\|string $chatId, int\|string\|null $msgId, array $items, ?int $active = null): int\|string\|null` |
| `parseTitle` | `protected static function parseTitle(string $text): ?string` |
| `parsePercent` | `protected static function parsePercent(string $text): ?float` |
| `applyTitle` | `protected function applyTitle(array $items, int $index, string $title, int $userId): array` |
| `applyPercent` | `protected function applyPercent(array $items, int $index, float $percent): array` |
| `active` в state | `int\|null` — индекс развёрнутого пункта в `await_confirm` |

---

## Task 1: Новая раскладка клавиатуры + параметр `active` в `renderEditor`

**Files:**
- Modify: `app/Services/Bot/AbstractBotConversationService.php` — `buildEditorKeyboard` (~842–877), `renderEditor` (~1076–1088)
- Test: `tests/Feature/TelegramConversationServiceTest.php`, `tests/Feature/MaxConversationServiceTest.php`

**Interfaces:**
- Consumes: существующий `makeButton(string $text, string $data): array` (платформенный хук).
- Produces: `buildEditorKeyboard(array $items, ?int $active = null)` и `renderEditor(..., ?int $active = null)` — используются в задачах 2–3.

- [ ] **Step 1: Write failing test (TG, Reflection раскладки)**

Добавить/обновить тест в `tests/Feature/TelegramConversationServiceTest.php` (следуй существующему паттерну создания сервиса и Reflection-вызова `buildEditorKeyboard`, как в текущих тестах лейблов `add`/`merge`/`replace`):

```php
it('builds editor keyboard with one wide category row per item and global buttons', function () {
    $service = $this->makeService(); // существующий хелпер создания сервиса в файле
    $items = [
        ['title' => 'Супермаркеты', 'percent' => 5.0, 'category_id' => 1, 'mcc' => ''],
        ['title' => 'Рестораны', 'percent' => 10.0, 'category_id' => null, 'mcc' => ''],
    ];

    $kb = invokeProtected($service, 'buildEditorKeyboard', [$items, null]); // Reflection helper

    // Первая строка — «Добавить категорию»
    expect($kb[0][0]['text'])->toContain('Добавить категорию');
    expect($kb[0][0]['callback_data'])->toBe('add');

    // Каждый пункт — ОДНА кнопка в ряду, callback cat:{i}
    expect($kb[1])->toHaveCount(1);
    expect($kb[1][0]['callback_data'])->toBe('cat:0');
    expect($kb[1][0]['text'])->toContain('Супермаркеты')->toContain('5%')->toContain('✅');

    expect($kb[2])->toHaveCount(1);
    expect($kb[2][0]['callback_data'])->toBe('cat:1');
    expect($kb[2][0]['text'])->toContain('Рестораны')->toContain('10%')->toContain('🆕');

    // Хвостовые глобальные кнопки без изменений
    $flat = array_merge(...array_map(fn ($row) => array_column($row, 'callback_data'), $kb));
    expect($flat)->toContain('merge')->toContain('replace')->toContain('cancel');
});

it('expands the active item with field rows', function () {
    $service = $this->makeService();
    $items = [
        ['title' => 'Рестораны', 'percent' => 10.0, 'category_id' => null, 'mcc' => ''],
    ];

    $kb = invokeProtected($service, 'buildEditorKeyboard', [$items, 0]);

    $flat = array_merge(...array_map(fn ($row) => array_column($row, 'callback_data'), $kb));
    expect($flat)
        ->toContain('cat:0')          // широкая кнопка активного пункта
        ->toContain('edt_t:0')        // Название
        ->toContain('edt_p:0')        // Процент
        ->toContain('note:0')         // Примечание
        ->toContain('del:0');         // Удалить (рядом со Свернуть)

    // Последний ряд развёрнутого пункта — Удалить + Свернуть (2 кнопки), Свернуть = cat:0
    $collapseRow = end($kb);
    // найти ряд, где есть del:0 — он же содержит cat:0 (Свернуть)
    $delRow = array_values(array_filter($kb, fn ($r) => in_array('del:0', array_column($r, 'callback_data'), true)))[0];
    expect(array_column($delRow, 'callback_data'))->toBe(['del:0', 'cat:0']);
});
```

> Примечание исполнителю: `invokeProtected()` / `$this->makeService()` — использовать те же хелперы, что уже применяются в существующих Reflection-тестах `buildEditorKeyboard` этого файла (если имена хелперов отличаются — использовать их). Зеркальный тест добавить в `MaxConversationServiceTest.php` (для MAX `callback_data` лежит в ключе `payload`, не `callback_data` — свериться с существующими MAX-тестами и проверять тот ключ, который использует `MaxConversationService::makeButton`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=builds_editor_keyboard`
Expected: FAIL — текущая `buildEditorKeyboard` отдаёт `edit:0`/`del:0`/`note:0` тремя кнопками в ряду, а не `cat:0` одной кнопкой.

- [ ] **Step 3: Implement — `buildEditorKeyboard` с `active`**

Заменить тело `buildEditorKeyboard` (~842–877) на:

```php
/**
 * Формирует inline-клавиатуру редактора: каждая категория — одна широкая кнопка во весь ряд;
 * активный (развёрнутый) пункт дополнительно раскрывает ряды полей (Название/Процент/Примечание/Удалить/Свернуть).
 *
 * @param  array  $items  Элементы [['title'=>string,'percent'=>float,'category_id'=>?int,'mcc'=>string], ...]
 * @param  int|null  $active  Индекс развёрнутого пункта (null — всё свёрнуто)
 * @return array Массив строк кнопок
 */
protected function buildEditorKeyboard(array $items, ?int $active = null): array
{
    $keyboard = [];

    // «Добавить категорию» — первой, чтобы всегда была под рукой
    $keyboard[] = [$this->makeButton('➕ Добавить категорию', 'add')];

    foreach ($items as $i => $item) {
        $title = $item['title'] ?? '';
        $percent = $item['percent'] ?? 0;

        // Обрезаем title если слишком длинный (лимит текста кнопки)
        if (mb_strlen($title) > 30) {
            $title = mb_substr($title, 0, 30).'…';
        }

        // Маркер статуса: ✅ — существующая категория, 🆕 — новая (будет создана)
        $mark = empty($item['category_id']) ? '🆕' : '✅';

        // Широкая кнопка-категория во весь ряд (тап → разворот/сворот)
        $keyboard[] = [$this->makeButton("{$mark} {$title} {$percent}%", 'cat:'.$i)];

        // Поля активного (развёрнутого) пункта — каждый ряд во всю ширину,
        // кроме последнего (Удалить + Свернуть делит ряд пополам)
        if ($i === $active) {
            $keyboard[] = [$this->makeButton('✏️ Название: '.e($item['title'] ?? ''), 'edt_t:'.$i)];
            $keyboard[] = [$this->makeButton('Процент: '.($item['percent'] ?? 0).'%', 'edt_p:'.$i)];
            $noteLabel = ! empty($item['mcc'])
                ? '📝 Примечание: '.e($item['mcc'])
                : '📝 Примечание: (пусто)';
            $keyboard[] = [$this->makeButton($noteLabel, 'note:'.$i)];
            $keyboard[] = [
                $this->makeButton('🗑 Удалить', 'del:'.$i),
                $this->makeButton('✖ Свернуть', 'cat:'.$i),
            ];
        }
    }

    // Кнопки сохранения — каждая на всю ширину своей строки
    $keyboard[] = [$this->makeButton('💾 Сохранить (добавить к старым)', 'merge')];
    $keyboard[] = [$this->makeButton('♻️ Заменить (удалить старые)', 'replace')];
    $keyboard[] = [$this->makeButton('Отменить', 'cancel')];

    return $keyboard;
}
```

- [ ] **Step 4: Implement — `renderEditor` с `active`**

Обновить сигнатуру и передачу `active` в `renderEditor` (~1076–1088):

```php
/**
 * Рендерит редактор категорий: обновляет существующее сообщение (edit) или
 * отправляет новое (fallback при отсутствии msg_id). Возвращает message_id нового.
 *
 * @param  int|string  $chatId  ID чата
 * @param  int|string|null  $msgId  ID сообщения редактора (null — отправить новое)
 * @param  array  $items  Элементы редактора
 * @param  int|null  $active  Индекс развёрнутого пункта (null — свёрнуто)
 * @return int|string|null Message ID нового сообщения или null при edit
 */
protected function renderEditor(int|string $chatId, int|string|null $msgId, array $items, ?int $active = null): int|string|null
{
    $text = $this->buildEditorText($items);
    $keyboard = $this->buildEditorKeyboard($items, $active);

    if ($msgId !== null) {
        $this->editMessageText($chatId, $msgId, $text, $keyboard);

        return null;
    }

    return $this->sendMessage($chatId, $text, $keyboard);
}
```

> Существующие вызовы `renderEditor($chatId, $msgId, $items)` в `processPhotos` (~465) и `processTextList` корректны — 4-й параметр optional (`null`). Точки в `handleMessage`/`handleCallback` (`await_note` ~199, `await_edit` ~241, `del` ~343) правятся в задачах 2–3.

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --filter=builds_editor_keyboard`
Expected: PASS.
Также запустить зеркальный MAX-тест. Run: `php artisan test tests/Feature/MaxConversationServiceTest.php --filter=builds_editor_keyboard`
Expected: PASS.

- [ ] **Step 6: Commit (СТОП — спросить пользователя)**

```bash
git add app/Services/Bot/AbstractBotConversationService.php tests/Feature/TelegramConversationServiceTest.php tests/Feature/MaxConversationServiceTest.php
git commit -m "feat(bot): широкая кнопка-категория + поля развёрнутого пункта в редакторе кешбэков"
```

---

## Task 2: Роутинг `cat:{i}` (разворот/сворот) + `edit:` алиас + сброс `active` при удалении

**Files:**
- Modify: `app/Services/Bot/AbstractBotConversationService.php` — `handleCallback` (ветки `edit:` ~328–334 и `del:` ~336–347)
- Test: оба feature-файла

**Interfaces:**
- Consumes: `renderEditor(..., ?int $active)` (Task 1), `patchState(string $pid, array $patch)`, `state(string $pid): array`, `setState(string $pid, string $name, array $extra = [])`.
- Produces: поведение `cat:{i}` (toggle `active`) и корректный `active=null` после `del:{i}`.

- [ ] **Step 1: Write failing test (TG)**

```php
it('toggles active item via cat callback and collapses on second tap', function () {
    Http::fake([config('services.telegram.url', 'https://api.telegram.org').'/*' => Http::response(['ok' => true], 200)]);
    [$service, $pid, $chatId, $user] = $this->bootEditor(); // хелпер: пользователь в await_confirm с 2 items и msg_id

    // Первый тап — разворот пункта 1
    $service->handle(['callback_query' => $this->callback('cat:1', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['active'])->toBe(1);

    // Повторный тап по cat:1 — сворачивание
    $service->handle(['callback_query' => $this->callback('cat:1', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['active'])->toBeNull();
});

it('treats legacy edit:i as alias of cat:i (graceful for old keyboards)', function () {
    Http::fake([config('services.telegram.url', 'https://api.telegram.org').'/*' => Http::response(['ok' => true], 200)]);
    [$service, $pid, $chatId, $user] = $this->bootEditor();

    $service->handle(['callback_query' => $this->callback('edit:0', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['active'])->toBe(0); // алиас cat:0
});

it('resets active when deleting an item', function () {
    Http::fake([config('services.telegram.url', 'https://api.telegram.org').'/*' => Http::response(['ok' => true], 200)]);
    [$service, $pid, $chatId, $user] = $this->bootEditor();
    invokeProtected($service, 'patchState', [$pid, ['active' => 1]]); // пункт 1 развёрнут

    $service->handle(['callback_query' => $this->callback('del:1', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['active'])->toBeNull();
    expect($state['items'])->toHaveCount(1); // пункт удалён
});
```

> `bootEditor()` — хелпер, который ставит пользователя в состояние `await_confirm` с `items` (2 пункта), `msg_id`, `card_id`. Следовать существующим тестам `merge`/`replace`/`edit` в этом файле для подготовки state. Зеркалить в MAX-файле (`maxCallback(...)` вместо `$this->callback(...)`, `maxMessage(...)`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="toggles active item via cat callback"`
Expected: FAIL — ветки `cat:` нет; `edit:` переводит в `await_edit` (а не toggle `active`).

- [ ] **Step 3: Implement — заменить ветку `edit:` на `cat:`/`edit:` toggle**

В `handleCallback` заменить блок `if (str_starts_with($data, 'edit:'))` (~328–334) на:

```php
// Разворот/сворот пункта. edit:{i} — алиас cat:{i} для уже отправленных старых клавиатур.
// Внимание: 'cat:' длиной 4, 'edit:' длиной 5 — смещение зависит от префикса.
if (str_starts_with($data, 'cat:') || str_starts_with($data, 'edit:')) {
    $index = (int) substr($data, str_starts_with($data, 'edit:') ? 5 : 4);
    $state = $this->state($pid);

    if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
        $active = ($state['active'] ?? null) === $index ? null : $index;
        $this->patchState($pid, ['active' => $active]);
        $this->renderEditor($chatId, $state['msg_id'] ?? null, $state['items'], $active);
    }

    return;
}
```

- [ ] **Step 4: Implement — сброс `active` в ветке `del:`**

Обновить блок `if (str_starts_with($data, 'del:'))` (~336–347):

```php
if (str_starts_with($data, 'del:')) {
    $index = (int) substr($data, 4);
    $state = $this->state($pid);

    if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
        array_splice($state['items'], $index, 1);
        $state['active'] = null; // после удаления индексы сместились — сбрасываем разворот
        $this->setState($pid, 'await_confirm', $state);
        $this->renderEditor($chatId, $state['msg_id'] ?? null, $state['items'], null);
    }

    return;
}
```

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --filter="toggles active item via cat callback|legacy edit:i as alias|resets active when deleting"`
Expected: PASS (TG). Зеркально запустить MAX. Expected: PASS.

- [ ] **Step 6: Commit (СТОП — спросить пользователя)**

```bash
git add app/Services/Bot/AbstractBotConversationService.php tests/Feature/TelegramConversationServiceTest.php tests/Feature/MaxConversationServiceTest.php
git commit -m "feat(bot): аккордеон-разворот пункта по cat:i (edit:i — алиас), сброс active при удалении"
```

---

## Task 3: Правка названия и процента (`edt_t:{i}` / `edt_p:{i}`) + развёрнутый возврат из `await_note`

**Files:**
- Modify: `app/Services/Bot/AbstractBotConversationService.php` — `handleCallback` (добавить `edt_t:`/`edt_p:`), `handleMessage` (блок `await_edit` ~205–244), блок `await_note` (~188–203); новые методы `parseTitle`/`parsePercent`/`applyTitle`/`applyPercent`
- Test: оба feature-файла

**Interfaces:**
- Consumes: `parseTitle`/`parsePercent`/`applyTitle`/`applyPercent` (создаются ниже), `attachCategory(array $parsed, Collection $categories)`, `userCategories(int $userId): Collection`, `renderEditor(..., ?int $active)`.
- Produces: переходы `edt_t:{i}`/`edt_p:{i}` → `await_edit` с `{index, field}`; возврат в `await_confirm` с `{active: index}`.

- [ ] **Step 1: Write failing test (TG)**

```php
it('edt_t callback enters await_edit with field=title and updates title with re-match', function () {
    Http::fake([config('services.telegram.url', 'https://api.telegram.org').'/*' => Http::response(['ok' => true], 200)]);
    [$service, $pid, $chatId, $user] = $this->bootEditor();
    // В items[0] — категория «Рестораны» (category_id=null, 🆕); у пользователя есть категория «Аптеки»

    // Тап «Название» пункта 0
    $service->handle(['callback_query' => $this->callback('edt_t:0', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['name'])->toBe('await_edit');
    expect($state['index'])->toBe(0);
    expect($state['field'])->toBe('title');

    // Ввод нового названия
    $service->handle(['message' => $this->message('Аптеки', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['name'])->toBe('await_confirm');
    expect($state['active'])->toBe(0);                  // пункт остался развёрнут
    expect($state['items'][0]['title'])->toBe('Аптеки');
    expect($state['items'][0]['category_id'])->not->toBeNull(); // категория пересопоставлена → ✅
});

it('edt_p callback enters await_edit with field=percent and updates percent', function () {
    Http::fake([config('services.telegram.url', 'https://api.telegram.org').'/*' => Http::response(['ok' => true], 200)]);
    [$service, $pid, $chatId, $user] = $this->bootEditor();

    $service->handle(['callback_query' => $this->callback('edt_p:0', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['field'])->toBe('percent');

    // Процент с запятой нормализуется
    $service->handle(['message' => $this->message('3,5', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['items'][0]['percent'])->toBe(3.5);
});

it('rejects invalid title/percent input and stays in await_edit', function () {
    Http::fake([config('services.telegram.url', 'https://api.telegram.org').'/*' => Http::response(['ok' => true], 200)]);
    [$service, $pid, $chatId, $user] = $this->bootEditor();
    $service->handle(['callback_query' => $this->callback('edt_p:0', $user, $chatId)], ...);

    $service->handle(['message' => $this->message('не число', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['name'])->toBe('await_edit'); // остались ждать корректного ввода
});

it('await_note returns to await_confirm with the item expanded', function () {
    Http::fake([config('services.telegram.url', 'https://api.telegram.org').'/*' => Http::response(['ok' => true], 200)]);
    [$service, $pid, $chatId, $user] = $this->bootEditor();
    $service->handle(['callback_query' => $this->callback('note:0', $user, $chatId)], ...); // → await_note, index=0

    $service->handle(['message' => $this->message('MCC5812', $user, $chatId)], ...);
    $state = invokeProtected($service, 'state', [$pid]);
    expect($state['name'])->toBe('await_confirm');
    expect($state['active'])->toBe(0);                 // пункт развёрнут после правки примечания
    expect($state['items'][0]['mcc'])->toBe('MCC5812');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="edt_t callback|edt_p callback|rejects invalid title|await_note returns"`
Expected: FAIL — веток `edt_t:`/`edt_p:` нет; `await_note` не возвращает `active`.

- [ ] **Step 3: Implement — новые методы-парсеры и применялы**

Добавить рядом с `parseItem`/`sanitizeNote` (~940):

```php
/**
 * Парсит отдельное поле «название»: trim, без переносов/табов (ломают кнопку),
 * обрезка до 255 символов. null — если пусто.
 *
 * @param  string  $text  Сырой ввод названия
 * @return string|null Очищенное название либо null при пустом вводе
 */
protected static function parseTitle(string $text): ?string
{
    $title = trim(str_replace(["\r", "\n", "\t"], ' ', $text));

    if ($title === '') {
        return null;
    }

    return mb_substr($title, 0, 255);
}

/**
 * Парсит отдельное поле «процент»: первое число в вводе, запятая→точка.
 * null — если числа нет. 0 допустим (= «нет кешбэка», при сохранении пропускается).
 *
 * @param  string  $text  Сырой ввод процента
 * @return float|null Процент либо null при отсутствии числа
 */
protected static function parsePercent(string $text): ?float
{
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $text, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }

    return null;
}

/**
 * Применяет новое название к пункту и пересопоставляет категорию (название — ключ мэтчинга):
 * обновляются title (каноничный) и category_id; маркер может смениться ✅↔🆕. Процент/MCC не трогаются.
 *
 * @param  array  $items  Текущие пункты редактора
 * @param  int  $index  Индекс правимого пункта
 * @param  string  $title  Новое название
 * @param  int  $userId  ID пользователя (для загрузки категорий мэтчинга)
 * @return array Обновлённые пункты
 */
protected function applyTitle(array $items, int $index, string $title, int $userId): array
{
    $resolved = $this->attachCategory(['title' => $title, 'force_new' => false], $this->userCategories($userId));
    $items[$index]['title'] = $resolved['title'];
    $items[$index]['category_id'] = $resolved['category_id'];

    return $items;
}

/**
 * Применяет новый процент к пункту. Категория и название не затрагиваются.
 *
 * @param  array  $items  Текущие пункты редактора
 * @param  int  $index  Индекс правимого пункта
 * @param  float  $percent  Новый процент
 * @return array Обновлённые пункты
 */
protected function applyPercent(array $items, int $index, float $percent): array
{
    $items[$index]['percent'] = $percent;

    return $items;
}
```

- [ ] **Step 4: Implement — ветки `edt_t:`/`edt_p:` в `handleCallback`**

В `handleCallback` добавить перед `if (str_starts_with($data, 'note:'))` (~349):

```php
// Правка названия пункта
if (str_starts_with($data, 'edt_t:')) {
    $index = (int) substr($data, 6);
    $state = $this->state($pid);

    if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
        $title = e($state['items'][$index]['title'] ?? '');
        $this->sendTransient($pid, $chatId, "Пришли новое название для «{$title}»:");
        $this->setStateName($pid, 'await_edit', ['index' => $index, 'field' => 'title']);
    }

    return;
}

// Правка процента пункта
if (str_starts_with($data, 'edt_p:')) {
    $index = (int) substr($data, 6);
    $state = $this->state($pid);

    if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
        $title = e($state['items'][$index]['title'] ?? '');
        $this->sendTransient($pid, $chatId, "Пришли новый процент для «{$title}» (например, 5 или 3,5):");
        $this->setStateName($pid, 'await_edit', ['index' => $index, 'field' => 'percent']);
    }

    return;
}
```

- [ ] **Step 5: Implement — ветвление по `field` в `handleMessage` для `await_edit`**

В блоке `if ($state['name'] === 'await_edit' || $state['name'] === 'await_add')` (~205–244) добавить ветвление в самом начале (до существующего `parseItem`-пути). Существующий `parseItem`-путь остаётся для `await_add` и legacy `await_edit` без `field`:

```php
// Обработка состояний редактора (правка/добавление пункта)
if ($state['name'] === 'await_edit' || $state['name'] === 'await_add') {
    $index = $state['index'] ?? null;
    $field = $state['field'] ?? null; // только для await_edit (новая схема полей)
    $items = $state['items'] ?? [];

    // Правка отдельного поля (Название / Процент) из развёрнутого пункта
    if ($state['name'] === 'await_edit' && $field !== null && $index !== null && isset($items[$index])) {
        if ($field === 'title') {
            $title = self::parseTitle($text);
            if ($title === null) {
                $this->sendTransient($pid, $chatId, 'Не понял название. Пришли слово или фразу, например «Аптеки».');

                return;
            }
            $items = $this->applyTitle($items, $index, $title, $user->id);
        } else { // field === 'percent'
            $percent = self::parsePercent($text);
            if ($percent === null) {
                $this->sendTransient($pid, $chatId, 'Не понял процент. Пришли число, например «5» или «3,5».');

                return;
            }
            $items = $this->applyPercent($items, $index, $percent);
        }

        // Cleanup транзитного промпта правки; пункт остаётся развёрнут (active=index)
        if (! empty($state['last_bot_msg'])) {
            $this->deleteMessage($chatId, $state['last_bot_msg']);
        }
        $this->setStateName($pid, 'await_confirm', ['items' => $items, 'last_bot_msg' => null, 'active' => $index]);
        $this->renderEditor($chatId, $state['msg_id'] ?? null, $items, $index);

        return;
    }

    // Legacy-путь: полная строка «название процент [mcc]» (await_add и await_edit без field)
    $parsed = self::parseItem($text);
    // ... далее без изменений (существующий код 207–243)
```

- [ ] **Step 6: Implement — развёрнутый возврат из `await_note`**

В блоке `await_note` (~188–203) при возврате в `await_confirm` передать `active`:

```php
// ввод примечания → возврат с развёрнутым пунктом
$this->setStateName($pid, 'await_confirm', ['items' => $items, 'last_bot_msg' => null, 'active' => $index]);
$this->renderEditor($chatId, $state['msg_id'] ?? null, $items, $index);
```

(Было: `setStateName(..., ['items' => $items, 'last_bot_msg' => null]);` и `renderEditor($chatId, $state['msg_id'] ?? null, $items);` — добавить `'active' => $index` и 4-й аргумент `$index`.)

- [ ] **Step 7: Run tests to verify pass**

Run: `php artisan test --filter="edt_t callback|edt_p callback|rejects invalid title|await_note returns"`
Expected: PASS (TG). Зеркально MAX. Expected: PASS.

- [ ] **Step 8: Commit (СТОП — спросить пользователя)**

```bash
git add app/Services/Bot/AbstractBotConversationService.php tests/Feature/TelegramConversationServiceTest.php tests/Feature/MaxConversationServiceTest.php
git commit -m "feat(bot): раздельная правка названия/процента (edt_t/edt_p) с пересопоставлением категории"
```

---

## Task 4: Форматирование и полный набор тестов

**Files:**
- Modify: (только форматирование) `app/Services/Bot/AbstractBotConversationService.php`

- [ ] **Step 1: Pint**

Run: `vendor/bin/pint --dirty`
Expected: форматирование применено (или «already satisfied»). Если Pint изменил тестовые файлы — допустимо.

- [ ] **Step 2: Полный suite ботов**

Run: `php artisan test tests/Feature/TelegramConversationServiceTest.php tests/Feature/MaxConversationServiceTest.php`
Expected: PASS, всё зелёное. Если упал существующий тест на лейблы `edit:`/`del:`/`note:` — обновить его под новую раскладку (лэйаут изменился намеренно).

- [ ] **Step 3: Полный suite проекта (по запросу пользователя)**

Run: `php artisan test`
Expected: PASS. Зафиксировать результат.

- [ ] **Step 4: Commit (СТОП — спросить пользователя)**

```bash
git add -A
git commit -m "style(bot): pint + зелёные тесты редактора кешбэков"
```

---

## Self-Review (проверено автором плана)

**Spec coverage:**
- §4.1 Раскладка (широкая кнопка + поля) → Task 1.
- §4.2 Callback-формат (`cat`, `edt_t`, `edt_p`, `note`, `del`, `edit`-алиас) → Tasks 1–3.
- §4.3 State machine (без новых состояний; `active`/`field` в extra) → Tasks 2–3.
- §4.4 Правка полей + пересопоставление категории → Task 3 (`applyTitle`).
- §4.5 Крайние случаи: 0 пунктов (не сработает `isset($state['items'][$index])` → no-op, корректно); длинный title (обрезка 30 в `buildEditorKeyboard`); невалидный ввод (остаёмся в `await_edit`); `del` при развёрнутом (сброс `active`); повторный `cat` (toggle). → Tasks 1–3.
- §5 Файлы — только `AbstractBotConversationService` + тесты; адаптеры/`buildEditorText`/`parseItem`/БД не трогаются. → Учтено.
- §6 Тесты (обновление Reflection + новые кейсы) → Tasks 1–3.

**Placeholder scan:** TBD/TODO нет. Все кодовые шаги содержат конкретный код.

**Type consistency:** `buildEditorKeyboard(array, ?int)` / `renderEditor(..., ?int)` / `parseTitle(string): ?string` / `parsePercent(string): ?float` / `applyTitle(array, int, string, int): array` / `applyPercent(array, int, float): array` — едины во всех задачах. `active` (int|null) и `field` ('title'|'percent'|null) — едины. `substr($data, str_starts_with($data, 'edit:') ? 5 : 4)` для `cat:`/`edit:` (длины 4/5), `substr($data, 6)` для `edt_t:`/`edt_p:` (длина 6) — проверено по длине префиксов.
