<?php

namespace App\Services\Bot;

use App\Models\Card;
use App\Models\Category;
use App\Models\User;
use App\Services\Max\MaxBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Диалоговый движок MAX-бота (state machine по max_id в Cache).
 *
 * Порт BotConversationService под формат MAX API с адаптацией cleanup:
 *  - парсинг $update по update_type (message_created / message_callback / bot_started);
 *  - пользователь ищется по users.max_id;
 *  - state/lock/rate-limit в отдельном namespace bot.state.max.* (не конфликтует с TG);
 *  - ⚠️ В диалоге MAX бот может удалять ТОЛЬКО СВОИ сообщения → сообщения и фото
 *    пользователя НЕ удаляются; transient-сообщения бота и редактор (edit) — затрагиваются.
 *
 * Домен (CashbackImportService, AiService) переиспользуется без изменений.
 */
class MaxConversationService
{
    private const STATE_TTL = 1800;

    /**
     * Создать новый экземпляр сервиса.
     */
    public function __construct(
        private MaxBotService $bot,
        private CashbackImportService $import,
    ) {}

    /**
     * Единая точка входа для обработки update от MAX.
     *
     * @param  array  $update  Данные от MAX Webhook
     */
    public function handle(array $update): void
    {
        $type = $update['update_type'] ?? '';

        // РЕАЛЬНЫЙ формат MAX (снят с живого API):
        //  - message_created: chat_id в message.recipient.chat_id; автор в message.sender.user_id.
        //  - message_callback: chat_id в message.recipient.chat_id; автор нажатия в callback.user.user_id
        //    (message.sender тут = сам бот, поэтому callback.user проверяем ПЕРВЫМ);
        //    callback_id/payload — внутри callback.*.
        //  - bot_started: автор в user.user_id (топ-уровень).
        $msg = $update['message'] ?? [];
        $chatId = $update['chat_id']
            ?? $msg['recipient']['chat_id']
            ?? null;
        $maxId = (string) (
            $update['callback']['user']['user_id']    // message_callback — автор нажатия кнопки
            ?? $msg['sender']['user_id']              // message_created — автор сообщения
            ?? $update['user']['user_id']             // bot_started / топ-уровень
            ?? $update['from']['user_id']
            ?? ''
        );

        if ($chatId === null || $maxId === '') {
            return;
        }

        $user = User::where('max_id', $maxId)->first();
        if ($user === null) {
            $url = config('app.url').'/profile/max-link?max='.$maxId;
            $this->bot->sendMessage($chatId, 'Сначала привяжи аккаунт '.config('app.name').": {$url}");

            return;
        }

        // Блокировка по maxId: двойной тап / ретрай доставки не должен дать гонку.
        // TTL 60с: downloadPhoto (15с) + GigaChat recognize (до 15с) + sendMessage — должно влезть
        $lock = Cache::lock("bot.lock.max.{$maxId}", 60);
        if (! $lock->get()) {
            return;
        }

        try {
            if ($type === 'message_callback') {
                $this->handleCallback($update, $chatId, $user);
            } elseif ($type === 'bot_started') {
                $this->sendMenu($chatId, $user);
            } else {
                $this->handleMessage($update['message'] ?? [], $chatId, $user);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Обработка текстовых сообщений (message_created).
     *
     * @param  array  $message  Объект message из update
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function handleMessage(array $message, int|string $chatId, User $user): void
    {
        $text = trim((string) ($message['body']['text'] ?? ''));

        // ⚠️ MAX: сообщение пользователя НЕ удаляем — бот может удалять только свои сообщения.
        $state = $this->state($user->max_id);

        if ($text === '/start' || $text === '/menu') {
            $this->sendMenu($chatId, $user);

            return;
        }

        if ($text === 'Обновить кешбэк' || $state['name'] === 'await_card') {
            $this->sendCardKeyboard($chatId, $user);

            return;
        }

        $photoUrl = $this->extractPhotoUrl($message);

        if ($state['name'] === 'await_photo' && $photoUrl !== null) {
            $this->processPhotos($message, $photoUrl, $chatId, $user);

            return;
        }

        // Фото вне состояния ожидания — НЕ удаляем (MAX), только подсказываем порядок действий
        if ($photoUrl !== null) {
            $this->sendTransient((string) $user->max_id, $chatId, 'Сначала выбери карту (Обновить кешбэк), затем пришли скриншот.');

            return;
        }

        // Обработка состояний редактора (правка/добавление пункта)
        if ($state['name'] === 'await_edit' || $state['name'] === 'await_add') {
            $parsed = self::parseItem($text);

            if ($parsed === null) {
                $this->sendTransient((string) $user->max_id, $chatId, 'Не понял формат. Пришли «название процент», напр. `Аптеки 5`');

                return;
            }

            $parsed['category_id'] = $this->resolveCategoryId($user->id, $parsed['title']);

            $items = $state['items'] ?? [];
            if ($state['name'] === 'await_edit') {
                $index = $state['index'] ?? null;
                if ($index !== null && isset($items[$index])) {
                    $items[$index] = $parsed;
                }
            } else {
                $items[] = $parsed;
            }

            // Cleanup транзитного промпта правки (СВОЁ сообщение — можно удалить)
            if (! empty($state['last_bot_msg'])) {
                $this->bot->deleteMessage($chatId, $state['last_bot_msg']);
            }
            $this->setStateName((string) $user->max_id, 'await_confirm', ['items' => $items, 'last_bot_msg' => null]);

            $this->renderEditor($chatId, $state['msg_id'] ?? null, $items);

            return;
        }

        $this->sendTransient((string) $user->max_id, $chatId, 'Не понял. /menu — меню.');
    }

    /**
     * Извлекает URL изображения из attachments входящего сообщения.
     *
     * @param  array  $message  Объект message
     * @return string|null Прямой URL картинки или null
     */
    private function extractPhotoUrl(array $message): ?string
    {
        $attachments = $message['body']['attachments'] ?? [];

        foreach ($attachments as $att) {
            if (($att['type'] ?? '') === 'image') {
                return $att['payload']['url']
                    ?? $att['info']['url']
                    ?? $att['url']
                    ?? null;
            }
        }

        return null;
    }

    /**
     * Отправка меню с приветствием и кнопкой «Обновить кешбэк».
     *
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function sendMenu(int|string $chatId, User $user): void
    {
        $count = Card::where('user_id', $user->id)->count();
        $keyboard = [[
            ['type' => 'callback', 'text' => 'Обновить кешбэк', 'payload' => 'cmd:update'],
        ]];

        $this->sendTransient((string) $user->max_id, $chatId, 'Привет, '.e($user->name)."! Карт: {$count}.", $keyboard);
        $this->setStateName((string) $user->max_id, 'idle');
    }

    /**
     * Отправка inline-клавиатуры с картами пользователя.
     *
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function sendCardKeyboard(int|string $chatId, User $user): void
    {
        $cards = Card::where('user_id', $user->id)->get();

        if ($cards->isEmpty()) {
            $this->sendTransient((string) $user->max_id, $chatId, 'У тебя нет карт. Добавь карту в ЛК '.config('app.name').'.');
            $this->setStateName((string) $user->max_id, 'idle');

            return;
        }

        $keyboard = [];
        foreach ($cards as $card) {
            $bankTitle = $card->bank?->title ?? 'Без банка';
            $cardNumber = $card->number;
            $keyboard[][] = [
                'type' => 'callback',
                'text' => "{$bankTitle} {$cardNumber}",
                'payload' => 'card:'.$card->id,
            ];
        }

        $this->sendTransient((string) $user->max_id, $chatId, 'Выбери карту:', $keyboard);
        $this->setStateName((string) $user->max_id, 'await_card');
    }

    /**
     * Обработка callback (нажатие inline-кнопки).
     *
     * @param  array  $update  Полный update (с callback_id и payload)
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function handleCallback(array $update, int|string $chatId, User $user): void
    {
        // РЕАЛЬНЫЙ формат MAX: callback_id и payload лежат внутри update.callback.*
        $cb = $update['callback'] ?? [];
        if (! empty($cb['callback_id'])) {
            $this->bot->answerCallback($cb['callback_id']);
        }

        $data = $cb['payload'] ?? '';

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

            $this->sendTransient((string) $user->max_id, $chatId, 'Пришли скриншот категорий кешбэка.');
            $this->setStateName((string) $user->max_id, 'await_photo', ['card_id' => $cardId]);

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

        if (str_starts_with($data, 'edit:')) {
            $index = (int) substr($data, 5);
            $this->sendTransient((string) $user->max_id, $chatId, 'Пришли «название процент», напр. `Аптеки 5`');
            $this->setStateName((string) $user->max_id, 'await_edit', ['index' => $index]);

            return;
        }

        if (str_starts_with($data, 'del:')) {
            $index = (int) substr($data, 4);
            $state = $this->state($user->max_id);

            if (($state['name'] ?? null) === 'await_confirm' && isset($state['items'][$index])) {
                array_splice($state['items'], $index, 1);
                $this->setState((string) $user->max_id, 'await_confirm', $state);
                $this->renderEditor($chatId, $state['msg_id'] ?? null, $state['items']);
            }

            return;
        }

        if ($data === 'add') {
            $this->sendTransient((string) $user->max_id, $chatId, 'Пришли «название процент», напр. `Кафе и рестораны 3.5`');
            $this->setStateName((string) $user->max_id, 'await_add');

            return;
        }
    }

    /**
     * Обработка фото: скачивание, распознавание, построение редактора.
     *
     * @param  array  $message  Объект message с фото
     * @param  string  $photoUrl  URL изображения (CDN, без авторизации)
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    private function processPhotos(array $message, string $photoUrl, int|string $chatId, User $user): void
    {
        $state = $this->state($user->max_id);
        $cardId = $state['card_id'] ?? null;

        if ($cardId === null) {
            $this->sendMenu($chatId, $user);

            return;
        }

        // Rate-limit: каждое фото = платный запрос к GigaChat, ≤5/мин
        $rlKey = "bot.rl.photo.max.{$user->max_id}";
        $count = (int) Cache::get($rlKey, 0);
        if ($count >= 5) {
            // ⚠️ MAX: фото пользователя НЕ удаляем
            $this->sendTransient((string) $user->max_id, $chatId, 'Слишком много скринов за минуту. Подожди немного.');

            return;
        }
        Cache::put($rlKey, $count + 1, 60);

        $path = $this->bot->downloadPhoto($photoUrl);

        if ($path === null) {
            $this->sendTransient((string) $user->max_id, $chatId, 'Не удалось скачать фото.');

            return;
        }

        // Сохраняем скриншот в карту (public/card_cashback_image/ — тот же путь, что читает веб и AiService)
        $imageFilename = 'card_'.uniqid('', true).'.jpg';
        Storage::disk('public')->put('card_cashback_image/'.$imageFilename, file_get_contents($path));

        // Индикатор долгой операции (GigaChat отвечает не сразу)
        $this->bot->sendChatAction($chatId, 'typing');
        $this->sendTransient((string) $user->max_id, $chatId, '⏳ Распознаю скриншот…');

        try {
            $result = $this->import->import($user->id, $cardId, [$path]);
        } finally {
            @unlink($path);
        }

        // items: ✅ существующие категории + 🆕 новые (category_id=null)
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

        // Cleanup транзитного промпта «Пришли скриншот» (СВОЁ сообщение)
        $currentState = $this->state((string) $user->max_id);
        if (! empty($currentState['last_bot_msg'])) {
            $this->bot->deleteMessage($chatId, $currentState['last_bot_msg']);
        }

        $messageId = $this->renderEditor($chatId, null, $items);

        $this->setState((string) $user->max_id, 'await_confirm', [
            'card_id' => $cardId,
            'image' => $imageFilename,
            'items' => $items,
            'msg_id' => $messageId,
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
        $state = $this->state($user->max_id);

        if (($state['name'] ?? null) !== 'await_confirm') {
            $this->sendTransient((string) $user->max_id, $chatId, 'Сессия истекла. /menu');
            $this->setState((string) $user->max_id, 'idle');

            return;
        }

        $cardId = (int) ($state['card_id'] ?? 0);
        $items = $state['items'] ?? [];

        // Пустой список при сохранении — применять нечего, остаёмся в редакторе
        if (($action === 'merge' || $action === 'replace') && empty($items)) {
            $this->sendTransient((string) $user->max_id, $chatId, 'Список пуст. Добавь категорию (➕) или нажми «Отменить».');

            return;
        }

        // Отмена
        if ($action === 'cancel') {
            if (! empty($state['image'])) {
                Storage::disk('public')->delete('card_cashback_image/'.$state['image']);
            }
            $this->clearEditorMessages($chatId, $state);
            $this->sendTransient((string) $user->max_id, $chatId, 'Отменено.');
            $this->setState((string) $user->max_id, 'idle');

            return;
        }

        // merge / replace
        if (in_array($action, ['merge', 'replace'], true) && $cardId > 0 && ! empty($items)) {
            $raw = array_map(fn ($it) => [
                'category' => $it['title'],
                'cashback' => $it['percent'],
            ], $items);

            $card = Card::where('id', $cardId)->where('user_id', $user->id)->first();
            if (! $card) {
                $this->sendTransient((string) $user->max_id, $chatId, 'Карта не найдена.');
                $this->setState((string) $user->max_id, 'idle');

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

                return $this->import->apply($user->id, $cardId, $raw);
            });
            $created = $applyResult['created'] ?? [];

            // Cleanup: удаляем редактор (СВОЁ сообщение). Скрин юзера не трогаем (MAX).
            $this->clearEditorMessages($chatId, $state);

            $count = count($raw);
            $newPart = $created !== []
                ? ' ('.count($created).' нов.: '.implode(', ', $created).')'
                : '';
            $msg = $action === 'merge'
                ? ($count > 0 ? "Готово! Добавил/обновил {$count} категорий{$newPart}." : 'Готово! Список был пуст.')
                : ($count > 0 ? "Готово! Старые удалены, записано {$count} категорий{$newPart}." : 'Готово! Список был пуст.');
            $this->sendTransient((string) $user->max_id, $chatId, $msg);

            $this->setState((string) $user->max_id, 'idle');

            return;
        }

        $this->sendTransient((string) $user->max_id, $chatId, 'Ошибка применения. /menu');
        $this->setState((string) $user->max_id, 'idle');
    }

    /**
     * Формирует текст редактора с распознанными категориями (✅/🆕).
     *
     * @param  array  $items  Элементы [['title'=>string,'percent'=>float,'category_id'=>?int], ...]
     * @return string HTML-текст
     */
    private function buildEditorText(array $items): string
    {
        if (empty($items)) {
            return "Проверь распознанное (✅ — ваша категория, 🆕 — новая, будет создана):\n\nСписок пуст. Нажми «➕ Добавить категорию».";
        }

        $lines = ['Проверь распознанное (✅ — ваша, 🆕 — новая):'];
        foreach ($items as $i => $item) {
            $mark = empty($item['category_id']) ? '🆕' : '✅';
            // e() обязательно: title из AI/ручного ввода, format=html — без экранирования рендер сломается.
            $lines[] = ($i + 1).'. '.$mark.' '.e($item['title']).' — '.$item['percent'].'%';
        }

        return implode("\n", $lines);
    }

    /**
     * Формирует inline-клавиатуру редактора в формате MAX (type=callback).
     *
     * @param  array  $items  Элементы
     * @return array Массив строк кнопок MAX
     */
    private function buildEditorKeyboard(array $items): array
    {
        $keyboard = [];

        // «Добавить категорию» — первой строкой
        $keyboard[] = [['type' => 'callback', 'text' => '➕ Добавить категорию', 'payload' => 'add']];

        foreach ($items as $i => $item) {
            $title = $item['title'] ?? '';
            $percent = $item['percent'] ?? 0;

            if (mb_strlen($title) > 30) {
                $title = mb_substr($title, 0, 30).'…';
            }

            $mark = empty($item['category_id']) ? '🆕' : '✅';
            $editText = "{$mark} {$title} {$percent}%";

            $keyboard[] = [
                ['type' => 'callback', 'text' => $editText, 'payload' => 'edit:'.$i],
                ['type' => 'callback', 'text' => '🗑', 'payload' => 'del:'.$i],
            ];
        }

        $keyboard[] = [['type' => 'callback', 'text' => '💾 Сохранить (добавить к старым)', 'payload' => 'merge']];
        $keyboard[] = [['type' => 'callback', 'text' => '♻️ Заменить (удалить старые)', 'payload' => 'replace']];
        $keyboard[] = [['type' => 'callback', 'text' => 'Отменить', 'payload' => 'cancel']];

        return $keyboard;
    }

    /**
     * Парсит «Название процент» → ['title', 'percent'] или null.
     *
     * @param  string  $text  Ввод пользователя
     * @return array|null ['title'=>string,'percent'=>float] или null
     */
    public static function parseItem(string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }

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
     * @param  string  $maxId  MAX ID пользователя
     * @param  array  $patch  Патч для слияния
     */
    private function patchState(string $maxId, array $patch): void
    {
        $current = $this->state($maxId);
        $merged = array_merge($current, $patch);
        $name = $merged['name'] ?? 'idle';
        unset($merged['name']);
        $this->setState($maxId, $name, $merged);
    }

    /**
     * Меняет имя состояния, сохраняя остальные данные (включая last_bot_msg).
     *
     * @param  string  $maxId  MAX ID пользователя
     * @param  string  $name  Новое имя состояния
     * @param  array  $extra  Доп. данные
     */
    private function setStateName(string $maxId, string $name, array $extra = []): void
    {
        $current = $this->state($maxId);
        unset($current['name']);
        $this->setState($maxId, $name, array_merge($current, $extra));
    }

    /**
     * Отправляет транзитное (одноразовое) сообщение, удалив предыдущее транзитное.
     * Редактор (msg_id) НЕ затрагивается.
     *
     * @param  string  $maxId  MAX ID пользователя
     * @param  int|string  $chatId  ID чата
     * @param  string  $text  Текст
     * @param  array  $keyboard  Inline-клавиатура MAX
     * @return int|string|null Message ID отправленного сообщения
     */
    private function sendTransient(string $maxId, int|string $chatId, string $text, array $keyboard = []): int|string|null
    {
        $state = $this->state($maxId);
        if (! empty($state['last_bot_msg'])) {
            $this->bot->deleteMessage($chatId, $state['last_bot_msg']);
        }

        $msgId = $this->bot->sendMessage($chatId, $text, $keyboard);
        $this->patchState($maxId, ['last_bot_msg' => $msgId]);

        return $msgId;
    }

    /**
     * Рендерит редактор: edit существующего сообщения или отправка нового.
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string|null  $msgId  ID сообщения редактора (null — новое)
     * @param  array  $items  Элементы редактора
     * @return int|string|null Message ID нового сообщения или null при edit
     */
    private function renderEditor(int|string $chatId, int|string|null $msgId, array $items): int|string|null
    {
        $text = $this->buildEditorText($items);
        $keyboard = $this->buildEditorKeyboard($items);

        if ($msgId !== null) {
            $this->bot->editMessageText($chatId, $msgId, $text, $keyboard);

            return null;
        }

        return $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * Удаляет сообщение-редактор (cleanup при сохранении/отмене).
     *
     * ⚠️ Скрин пользователя (photo_msg_id) НЕ удаляется — MAX не позволяет боту
     * удалять чужие сообщения в диалоге.
     *
     * @param  int|string  $chatId  ID чата
     * @param  array  $state  Состояние с msg_id
     */
    private function clearEditorMessages(int|string $chatId, array $state): void
    {
        if (! empty($state['msg_id'])) {
            $this->bot->deleteMessage($chatId, $state['msg_id']);
        }
    }

    /**
     * Находит id существующей категории по точному нормализованному совпадению.
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
     * @param  string  $maxId  MAX ID пользователя
     * @return array Состояние с ключом 'name'
     */
    private function state(string $maxId): array
    {
        return Cache::get("bot.state.max.{$maxId}", ['name' => 'idle']);
    }

    /**
     * Установить состояние пользователя в кэше.
     *
     * @param  string  $maxId  MAX ID пользователя
     * @param  string  $name  Название состояния
     * @param  array  $extra  Дополнительные данные
     */
    private function setState(string $maxId, string $name, array $extra = []): void
    {
        Cache::put("bot.state.max.{$maxId}", array_merge(['name' => $name], $extra), self::STATE_TTL);
    }
}
