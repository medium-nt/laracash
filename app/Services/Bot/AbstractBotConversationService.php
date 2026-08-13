<?php

namespace App\Services\Bot;

use App\Models\Card;
use App\Models\Category;
use App\Models\User;
use App\Services\CategoryMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Общий диалоговый движок бот-ассистента кешбэка (state machine в Cache).
 *
 * Содержит всю платформенно-независимую логику разговора: точки входа handle(),
 * стейт-машину (await_card → await_photo → await_confirm → await_edit/add/note),
 * парсинг пунктов, редактор категорий, применение кешбэка, state/lock/rate-limit
 * в Cache. Платформенная специфика (формат update, клавиатуры, транспорт, cleanup)
 * вынесена в abstract-хуки — наследники (Telegram/Max) реализуют только их.
 *
 * Ключевая асимметрия: бот Telegram может удалять сообщения пользователя
 * (включая скрин), бот MAX — только свои. Управляется флагом canDeleteUserMessages()
 * и полем photo_msg_id (хранится только когда удаление разрешено).
 */
abstract class AbstractBotConversationService
{
    private const STATE_TTL = 1800;

    /**
     * Создать новый экземпляр сервиса.
     */
    public function __construct(
        protected CashbackImportService $import,
    ) {}

    // =========================================================================
    // Точка входа (template method)
    // =========================================================================

    /**
     * Единая точка входа: извлекает chat/user id → ищет пользователя →
     * под блокировкой по id пользователя роутит update в handleMessage/handleCallback.
     *
     * @param  array  $update  Данные от webhook платформы
     */
    final public function handle(array $update): void
    {
        $chatId = $this->extractChatId($update);
        $pid = $this->extractUserId($update);

        if ($chatId === null || $pid === '') {
            return;
        }

        if (! $this->isRelevantUpdate($update, $chatId)) {
            return;
        }

        // Ищем пользователя по платформенному id (telegram_id / max_id)
        $user = User::where($this->userIdColumn(), $pid)->first();
        if ($user === null) {
            $this->promptLink($update, $chatId, $pid);

            return;
        }

        // Блокировка по id пользователя: двойной тап / ретрай доставки не должен
        // дать гонку (дубль apply, дубль категорий). Неблокирующе: если lock занят —
        // значит уже обрабатывается, пропускаем. TTL 60с: downloadPhoto + GigaChat
        // recognize (до 15–40с) + sendMessage — должно влезть.
        $lock = Cache::lock($this->lockKey($pid), 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->dispatch($update, $chatId, $user);
        } finally {
            $lock->release();
        }
    }

    /**
     * Просит привязать аккаунт, если пользователь не найден.
     *
     * @param  array  $update  Данные webhook (для снятия «часиков» с callback)
     * @param  int|string  $chatId  ID чата
     * @param  string  $pid  Платформенный id пользователя
     */
    protected function promptLink(array $update, int|string $chatId, string $pid): void
    {
        // Снимаем «часики» с callback-кнопки (напр. «Проверить»), иначе она висит до таймаута
        [$cbId] = $this->extractCallback($update);
        if ($cbId !== '') {
            $this->answerCallback($cbId);
        }

        $url = config('app.url').$this->linkPath().$pid;
        $keyboard = [[$this->makeButton('Проверить', 'cmd:recheck')]];
        $this->sendMessage($chatId, 'Сначала привяжи аккаунт '.config('app.name').": {$url}", $keyboard);
    }

    // =========================================================================
    // Роутинг (реализуется наследником: формат update у платформ разный)
    // =========================================================================

    /**
     * Роутит update в обработчик сообщения/callback внутри блокировки.
     *
     * @param  array  $update  Полный webhook-update
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    abstract protected function dispatch(array $update, int|string $chatId, User $user): void;

    // =========================================================================
    // Обработка сообщения
    // =========================================================================

    /**
     * Обработка текстового сообщения (и фото как вложения).
     *
     * @param  array  $message  Объект сообщения из update
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function handleMessage(array $message, int|string $chatId, User $user): void
    {
        $text = trim((string) $this->extractText($message));
        $pid = $this->platformUserId($user);
        $photo = $this->extractPhoto($message);

        // Удаляем текстовое сообщение пользователя (команды/ввод) для чистоты чата.
        // Фото НЕ удаляем здесь — скрин нужен как референс при правке категорий,
        // удаляется только при сохранении/отмене (см. handleConfirm).
        // Только для платформ, где бот может удалять чужие сообщения.
        if ($photo === null) {
            $this->tryDeleteUserMessage($chatId, $message);
        }

        $state = $this->state($pid);

        // /start и /start <payload> (deep-link), /menu
        if ($text === '/start' || str_starts_with($text, '/start ') || $text === '/menu') {
            $this->sendMenu($chatId, $user);

            return;
        }

        if ($text === 'Обновить кешбэк' || $state['name'] === 'await_card') {
            $this->sendCardKeyboard($chatId, $user);

            return;
        }

        if ($state['name'] === 'await_photo' && $photo !== null) {
            $this->processPhotos($message, $chatId, $user);

            return;
        }

        // В await_photo можно ввести категории и текстом — по строке «Категория процент»
        if ($state['name'] === 'await_photo' && $text !== '') {
            $this->processTextList($text, $chatId, $user);

            return;
        }

        // Фото вне состояния ожидания скрина — подсказываем порядок действий
        if ($photo !== null) {
            $this->tryDeleteUserMessage($chatId, $message);
            $this->sendTransient($pid, $chatId, 'Сначала выбери карту (Обновить кешбэк), затем пришли скриншот.');

            return;
        }

        // Скрин прислан как файл (document), а не как изображение — не сможем распознать
        if ($this->hasDocument($message)) {
            $this->sendTransient($pid, $chatId, 'Пришли скрин как изображение, а не как файл — тогда я смогу его распознать.');

            return;
        }

        // Ввод примечания (MCC/условие) для конкретного пункта редактора
        if ($state['name'] === 'await_note') {
            $index = $state['index'] ?? null;
            $items = $state['items'] ?? [];
            if ($index !== null && isset($items[$index])) {
                $items[$index]['mcc'] = ($text === '' || $text === '/skip')
                    ? '' : self::sanitizeNote($text);

                if (! empty($state['last_bot_msg'])) {
                    $this->deleteMessage($chatId, $state['last_bot_msg']);
                }
                $this->setStateName($pid, 'await_confirm', ['items' => $items, 'last_bot_msg' => null]);
                $this->renderEditor($chatId, $state['msg_id'] ?? null, $items);
            }

            return;
        }

        // Обработка состояний редактора (правка/добавление пункта)
        if ($state['name'] === 'await_edit' || $state['name'] === 'await_add') {
            $parsed = self::parseItem($text);

            if ($parsed === null) {
                $this->sendTransient($pid, $chatId, "Не понял формат. Пришли «название процент»:\n• Аптеки 5\n• +Кафе 5 (новая категория)");

                return;
            }

            // Резолвим category_id: ✅ если категория есть, иначе 🆕 (будет создана при сохранении)
            $parsed = $this->attachCategory($parsed, $this->userCategories($user->id));

            // Применяем правку к items
            $items = $state['items'] ?? [];
            if ($state['name'] === 'await_edit') {
                $index = $state['index'] ?? null;
                if ($index !== null && isset($items[$index])) {
                    // Если юзер не дописал примечание (mcc='') — сохраняем прежнее,
                    // иначе правка процента «Аптеки 7» затёрла бы уже заданный MCC.
                    // Убрать/изменить примечание точечно — кнопка 📝 (await_note, /skip).
                    if ($parsed['mcc'] === '') {
                        $parsed['mcc'] = $items[$index]['mcc'] ?? '';
                    }
                    $items[$index] = $parsed;
                }
            } else {
                $items[] = $parsed;
            }

            // Cleanup транзитного промпта правки и обновление состояния
            if (! empty($state['last_bot_msg'])) {
                $this->deleteMessage($chatId, $state['last_bot_msg']);
            }
            $this->setStateName($pid, 'await_confirm', ['items' => $items, 'last_bot_msg' => null]);

            $this->renderEditor($chatId, $state['msg_id'] ?? null, $items);

            return;
        }

        $this->sendTransient($pid, $chatId, 'Не понял. /menu — меню.');
    }

    // =========================================================================
    // Обработка callback
    // =========================================================================

    /**
     * Обработка callback (нажатие inline-кнопки).
     *
     * @param  array  $update  Полный webhook-update (callback извлекается платформенно)
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function handleCallback(array $update, int|string $chatId, User $user): void
    {
        $pid = $this->platformUserId($user);

        // Снимаем «часики» с кнопки и достаём payload (формат callback у платформ разный)
        [$cbId, $data] = $this->extractCallback($update);
        if ($cbId !== '') {
            $this->answerCallback($cbId);
        }

        if ($data === 'cmd:update') {
            $this->sendCardKeyboard($chatId, $user);

            return;
        }

        // «Проверить» на сообщении о привязке: если юзер уже привязался в ЛК,
        // верхний lookup в handle() найдёт его и попадёт сюда — пускаем в бот.
        if ($data === 'cmd:recheck') {
            $this->sendMenu($chatId, $user);

            return;
        }

        if (str_starts_with($data, 'card:')) {
            $cardId = (int) substr($data, 5);

            // Проверяем, что карта принадлежит пользователю (user-scoping)
            $card = Card::where('id', $cardId)->where('user_id', $user->id)->first();
            if ($card === null) {
                $this->sendMessage($chatId, 'Карта не найдена.');

                return;
            }

            $label = e(($card->bank?->title ?? 'Без банка').' '.$card->number);
            $this->sendTransient($pid, $chatId, "Пришли скриншот категорий кешбэка по карте {$label} 📸\nИли введи их текстом — по одной в строке «Категория процент».\n\nПримеры:\n• Аптеки 5\n• Кафе 3,5\n• Аптеки 5 только 03\n\nНужна отдельная категория, хотя похожая уже есть? Начни строку с «+»:\n• +Кафе 5");
            $this->setStateName($pid, 'await_photo', ['card_id' => $cardId]);

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
            $this->sendTransient($pid, $chatId, "Пришли «название процент» — можно дописать примечание через пробел.\n\nПримеры:\n• Аптеки 5\n• Аптеки 5 только 03\n• +Кафе 5 (отдельная категория)");
            $this->setStateName($pid, 'await_edit', ['index' => $index]);

            return;
        }

        if (str_starts_with($data, 'del:')) {
            $index = (int) substr($data, 4);
            $state = $this->state($pid);

            if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
                array_splice($state['items'], $index, 1);
                $this->setState($pid, 'await_confirm', $state);
                $this->renderEditor($chatId, $state['msg_id'] ?? null, $state['items']);
            }

            return;
        }

        if (str_starts_with($data, 'note:')) {
            $index = (int) substr($data, 5);
            $state = $this->state($pid);

            if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
                $title = e($state['items'][$index]['title'] ?? '');
                $this->sendTransient($pid, $chatId, "Пришли примечание для «{$title}» (MCC код или условие). /skip — убрать примечание.");
                $this->setStateName($pid, 'await_note', ['index' => $index]);
            }

            return;
        }

        if ($data === 'add') {
            $this->sendTransient($pid, $chatId, "Пришли «название процент» — можно дописать примечание через пробел.\n\nПримеры:\n• Кафе 3.5\n• Аптеки 5 только 03\n• +Кафе 5 (отдельная категория)");
            $this->setStateName($pid, 'await_add');

            return;
        }
    }

    // =========================================================================
    // Импорт: фото и текстовый список
    // =========================================================================

    /**
     * Обработка фото: скачивание, распознавание, построение редактора.
     *
     * @param  array  $message  Объект сообщения с фото
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function processPhotos(array $message, int|string $chatId, User $user): void
    {
        $pid = $this->platformUserId($user);
        $state = $this->state($pid);
        $cardId = $state['card_id'] ?? null;

        if ($cardId === null) {
            $this->sendMenu($chatId, $user);

            return;
        }

        // Rate-limit: защита от спама фото (каждое = платный запрос к GigaChat), ≤5/мин
        $rlKey = $this->rateLimitKey($pid);
        $count = (int) Cache::get($rlKey, 0);
        if ($count >= 5) {
            $this->tryDeleteUserMessage($chatId, $message);
            $this->sendTransient($pid, $chatId, 'Слишком много скринов за минуту. Подожди немного.');

            return;
        }
        Cache::put($rlKey, $count + 1, 60);

        $photo = $this->extractPhoto($message);
        $path = $this->downloadPhoto($photo['source'] ?? '');

        if ($path === null) {
            $this->sendTransient($pid, $chatId, 'Не удалось скачать фото.');

            return;
        }

        // Сохраняем скриншот в карту (в public/card_cashback_image/ — тот же путь,
        // что читает веб и AiService), чтобы он появился в ЛК у карты.
        $imageFilename = 'card_'.uniqid('', true).'.jpg';
        Storage::disk('public')->put('card_cashback_image/'.$imageFilename, file_get_contents($path));

        // Индикатор распознавания: нативный «печатает…» + явное сообщение,
        // т.к. GigaChat отвечает не сразу. Сообщение самоудалится перед редактором.
        $this->sendChatAction($chatId, 'typing');
        $this->sendTransient($pid, $chatId, '⏳ Распознаю скриншот…');

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
            'mcc' => '',
        ])->values()->all();

        foreach ($result['skipped'] as $skip) {
            $items[] = [
                'title' => $skip['title'],
                'percent' => (float) ($skip['percent'] ?? 0),
                'category_id' => null,
                'mcc' => '',
            ];
        }

        // AI ничего не распознал → не открываем пустой редактор, объясняем причину
        if (empty($items)) {
            Storage::disk('public')->delete('card_cashback_image/'.$imageFilename);
            $this->sendTransient($pid, $chatId, 'Не удалось распознать категории на скрине. Попробуй другое фото или /menu.');
            $this->setStateName($pid, 'await_photo', ['card_id' => $cardId]);

            return;
        }

        // Cleanup: удаляем транзитный промпт «Пришли скриншот».
        // Сам скрин (фото) НЕ удаляем (где разрешено) — нужен юзеру как референс
        // при правке; удалим только при сохранении/отмене (см. handleConfirm, photo_msg_id).
        $currentState = $this->state($pid);
        if (! empty($currentState['last_bot_msg'])) {
            $this->deleteMessage($chatId, $currentState['last_bot_msg']);
        }

        // Отправляем сообщение-редактор (НЕ транзитное — живёт в msg_id)
        $messageId = $this->renderEditor($chatId, null, $items);

        // photo_msg_id хранится только когда бот может удалить скрин пользователя
        $photoMsgId = $this->canDeleteUserMessages() ? ($photo['msg_id'] ?? null) : null;

        $this->setState($pid, 'await_confirm', [
            'card_id' => $cardId,
            'image' => $imageFilename,
            'items' => $items,
            'msg_id' => $messageId,
            'photo_msg_id' => $photoMsgId,
            'last_bot_msg' => null,
        ]);
    }

    /**
     * Парсит текстовый список категорий (по строкам «Категория процент») и открывает редактор.
     *
     * Валидные строки попадают в редактор (с category_id: ✅ своя / 🆕 новая),
     * невалидные — отдельным сообщением «Не понял строки». Если не распознано ни одной
     * строки — показываем пример формата и остаёмся в await_photo. Домен (image, фото-референс)
     * не используется: это альтернативный путь ввода без скриншота.
     *
     * @param  string  $text  Многострочный ввод пользователя
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function processTextList(string $text, int|string $chatId, User $user): void
    {
        $pid = $this->platformUserId($user);
        $state = $this->state($pid);
        $cardId = $state['card_id'] ?? null;

        if ($cardId === null) {
            $this->sendTransient($pid, $chatId, 'Сессия истекла. /menu');
            $this->setState($pid, 'idle');

            return;
        }

        $items = [];
        $invalid = [];
        // Категории пользователя грузятся ОДИН раз — attachCategory работает по коллекции,
        // иначе был N+1 SELECT на каждую строку списка.
        $categories = $this->userCategories($user->id);
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parsed = self::parseItem($line);
            if ($parsed === null) {
                $invalid[] = $line;

                continue;
            }
            $parsed = $this->attachCategory($parsed, $categories);
            $items[] = $parsed;
        }

        // Ничего не распознали — не открываем пустой редактор, подсказываем формат
        if (empty($items)) {
            $this->sendTransient($pid, $chatId, "Не получилось распознать категории.\n\nФормат — по одной в строке «Категория процент». Примеры:\n• Аптеки 5\n• Кафе 3,5\n• +Кафе 5 (отдельная категория)");

            return;
        }

        // Cleanup транзитного промпта «Пришли скриншот …»
        $currentState = $this->state($pid);
        if (! empty($currentState['last_bot_msg'])) {
            $this->deleteMessage($chatId, $currentState['last_bot_msg']);
        }

        // Редактор (НЕ транзитное — живёт в msg_id). image/photo_msg_id НЕ кладём: текстовый путь.
        $messageId = $this->renderEditor($chatId, null, $items);

        $this->setState($pid, 'await_confirm', [
            'card_id' => $cardId,
            'items' => $items,
            'msg_id' => $messageId,
            'last_bot_msg' => null,
        ]);

        // Невалидные строки — отдельной подсказкой (sendTransient запишет last_bot_msg поверх null)
        if (! empty($invalid)) {
            $this->sendTransient(
                $pid,
                $chatId,
                'Не понял строки: '.implode(' | ', array_map(fn ($l) => '«'.e($l).'»', $invalid))
            );
        }
    }

    /**
     * Подтверждение или отмена применения кешбэка.
     *
     * @param  string  $action  'merge', 'replace' или 'cancel'
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function handleConfirm(string $action, int|string $chatId, User $user): void
    {
        $pid = $this->platformUserId($user);
        $state = $this->state($pid);

        if (($state['name'] ?? null) !== 'await_confirm') {
            $this->sendTransient($pid, $chatId, 'Сессия истекла. /menu');
            $this->setState($pid, 'idle');

            return;
        }

        $cardId = (int) ($state['card_id'] ?? 0);
        $items = $state['items'] ?? [];

        // Пустой список при сохранении — применять нечего, остаёмся в редакторе
        if (($action === 'merge' || $action === 'replace') && empty($items)) {
            $this->sendTransient($pid, $chatId, 'Список пуст. Добавь категорию (➕) или нажми «Отменить».');

            return;
        }

        // Обработка отмены
        if ($action === 'cancel') {
            if (! empty($state['image'])) {
                Storage::disk('public')->delete('card_cashback_image/'.$state['image']);
            }
            $this->clearEditorMessages($chatId, $state);
            $this->sendTransient($pid, $chatId, 'Отменено.');
            $this->setState($pid, 'idle');

            return;
        }

        // Обработка merge и replace
        if (in_array($action, ['merge', 'replace'], true) && $cardId > 0 && ! empty($items)) {
            // Пересобираем raw из текущих items
            $raw = array_map(fn ($it) => [
                'category' => $it['title'],
                'cashback' => $it['percent'],
                'mcc' => $it['mcc'] ?? '',
            ], $items);

            // Проверяем владение картой
            $card = Card::where('id', $cardId)->where('user_id', $user->id)->first();
            if (! $card) {
                $this->sendTransient($pid, $chatId, 'Карта не найдена.');
                $this->setState($pid, 'idle');

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

            // Cleanup: удаляем редактор и скрин (где разрешено), оставляя только результат
            $this->clearEditorMessages($chatId, $state);

            // Отправляем сообщение о результате
            $count = count($raw);
            $newPart = $created !== []
                ? ' ('.count($created).' нов.: '.implode(', ', $created).')'
                : '';
            $msg = $action === 'merge'
                ? ($count > 0 ? "Готово! Добавил/обновил {$count} категорий{$newPart}." : 'Готово! Список был пуст.')
                : ($count > 0 ? "Готово! Старые удалены, записано {$count} категорий{$newPart}." : 'Готово! Список был пуст.');
            $this->sendTransient($pid, $chatId, $msg);

            $this->setState($pid, 'idle');

            return;
        }

        // Если action неизвестный или нет данных
        $this->sendTransient($pid, $chatId, 'Ошибка применения. /menu');
        $this->setState($pid, 'idle');
    }

    // =========================================================================
    // Меню и клавиатуры
    // =========================================================================

    /**
     * Отправка меню с приветствием и кнопкой «Обновить кешбэк».
     *
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function sendMenu(int|string $chatId, User $user): void
    {
        $pid = $this->platformUserId($user);
        $count = Card::where('user_id', $user->id)->count();
        $keyboard = [[$this->makeButton('Обновить кешбэк', 'cmd:update')]];

        $this->sendTransient($pid, $chatId, 'Привет, '.e($user->name)."! Карт: {$count}.", $keyboard);
        $this->setStateName($pid, 'idle');
    }

    /**
     * Отправка inline-клавиатуры с картами пользователя.
     *
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function sendCardKeyboard(int|string $chatId, User $user): void
    {
        $pid = $this->platformUserId($user);
        $cards = Card::with('bank')->where('user_id', $user->id)->get();

        if ($cards->isEmpty()) {
            $this->sendTransient($pid, $chatId, 'У тебя нет карт. Добавь карту в ЛК.');
            $this->setStateName($pid, 'idle');

            return;
        }

        $keyboard = [];
        foreach ($cards as $card) {
            $bankTitle = $card->bank?->title ?? 'Без банка';
            $cardNumber = $card->number;
            $keyboard[][] = $this->makeButton("{$bankTitle} {$cardNumber}", 'card:'.$card->id);
        }

        $this->sendTransient($pid, $chatId, 'Выбери карту:', $keyboard);
        $this->setStateName($pid, 'await_card');
    }

    /**
     * Формирует текст редактора с распознанными категориями (✅/🆕).
     *
     * @param  array  $items  Элементы [['title'=>string,'percent'=>float,'category_id'=>?int,'mcc'=>string], ...]
     * @return string Текст сообщения
     */
    protected function buildEditorText(array $items): string
    {
        if (empty($items)) {
            return 'Список пуст. Нажми «➕ Добавить категорию».';
        }

        // Дубли: одинаковый category_id (не null) у нескольких пунктов — при сохранении
        // останется только последний (last-wins в apply). Подсвечиваем ⚠️, чтобы потеря не была тихой.
        $counts = array_count_values(array_filter(array_map(fn ($it) => $it['category_id'] ?? null, $items)));
        $dupIds = array_keys(array_filter($counts, fn ($n) => $n > 1));

        $lines = ['Проверь распознанное:'];
        foreach ($items as $i => $item) {
            $mark = empty($item['category_id']) ? '🆕' : '✅';
            // e() обязательно: title/mcc из AI/ручного ввода, HTML-парсинг — без экранирования
            // символы <, >, & ломают рендер (TG 400 → editMessageText игнорируется).
            $line = ($i + 1).'. '.$mark.' '.e($item['title']).' — '.$item['percent'].'%';
            // Примечание (MCC/условие) — в той же строке, через «·», чтобы пункт занимал одну строку
            if (! empty($item['mcc'])) {
                $line .= ' · 📝 '.e($item['mcc']);
            }
            if (in_array($item['category_id'] ?? null, $dupIds, true)) {
                $line .= ' ⚠️';
            }
            $lines[] = $line;
        }

        // Легенда внизу: ✅/🆕 — при наличии новой категории; ⚠️ — при наличии дублей category_id
        $hasNew = false;
        foreach ($items as $item) {
            if (empty($item['category_id'])) {
                $hasNew = true;
                break;
            }
        }
        if ($hasNew) {
            $lines[] = '✅ — ваша категория, 🆕 — новая, будет создана';
        }
        if (! empty($dupIds)) {
            $lines[] = '⚠️ — дублирующая категория, при сохранении останется последняя';
        }

        // Пустая строка между пунктами — визуальное разделение, чтобы список не сливался
        return implode("\n\n", $lines);
    }

    /**
     * Формирует inline-клавиатуру редактора в формате платформы.
     *
     * @param  array  $items  Элементы редактора
     * @return array Массив строк кнопок
     */
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
            $keyboard[] = [$this->makeButton("{$mark} ".e($title)." {$percent}%", 'cat:'.$i)];

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

    // =========================================================================
    // Парсинг пунктов и мэтчинг категорий
    // =========================================================================

    /**
     * Парсит текст пользователя для извлечения названия и процента.
     *
     * @param  string  $text  Текст от пользователя (напр. «Аптеки 5», «Кафе и рестораны 3.5» или «Аптеки 5 только 03»)
     * @return array|null Массив ['title'=>string,'percent'=>float,'mcc'=>string,'force_new'=>bool] (mcc='', force_new=false по умолчанию) или null
     */
    public static function parseItem(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Префикс «+» → принудительно новая категория: fuzzy-мэтчинг пропускается,
        // точное совпадение (если есть) переиспользуется. Пр.: «+Кафе 5» при наличии
        // «Кафе и рестораны» создаст отдельную «Кафе», не подменяя её.
        $forceNew = false;
        if (str_starts_with($text, '+')) {
            $forceNew = true;
            $text = ltrim(mb_substr($text, 1));
            if ($text === '') {
                return null;
            }
        }

        // Формат: «название — пробел — процент (число, опц. %) — пробел — примечание».
        // Процент = ПЕРВОЕ число в строке: тогда цифры в примечании (MCC-коды, «03», даты)
        // не путаются с процентом. Пр.: «Аптеки 5 только 03» → title=Аптеки, percent=5, mcc=«только 03».
        // Без модификатора 's': title (.+?) физически не может содержать перенос строки,
        // иначе он попал бы в текст inline-кнопки и сломал рендер.
        if (! preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)%?\s*(.*)$/u', $text, $m)) {
            return null;
        }

        $title = trim($m[1]);
        $percent = (float) str_replace(',', '.', $m[2]);
        $mcc = self::sanitizeNote($m[3]); // '' если после процента ничего нет

        if ($title === '' || $percent <= 0 || $percent > 100) {
            return null;
        }

        return ['title' => $title, 'percent' => $percent, 'mcc' => $mcc, 'force_new' => $forceNew];
    }

    /**
     * Очищает примечание (MCC/условие): trim, без переносов/табов (ломают рендеринг кнопки и
     * callback-data/payload), обрезка до 255 символов (длина колонки mcc в БД).
     *
     * @param  string  $note  Сырой текст примечания
     * @return string Очищенное примечание (может быть пустой строкой)
     */
    protected static function sanitizeNote(string $note): string
    {
        $note = trim(str_replace(["\r", "\n", "\t"], ' ', $note));

        return mb_substr($note, 0, 255);
    }

    /**
     * Сопоставляет введённое название с категориями пользователя (fuzzy через
     * CategoryMatcher) и проставляет category_id. При совпадении title заменяется
     * на каноничный — это чинит точный apply-путь (ensureCategories) и показ в
     * редакторе (✅ с честным названием). mcc/percent не затрагиваются.
     *
     * @param  array  $parsed  Распарсенный пункт ['title'=>…,'percent'=>…,'mcc'=>…,'force_new'=>bool]
     * @param  Collection<int, Category>  $categories  Категории пользователя (загружает вызывающая сторона).
     * @return array Тот же пункт с дополненным 'category_id' и каноничным 'title'
     */
    protected function attachCategory(array $parsed, Collection $categories): array
    {
        $matcher = new CategoryMatcher;

        // force_new (маркер «+») → только точное совпадение, без fuzzy-подмены названия.
        $matched = ! empty($parsed['force_new'])
            ? $matcher->matchExact($parsed['title'], $categories)
            : $matcher->match($parsed['title'], $categories);

        if ($matched !== null) {
            $parsed['category_id'] = $matched->id;
            $parsed['title'] = $matched->title;
        } else {
            $parsed['category_id'] = null;
        }

        return $parsed;
    }

    /**
     * Категории пользователя для мэтчинга — один SELECT.
     *
     * Вынесено отдельно, чтобы processTextList грузил коллекцию один раз (а не
     * на каждую строку списка) и передавал в attachCategory.
     *
     * @return Collection<int, Category>
     */
    protected function userCategories(int $userId): Collection
    {
        return Category::query()
            ->where('user_id', $userId)
            ->orderBy('title')
            ->get(['id', 'title', 'keywords']);
    }

    // =========================================================================
    // State-машина (Cache) и сообщения
    // =========================================================================

    /**
     * Обновляет часть состояния, сохраняя имя и TTL.
     *
     * @param  string  $pid  Платформенный id пользователя
     * @param  array  $patch  Патч для слияния с текущим состоянием
     */
    protected function patchState(string $pid, array $patch): void
    {
        $current = $this->state($pid);
        $merged = array_merge($current, $patch);
        $name = $merged['name'] ?? 'idle';
        unset($merged['name']);
        $this->setState($pid, $name, $merged);
    }

    /**
     * Меняет имя состояния, сохраняя остальные данные (включая last_bot_msg).
     *
     * В отличие от setState(), не затирает накопленные данные — это важно после
     * sendTransient(), который положил last_bot_msg: обычный setState() стёр бы его,
     * и следующий transient не смог бы удалить предыдущее сообщение бота.
     *
     * @param  string  $pid  Платформенный id пользователя
     * @param  string  $name  Новое имя состояния
     * @param  array  $extra  Доп. данные для слияния
     */
    protected function setStateName(string $pid, string $name, array $extra = []): void
    {
        $current = $this->state($pid);
        unset($current['name']);
        $this->setState($pid, $name, array_merge($current, $extra));
    }

    /**
     * Отправляет транзитное (одноразовое) сообщение, предварительно удалив
     * предыдущее транзитное, чтобы в чате не копился мусор. Редактор (msg_id) НЕ затрагивается.
     *
     * @param  string  $pid  Платформенный id пользователя
     * @param  int|string  $chatId  ID чата
     * @param  string  $text  Текст сообщения
     * @param  array  $keyboard  Inline-клавиатура
     * @return int|string|null Message ID отправленного сообщения
     */
    protected function sendTransient(string $pid, int|string $chatId, string $text, array $keyboard = []): int|string|null
    {
        $state = $this->state($pid);
        if (! empty($state['last_bot_msg'])) {
            $this->deleteMessage($chatId, $state['last_bot_msg']);
        }

        $msgId = $this->sendMessage($chatId, $text, $keyboard);
        $this->patchState($pid, ['last_bot_msg' => $msgId]);

        return $msgId;
    }

    /**
     * Удаляет сообщение пользователя (фото/ввод), если платформа это разрешает.
     *
     * Инкапсулирует общий cleanup-паттерн: проверка canDeleteUserMessages() +
     * извлечение id сообщения + удаление. Для MAX (canDelete=false) — no-op.
     *
     * @param  int|string  $chatId  ID чата
     * @param  array  $message  Объект сообщения пользователя
     */
    protected function tryDeleteUserMessage(int|string $chatId, array $message): void
    {
        if (! $this->canDeleteUserMessages()) {
            return;
        }
        $msgId = $this->extractMessageId($message);
        if ($msgId !== null) {
            $this->deleteMessage($chatId, $msgId);
        }
    }

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

    /**
     * Удаляет сообщение-редактор и скрин пользователя (cleanup при сохранении/отмене).
     *
     * Скрин (photo_msg_id) удаляется только когда бот может удалять чужие сообщения
     * и photo_msg_id был сохранён (фото-путь; текстовый путь его не кладёт).
     *
     * @param  int|string  $chatId  ID чата
     * @param  array  $state  Состояние с msg_id и (опц.) photo_msg_id
     */
    protected function clearEditorMessages(int|string $chatId, array $state): void
    {
        if (! empty($state['msg_id'])) {
            $this->deleteMessage($chatId, $state['msg_id']);
        }
        if ($this->canDeleteUserMessages() && ! empty($state['photo_msg_id'])) {
            $this->deleteMessage($chatId, $state['photo_msg_id']);
        }
    }

    /**
     * Получить состояние пользователя из кэша.
     *
     * @param  string  $pid  Платформенный id пользователя
     * @return array Массив с ключом 'name' и дополнительными данными
     */
    protected function state(string $pid): array
    {
        return Cache::get($this->stateKey($pid), ['name' => 'idle']);
    }

    /**
     * Установить состояние пользователя в кэше.
     *
     * @param  string  $pid  Платформенный id пользователя
     * @param  string  $name  Название состояния
     * @param  array  $extra  Дополнительные данные для сохранения
     */
    protected function setState(string $pid, string $name, array $extra = []): void
    {
        Cache::put($this->stateKey($pid), array_merge(['name' => $name], $extra), self::STATE_TTL);
    }

    /**
     * Ключ состояния в кэше.
     */
    private function stateKey(string $pid): string
    {
        return 'bot.state.'.$this->cacheNamespace().$pid;
    }

    /**
     * Ключ блокировки диалога.
     */
    private function lockKey(string $pid): string
    {
        return 'bot.lock.'.$this->cacheNamespace().$pid;
    }

    /**
     * Ключ rate-limit фото.
     */
    private function rateLimitKey(string $pid): string
    {
        return 'bot.rl.photo.'.$this->cacheNamespace().$pid;
    }

    // =========================================================================
    // Platform hooks — реализуются наследником (Telegram / MAX)
    // =========================================================================

    /**
     * Префикс namespace для cache-ключей: '' для Telegram, 'max.' для MAX.
     * Изоляция state/lock/rate-limit между платформами в общем Cache.
     */
    abstract protected function cacheNamespace(): string;

    /**
     * Колонка в users для поиска по платформенному id: 'telegram_id' / 'max_id'.
     */
    abstract protected function userIdColumn(): string;

    /**
     * Платформенный id пользователя из модели User (приведённый к строке).
     */
    abstract protected function platformUserId(User $user): string;

    /**
     * Путь (относительно app.url) для URL привязки аккаунта, с query-параметром.
     * Напр. '/profile/bot-link?tg=' или '/profile/max-link?max='.
     */
    abstract protected function linkPath(): string;

    /**
     * Может ли бот удалять сообщения пользователя в чате.
     * Telegram — да; MAX — нет (только свои).
     */
    abstract protected function canDeleteUserMessages(): bool;

    /**
     * Формирует inline-кнопку в формате платформы.
     *
     * @return array TG: ['text'=>..,'callback_data'=>..]; MAX: ['type'=>'callback','text'=>..,'payload'=>..]
     */
    abstract protected function makeButton(string $text, string $data): array;

    /**
     * Извлекает chat id из update.
     *
     * @param  array  $update  Полный webhook-update
     */
    abstract protected function extractChatId(array $update): int|string|null;

    /**
     * Извлекает платформенный id пользователя из update.
     *
     * @param  array  $update  Полный webhook-update
     */
    abstract protected function extractUserId(array $update): string;

    /**
     * Актуален ли update для обработки (отсев групповых чатов и пр.).
     * TG: только private-чаты; MAX: всегда true.
     *
     * @param  array  $update  Полный webhook-update
     * @param  int|string  $chatId  ID чата
     */
    abstract protected function isRelevantUpdate(array $update, int|string $chatId): bool;

    /**
     * Извлекает [callbackId, payload] из update для handleCallback/promptLink.
     *
     * @param  array  $update  Полный webhook-update
     * @return array{0:string,1:string} [callbackId, payload] (пустые строки если нет callback)
     */
    abstract protected function extractCallback(array $update): array;

    /**
     * Извлекает текст из объекта message.
     *
     * @param  array  $message  Объект сообщения
     */
    abstract protected function extractText(array $message): string;

    /**
     * Извлекает фото из объекта message.
     *
     * @param  array  $message  Объект сообщения
     * @return array|null ['source'=>string (file_id или URL), 'msg_id'=>int|string|null] или null, если фото нет
     */
    abstract protected function extractPhoto(array $message): ?array;

    /**
     * Извлекает id сообщения (для удаления пользовательского ввода).
     *
     * @param  array  $message  Объект сообщения
     */
    abstract protected function extractMessageId(array $message): int|string|null;

    /**
     * Содержит ли message document (файл вместо изображения).
     *
     * @param  array  $message  Объект сообщения
     */
    abstract protected function hasDocument(array $message): bool;

    /**
     * Скачивает фото по source (file_id для TG, URL для MAX) во временный файл.
     *
     * @param  string  $source  file_id (TG) или URL (MAX)
     * @return string|null Путь к локальному файлу или null при ошибке
     */
    abstract protected function downloadPhoto(string $source): ?string;

    /**
     * Отправляет сообщение. Делегирует платформенному транспорту.
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $text  Текст
     * @param  array  $keyboard  Inline-клавиатура
     * @return int|string|null Message ID отправленного сообщения или null при ошибке
     */
    abstract protected function sendMessage(int|string $chatId, string $text, array $keyboard = []): int|string|null;

    /**
     * Редактирует текст существующего сообщения (редактор категорий).
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $msgId  ID сообщения
     * @param  string  $text  Новый текст
     * @param  array  $keyboard  Inline-клавиатура
     */
    abstract protected function editMessageText(int|string $chatId, int|string $msgId, string $text, array $keyboard = []): void;

    /**
     * Удаляет сообщение.
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $msgId  ID сообщения
     */
    abstract protected function deleteMessage(int|string $chatId, int|string $msgId): void;

    /**
     * Отвечает на callback (снимает «часики» с кнопки).
     *
     * @param  string  $callbackId  ID callback
     */
    abstract protected function answerCallback(string $callbackId): void;

    /**
     * Отправляет chat action (напр. «печатает…»).
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $action  Тип действия ('typing' и пр.)
     */
    abstract protected function sendChatAction(int|string $chatId, string $action): void;
}
