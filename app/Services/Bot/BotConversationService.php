<?php

namespace App\Services\Bot;

use App\Models\Card;
use App\Models\Category;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BotConversationService
{
    private const STATE_TTL = 1800;

    /**
     * Создать новый экземпляр сервиса.
     */
    public function __construct(
        private TelegramBotService $bot,
        private CashbackImportService $import,
    ) {}

    /**
     * Единая точка входа для обработки update от Telegram.
     *
     * @param  array  $update  Данные от Telegram Webhook
     */
    public function handle(array $update): void
    {
        // 1. Извлекаем chatId и tgId
        $chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
        $tgId = (string) ($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? '');

        if ($chatId === null || $tgId === '') {
            return;
        }

        // Игнорируем групповые чаты: бот — личный ассистент кешбэка. Ответ в группу = спам
        // и утечка telegram_id участников в общий чат.
        $chatType = $update['message']['chat']['type']
            ?? $update['callback_query']['message']['chat']['type']
            ?? null;
        if ($chatType !== null && $chatType !== 'private') {
            return;
        }

        // 2. Ищем пользователя по telegram_id
        $user = User::where('telegram_id', $tgId)->first();
        if ($user === null) {
            $url = config('app.url').'/profile/bot-link?tg='.$tgId;
            $this->bot->sendMessage($chatId, 'Сначала привяжи аккаунт '.config('app.name').": {$url}");

            return;
        }

        // 3. Перенаправляем в обработчик callback или message под блокировкой по tgId,
        // чтобы двойной тап / ретрай доставки не дал гонку (дубль apply, дубль категорий).
        // Неблокирующе: если lock занят — значит уже обрабатывается, пропускаем апдейт.
        // TTL 60с: downloadPhoto + GigaChat recognize (до 15–40с) + sendMessage — должно влезть
        $lock = Cache::lock("bot.lock.{$tgId}", 60);
        if (! $lock->get()) {
            return;
        }

        try {
            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query'], $chatId, $user);
            } else {
                $this->handleMessage($update['message'] ?? [], $chatId, $user);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Обработка текстовых сообщений.
     *
     * @param  array  $message  Данные сообщения
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function handleMessage(array $message, int|string $chatId, User $user): void
    {
        $text = trim((string) ($message['text'] ?? ''));

        // Удаляем текстовое сообщение пользователя (команды/ввод) для чистоты чата.
        // Фото НЕ удаляем здесь — скрин нужен как референс при правке категорий,
        // удаляется только при сохранении/отмене (см. handleConfirm).
        if (! isset($message['photo'])) {
            $this->deleteUserMessage($chatId, $message);
        }

        $state = $this->state($user->telegram_id);

        // 4. Обработка команд и состояний
        // /start и /start <payload> (deep-link из кнопки «Привязать Telegram» шлёт «/start link»)
        if ($text === '/start' || str_starts_with($text, '/start ') || $text === '/menu') {
            $this->sendMenu($chatId, $user);

            return;
        }

        if ($text === 'Обновить кешбэк' || $state['name'] === 'await_card') {
            $this->sendCardKeyboard($chatId, $user);

            return;
        }

        if ($state['name'] === 'await_photo' && isset($message['photo'])) {
            $this->processPhotos($message, $chatId, $user);

            return;
        }

        // Фото вне состояния ожидания скрина — не обрабатываем, подсказываем порядок действий
        if (isset($message['photo'])) {
            $this->deleteUserMessage($chatId, $message);
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Сначала выбери карту (Обновить кешбэк), затем пришли скриншот.');

            return;
        }

        // Скрин прислан как файл (document), а не как изображение — не сможем распознать
        if (isset($message['document'])) {
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Пришли скрин как изображение, а не как файл — тогда я смогу его распознать.');

            return;
        }

        // Обработка состояний редактора (правка/добавление пункта)
        if ($state['name'] === 'await_edit' || $state['name'] === 'await_add') {
            $parsed = self::parseItem($text);

            if ($parsed === null) {
                $this->sendTransient((string) $user->telegram_id, $chatId, 'Не понял формат. Пришли «название процент», напр. `Аптеки 5`');

                return;
            }

            // Резолвим category_id: ✅ если категория есть, иначе 🆕 (будет создана при сохранении)
            $parsed['category_id'] = $this->resolveCategoryId($user->id, $parsed['title']);

            // Применяем правку к items
            $items = $state['items'] ?? [];
            if ($state['name'] === 'await_edit') {
                $index = $state['index'] ?? null;
                if ($index !== null && isset($items[$index])) {
                    $items[$index] = $parsed;
                }
            } else {
                $items[] = $parsed;
            }

            // Cleanup транзитного промпта правки и обновление состояния (одно чтение state)
            if (! empty($state['last_bot_msg'])) {
                $this->bot->deleteMessage($chatId, (int) $state['last_bot_msg']);
            }
            $this->setStateName((string) $user->telegram_id, 'await_confirm', ['items' => $items, 'last_bot_msg' => null]);

            $this->renderEditor($chatId, $state['msg_id'] ?? null, $items);

            return;
        }

        $this->sendTransient((string) $user->telegram_id, $chatId, 'Не понял. /menu — меню.');
    }

    /**
     * Отправка меню с приветствием и кнопкой "Обновить кешбэк".
     *
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function sendMenu(int|string $chatId, User $user): void
    {
        $count = Card::where('user_id', $user->id)->count();
        $keyboard = [[
            ['text' => 'Обновить кешбэк', 'callback_data' => 'cmd:update'],
        ]];

        $this->sendTransient((string) $user->telegram_id, $chatId, 'Привет, '.e($user->name)."! Карт: {$count}.", $keyboard);
        $this->setStateName((string) $user->telegram_id, 'idle');
    }

    /**
     * Отправка клавиатуры с картами пользователя.
     *
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function sendCardKeyboard(int|string $chatId, User $user): void
    {
        $cards = Card::where('user_id', $user->id)->get();

        if ($cards->isEmpty()) {
            $this->sendTransient((string) $user->telegram_id, $chatId, 'У тебя нет карт. Добавь карту в ЛК.');
            $this->setStateName((string) $user->telegram_id, 'idle');

            return;
        }

        $keyboard = [];
        foreach ($cards as $card) {
            $bankTitle = $card->bank?->title ?? 'Без банка';
            $cardNumber = $card->number;
            $keyboard[][] = [
                'text' => "{$bankTitle} {$cardNumber}",
                'callback_data' => 'card:'.$card->id,
            ];
        }

        $this->sendTransient((string) $user->telegram_id, $chatId, 'Выбери карту:', $keyboard);
        $this->setStateName((string) $user->telegram_id, 'await_card');
    }

    /**
     * Обработка callback кнопок.
     *
     * @param  array  $cb  Данные callback query
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function handleCallback(array $cb, int|string $chatId, User $user): void
    {
        // 7. Снимаем "часики" с кнопки (только если есть callback id — иначе мусорный запрос в TG)
        if (! empty($cb['id'])) {
            $this->bot->answerCallback($cb['id']);
        }

        $data = $cb['data'] ?? '';

        if ($data === 'cmd:update') {
            $this->sendCardKeyboard($chatId, $user);

            return;
        }

        if (str_starts_with($data, 'card:')) {
            $cardId = (int) substr($data, 5);

            // Проверяем, что карта принадлежит пользователю (user-scoping)
            if (! Card::where('id', $cardId)->where('user_id', $user->id)->exists()) {
                $this->bot->sendMessage($chatId, 'Карта не найдена.');

                return;
            }

            $this->sendTransient((string) $user->telegram_id, $chatId, 'Пришли скриншот категорий кешбэка.');
            $this->setStateName((string) $user->telegram_id, 'await_photo', ['card_id' => $cardId]);

            return;
        }

        if ($data === 'merge' || $data === 'replace' || $data === 'cancel') {
            $this->handleConfirm($data, $chatId, $user);

            return;
        }

        // Алиасы для обратной совместимости
        if ($data === 'save' || $data === 'apply') {
            $this->handleConfirm('merge', $chatId, $user);

            return;
        }

        // Обработка кнопок редактора
        if (str_starts_with($data, 'edit:')) {
            $index = (int) substr($data, 5);
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Пришли «название процент», напр. `Аптеки 5`');
            $this->setStateName((string) $user->telegram_id, 'await_edit', ['index' => $index]);

            return;
        }

        if (str_starts_with($data, 'del:')) {
            $index = (int) substr($data, 4);
            $state = $this->state($user->telegram_id);

            if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
                array_splice($state['items'], $index, 1);
                $this->setState((string) $user->telegram_id, 'await_confirm', $state);
                $this->renderEditor($chatId, $state['msg_id'] ?? null, $state['items']);
            }

            return;
        }

        if ($data === 'add') {
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Пришли «название процент», напр. `Кафе и рестораны 3.5`');
            $this->setStateName((string) $user->telegram_id, 'await_add');

            return;
        }
    }

    /**
     * Обработка фото и импорт кешбэка.
     *
     * @param  array  $message  Данные сообщения с фото
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function processPhotos(array $message, int|string $chatId, User $user): void
    {
        $state = $this->state($user->telegram_id);
        $cardId = $state['card_id'] ?? null;

        if ($cardId === null) {
            $this->sendMenu($chatId, $user);

            return;
        }

        // Rate-limit: защита от спама фото (каждое = платный запрос к GigaChat), ≤5/мин
        $rlKey = "bot.rl.photo.{$user->telegram_id}";
        $count = (int) Cache::get($rlKey, 0);
        if ($count >= 5) {
            $this->deleteUserMessage($chatId, $message);
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Слишком много скринов за минуту. Подожди немного.');

            return;
        }
        Cache::put($rlKey, $count + 1, 60);

        // 8. Берём самое крупное фото
        $photo = end($message['photo']);
        $path = $this->bot->downloadPhoto($photo['file_id']);

        if ($path === null) {
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Не удалось скачать фото.');

            return;
        }

        // Сохраняем скриншот в карту (в public/card_cashback_image/ — тот же путь,
        // что читает веб и AiService), чтобы он появился в ЛК у карты.
        $imageFilename = 'card_'.uniqid('', true).'.jpg';
        Storage::disk('public')->put('card_cashback_image/'.$imageFilename, file_get_contents($path));

        // Индикатор распознавания: нативный «печатает…» + явное сообщение,
        // т.к. GigaChat отвечает не сразу. Сообщение самоудалится перед редактором.
        $this->bot->sendChatAction($chatId, 'typing');
        $this->sendTransient((string) $user->telegram_id, $chatId, '⏳ Распознаю скриншот…');

        // Импортируем кешбэк с гарантированным удалением временного файла
        try {
            $result = $this->import->import($user->id, $cardId, [$path]);
        } finally {
            @unlink($path);
        }

        // Формируем items: существующие категории (✅) + новые (🆕, category_id=null)
        $items = collect($result['saved'])->map(fn ($s) => [
            'title' => $s['title'],
            'percent' => (float) $s['percent'],
            'category_id' => $s['category_id'],
        ])->values()->all();

        foreach ($result['skipped'] as $skip) {
            $items[] = [
                'title' => $skip['title'],
                'percent' => (float) ($skip['percent'] ?? 0),
                'category_id' => null,
            ];
        }

        // AI ничего не распознал → не открываем пустой редактор, объясняем причину
        if (empty($items)) {
            Storage::disk('public')->delete('card_cashback_image/'.$imageFilename);
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Не удалось распознать категории на скрине. Попробуй другое фото или /menu.');
            $this->setStateName((string) $user->telegram_id, 'await_photo', ['card_id' => $cardId]);

            return;
        }

        // Cleanup: удаляем транзитный промпт «Пришли скриншот».
        // Сам скрин (фото) НЕ удаляем — он нужен пользователю как референс при правке;
        // удалим только при сохранении/отмене (см. handleConfirm, photo_msg_id).
        $currentState = $this->state((string) $user->telegram_id);
        if (! empty($currentState['last_bot_msg'])) {
            $this->bot->deleteMessage($chatId, (int) $currentState['last_bot_msg']);
        }

        // Отправляем сообщение-редактор (НЕ транзитное — живёт в msg_id)
        $messageId = $this->renderEditor($chatId, null, $items);

        $this->setState((string) $user->telegram_id, 'await_confirm', [
            'card_id' => $cardId,
            'image' => $imageFilename,
            'items' => $items,
            'msg_id' => $messageId,
            'photo_msg_id' => $message['message_id'] ?? null,
            'last_bot_msg' => null,
        ]);
    }

    /**
     * Подтверждение или отмена применения кешбэка.
     *
     * @param  string  $action  'merge', 'replace' или 'cancel'
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function handleConfirm(string $action, int|string $chatId, User $user): void
    {
        $state = $this->state($user->telegram_id);

        // 9. Проверяем состояние
        if (($state['name'] ?? null) !== 'await_confirm') {
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Сессия истекла. /menu');
            $this->setState((string) $user->telegram_id, 'idle');

            return;
        }

        $cardId = (int) ($state['card_id'] ?? 0);
        $items = $state['items'] ?? [];

        // Пустой список при сохранении — применять нечего, остаёмся в редакторе
        if (($action === 'merge' || $action === 'replace') && empty($items)) {
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Список пуст. Добавь категорию (➕) или нажми «Отменить».');

            return;
        }

        // Обработка отмены
        if ($action === 'cancel') {
            if (! empty($state['image'])) {
                Storage::disk('public')->delete('card_cashback_image/'.$state['image']);
            }
            $this->clearEditorMessages($chatId, $state);
            $this->sendTransient((string) $user->telegram_id, $chatId, 'Отменено.');
            $this->setState((string) $user->telegram_id, 'idle');

            return;
        }

        // Обработка merge и replace
        if (in_array($action, ['merge', 'replace'], true) && $cardId > 0 && ! empty($items)) {
            // Пересобираем raw из текущих items
            $raw = array_map(fn ($it) => [
                'category' => $it['title'],
                'cashback' => $it['percent'],
            ], $items);

            // Проверяем владение картой
            $card = Card::where('id', $cardId)->where('user_id', $user->id)->first();
            if (! $card) {
                $this->sendTransient((string) $user->telegram_id, $chatId, 'Карта не найдена.');
                $this->setState((string) $user->telegram_id, 'idle');

                return;
            }

            // Атомарно: удаление старых категорий (replace) + image + apply. Если apply упадёт,
            // БД откатится — не останемся с «голой» картой (старое стёрто, новое не записано).
            $applyResult = DB::transaction(function () use ($action, $cardId, $card, $state, $user, $raw) {
                if ($action === 'replace') {
                    \App\Models\Cashback::where('card_id', $cardId)->delete();
                }

                // Сохраняем изображение в карту
                if (isset($state['image'])) {
                    if (! empty($card->cashback_image) && $card->cashback_image !== $state['image']) {
                        Storage::disk('public')->delete('card_cashback_image/'.$card->cashback_image);
                    }
                    $card->cashback_image = $state['image'];
                    $card->save();
                }

                // Применяем кешбэк (создаёт недостающие категории, возвращает список созданных)
                return $this->import->apply($user->id, $cardId, $raw);
            });
            $created = $applyResult['created'] ?? [];

            // Cleanup: удаляем редактор и скрин, оставляя только результат
            $this->clearEditorMessages($chatId, $state);

            // Отправляем сообщение о результате
            $count = count($raw);
            $newPart = $created !== []
                ? ' ('.count($created).' нов.: '.implode(', ', $created).')'
                : '';
            $msg = $action === 'merge'
                ? ($count > 0 ? "Готово! Добавил/обновил {$count} категорий{$newPart}." : 'Готово! Список был пуст.')
                : ($count > 0 ? "Готово! Старые удалены, записано {$count} категорий{$newPart}." : 'Готово! Список был пуст.');
            $this->sendTransient((string) $user->telegram_id, $chatId, $msg);

            $this->setState((string) $user->telegram_id, 'idle');

            return;
        }

        // Если action неизвестный или нет данных
        $this->sendTransient((string) $user->telegram_id, $chatId, 'Ошибка применения. /menu');
        $this->setState((string) $user->telegram_id, 'idle');
    }

    /**
     * Формирует текст редактора с списком распознанных категорий.
     *
     * @param  array  $items  Массив элементов [['title'=>string,'percent'=>float], ...]
     * @return string Текст сообщения
     */
    private function buildEditorText(array $items): string
    {
        if (empty($items)) {
            return "Проверь распознанное (✅ — ваша категория, 🆕 — новая, будет создана):\n\nСписок пуст. Нажми «➕ Добавить категорию».";
        }

        $lines = ['Проверь распознанное (✅ — ваша, 🆕 — новая):'];
        foreach ($items as $i => $item) {
            $mark = empty($item['category_id']) ? '🆕' : '✅';
            // e() обязательно: title из AI/ручного ввода, parse_mode=HTML — без экранирования
            // символы <, >, & ломают рендер (Telegram 400 → editMessageText игнорируется).
            $lines[] = ($i + 1).'. '.$mark.' '.e($item['title']).' — '.$item['percent'].'%';
        }

        return implode("\n", $lines);
    }

    /**
     * Формирует inline-клавиатуру для редактора.
     *
     * @param  array  $items  Массив элементов [['title'=>string,'percent'=>float], ...]
     * @return array Массив клавиатуры для Telegram API
     */
    private function buildEditorKeyboard(array $items): array
    {
        $keyboard = [];

        // «Добавить категорию» — первой, чтобы всегда была под рукой
        $keyboard[] = [['text' => '➕ Добавить категорию', 'callback_data' => 'add']];

        // Кнопки редактирования и удаления для каждого элемента
        foreach ($items as $i => $item) {
            // Формируем текст кнопки редактирования с названием и процентом
            $title = $item['title'] ?? '';
            $percent = $item['percent'] ?? 0;

            // Обрезаем title если слишком длинный (TG button text limit ~64 bytes)
            if (mb_strlen($title) > 30) {
                $title = mb_substr($title, 0, 30).'…';
            }

            // Маркер статуса: ✅ — существующая категория, 🆕 — новая (будет создана)
            $mark = empty($item['category_id']) ? '🆕' : '✅';
            $editText = "{$mark} {$title} {$percent}%";

            $keyboard[] = [
                ['text' => $editText, 'callback_data' => 'edit:'.$i],
                ['text' => '🗑', 'callback_data' => 'del:'.$i],
            ];
        }

        // Кнопки сохранения — каждая на всю ширину своей строки,
        // чтобы текст был виден полностью (в строке Telegram делит кнопки поровну).
        $keyboard[] = [['text' => '💾 Сохранить (добавить к старым)', 'callback_data' => 'merge']];
        $keyboard[] = [['text' => '♻️ Заменить (удалить старые)', 'callback_data' => 'replace']];
        $keyboard[] = [['text' => 'Отменить', 'callback_data' => 'cancel']];

        return $keyboard;
    }

    /**
     * Парсит текст пользователя для извлечения названия и процента.
     *
     * @param  string  $text  Текст от пользователя (например "Аптеки 5" или "Кафе и рестораны 3.5")
     * @return array|null Массив ['title'=>string, 'percent'=>float] или null если не удалось распарсить
     */
    public static function parseItem(string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }

        // Regex: захватываем всё до последнего числа как название, последнее число как процент
        if (! preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)%?\s*$/u', $text, $matches)) {
            return null;
        }

        $title = trim($matches[1]);
        $percent = (float) str_replace(',', '.', $matches[2]);

        if ($title === '' || $percent <= 0 || $percent > 100) {
            return null;
        }

        return ['title' => $title, 'percent' => $percent];
    }

    /**
     * Обновляет часть состояния, сохраняя имя и TTL.
     *
     * @param  string  $tgId  Telegram ID пользователя
     * @param  array  $patch  Патч для слияния с текущим состоянием
     */
    private function patchState(string $tgId, array $patch): void
    {
        $current = $this->state($tgId);
        $merged = array_merge($current, $patch);
        $name = $merged['name'] ?? 'idle';
        unset($merged['name']);
        $this->setState($tgId, $name, $merged);
    }

    /**
     * Меняет имя состояния, сохраняя остальные данные (включая last_bot_msg).
     *
     * В отличие от setState(), не затирает накопленные данные — это важно после
     * sendTransient(), который положил last_bot_msg: обычный setState() стёр бы его,
     * и следующий transient не смог бы удалить предыдущее сообщение бота.
     *
     * @param  string  $tgId  Telegram ID пользователя
     * @param  string  $name  Новое имя состояния
     * @param  array  $extra  Доп. данные для слияния
     */
    private function setStateName(string $tgId, string $name, array $extra = []): void
    {
        $current = $this->state($tgId);
        unset($current['name']);
        $this->setState($tgId, $name, array_merge($current, $extra));
    }

    /**
     * Отправляет транзитное (одноразовое) сообщение, предварительно удалив
     * предыдущее транзитное, чтобы в чате не копился мусор. Редактор (msg_id) НЕ затрагивается.
     *
     * @param  string  $tgId  Telegram ID пользователя
     * @param  int|string  $chatId  ID чата
     * @param  string  $text  Текст сообщения
     * @param  array  $keyboard  Inline-клавиатура
     * @return int|string|null Message ID отправленного сообщения
     */
    private function sendTransient(string $tgId, int|string $chatId, string $text, array $keyboard = []): int|string|null
    {
        $state = $this->state($tgId);
        if (! empty($state['last_bot_msg'])) {
            $this->bot->deleteMessage($chatId, (int) $state['last_bot_msg']);
        }

        $msgId = $this->bot->sendMessage($chatId, $text, $keyboard);
        $this->patchState($tgId, ['last_bot_msg' => $msgId]);

        return $msgId;
    }

    /**
     * Удаляет сообщение пользователя (фото/ввод) для очистки чата.
     *
     * @param  int|string  $chatId  ID чата
     * @param  array  $message  Данные сообщения от Telegram
     */
    private function deleteUserMessage(int|string $chatId, array $message): void
    {
        $id = $message['message_id'] ?? null;
        if ($id !== null) {
            $this->bot->deleteMessage($chatId, (int) $id);
        }
    }

    /**
     * Рендерит редактор категорий: обновляет существующее сообщение (edit) или
     * отправляет новое (fallback при отсутствии msg_id). Возвращает message_id нового.
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string|null  $msgId  ID сообщения редактора (null — отправить новое)
     * @param  array  $items  Элементы редактора
     * @return int|string|null Message ID нового сообщения или null при edit
     */
    private function renderEditor(int|string $chatId, int|string|null $msgId, array $items): int|string|null
    {
        $text = $this->buildEditorText($items);
        $keyboard = $this->buildEditorKeyboard($items);

        if ($msgId !== null) {
            $this->bot->editMessageText($chatId, (int) $msgId, $text, $keyboard);

            return null;
        }

        return $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * Удаляет сообщение-редактор и скрин пользователя (cleanup при сохранении/отмене).
     *
     * @param  int|string  $chatId  ID чата
     * @param  array  $state  Состояние с msg_id и photo_msg_id
     */
    private function clearEditorMessages(int|string $chatId, array $state): void
    {
        if (! empty($state['msg_id'])) {
            $this->bot->deleteMessage($chatId, (int) $state['msg_id']);
        }
        if (! empty($state['photo_msg_id'])) {
            $this->bot->deleteMessage($chatId, (int) $state['photo_msg_id']);
        }
    }

    /**
     * Находит id существующей категории пользователя по точному совпадению названия
     * (нормализованное: регистр/пробелы игнорируются). null — категория новая.
     *
     * @param  int  $userId  ID пользователя
     * @param  string  $title  Введённое название
     * @return int|null ID категории или null
     */
    private function resolveCategoryId(int $userId, string $title): ?int
    {
        $norm = self::normTitle($title);

        $category = Category::query()
            ->where('user_id', $userId)
            ->get(['id', 'title'])
            ->first(fn ($c) => self::normTitle($c->title) === $norm);

        return $category?->id;
    }

    /**
     * Нормализует название: нижний регистр + схлопывание пробелов + trim.
     */
    private static function normTitle(string $title): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($title)));
    }

    /**
     * Получить состояние пользователя из кэша.
     *
     * @param  string  $tgId  Telegram ID пользователя
     * @return array Массив с ключами 'name' и дополнительными данными
     */
    private function state(string $tgId): array
    {
        return Cache::get("bot.state.{$tgId}", ['name' => 'idle']);
    }

    /**
     * Установить состояние пользователя в кэше.
     *
     * @param  string  $tgId  Telegram ID пользователя
     * @param  string  $name  Название состояния
     * @param  array  $extra  Дополнительные данные для сохранения
     */
    private function setState(string $tgId, string $name, array $extra = []): void
    {
        Cache::put(
            "bot.state.{$tgId}",
            array_merge(['name' => $name], $extra),
            now()->addSeconds(self::STATE_TTL)
        );
    }
}
