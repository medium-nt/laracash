<?php

namespace App\Services\Bot;

use App\Models\User;
use App\Services\Max\MaxBotService;

/**
 * Диалоговый движок MAX-бота: платформенный адаптер поверх общей стейт-машины
 * AbstractBotConversationService.
 *
 * Реализует только MAX-специфику: парсинг update по update_type
 * (message_created / message_callback / bot_started), формат inline-кнопок (payload),
 * делегацию транспорту MaxBotService.
 *
 * ⚠️ В диалоге MAX бот может удалять ТОЛЬКО СВОИ сообщения → canDeleteUserMessages()=false:
 * сообщения и фото пользователя НЕ удаляются; transient-сообщения бота и редактор
 * (edit) — затрагиваются. Поле photo_msg_id не сохраняется.
 */
final class MaxConversationService extends AbstractBotConversationService
{
    /**
     * Создать новый экземпляр сервиса.
     */
    public function __construct(
        private MaxBotService $bot,
        CashbackImportService $import,
    ) {
        parent::__construct($import);
    }

    /**
     * Роутит update по update_type: callback / bot_started / message.
     *
     * РЕАЛЬНЫЙ формат MAX (снят с живого API):
     *  - message_callback: автор нажатия в callback.user.user_id; callback_id/payload в callback.*.
     *  - bot_started: автор в user.user_id (топ-уровень).
     *  - message_created: автор в message.sender.user_id.
     *
     * @param  array  $update  Полный webhook-update
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function dispatch(array $update, int|string $chatId, User $user): void
    {
        $type = $update['update_type'] ?? '';

        if ($type === 'message_callback') {
            $this->handleCallback($update, $chatId, $user);
        } elseif ($type === 'bot_started') {
            $this->sendMenu($chatId, $user);
        } else {
            $this->handleMessage($update['message'] ?? [], $chatId, $user);
        }
    }

    // -------------------------------------------------------------------------
    // Platform hooks
    // -------------------------------------------------------------------------

    /**
     * Префикс namespace для cache-ключей: MAX использует 'max.', чтобы не конфликтовать
     * с state/lock/rate-limit Telegram в общем Cache.
     */
    protected function cacheNamespace(): string
    {
        return 'max.';
    }

    /**
     * Колонка в users для поиска по MAX id.
     */
    protected function userIdColumn(): string
    {
        return 'max_id';
    }

    /**
     * MAX id пользователя из модели.
     */
    protected function platformUserId(User $user): string
    {
        return (string) $user->max_id;
    }

    /**
     * Путь для URL привязки MAX-аккаунта.
     */
    protected function linkPath(): string
    {
        return '/profile/max-link?max=';
    }

    /**
     * MAX-бот НЕ может удалять чужие сообщения в диалоге — только свои.
     */
    protected function canDeleteUserMessages(): bool
    {
        return false;
    }

    /**
     * Формирует inline-кнопку в формате MAX API.
     *
     * @param  string  $text  Текст кнопки
     * @param  string  $data  Payload
     * @return array ['type'=>'callback','text'=>..,'payload'=>..]
     */
    protected function makeButton(string $text, string $data): array
    {
        return ['type' => 'callback', 'text' => $text, 'payload' => $data];
    }

    /**
     * Формирует inline URL-кнопку в формате MAX API (открывает ссылку).
     *
     * @param  string  $text  Текст кнопки
     * @param  string  $url  URL, который откроется при нажатии
     * @return array ['type'=>'link','text'=>..,'url'=>..]
     */
    protected function makeUrlButton(string $text, string $url): array
    {
        return ['type' => 'link', 'text' => $text, 'url' => $url];
    }

    /**
     * Извлекает chat id из MAX-update.
     *
     * @param  array  $update  Полный webhook-update
     */
    protected function extractChatId(array $update): int|string|null
    {
        $msg = $update['message'] ?? [];

        return $update['chat_id']
            ?? $msg['recipient']['chat_id']
            ?? null;
    }

    /**
     * Извлекает MAX id пользователя из update: автор нажатия кнопки (callback.user)
     * проверяется первым, т.к. при message_callback message.sender — сам бот.
     *
     * @param  array  $update  Полный webhook-update
     */
    protected function extractUserId(array $update): string
    {
        $msg = $update['message'] ?? [];

        return (string) (
            $update['callback']['user']['user_id']    // message_callback — автор нажатия кнопки
            ?? $msg['sender']['user_id']              // message_created — автор сообщения
            ?? $update['user']['user_id']             // bot_started / топ-уровень
            ?? $update['from']['user_id']
            ?? ''
        );
    }

    /**
     * Актуален ли update: MAX не требует отсева (личные диалоги).
     *
     * @param  array  $update  Полный webhook-update
     * @param  int|string  $chatId  ID чата
     */
    protected function isRelevantUpdate(array $update, int|string $chatId): bool
    {
        return true;
    }

    /**
     * Извлекает [callbackId, payload] из callback (внутри update.callback.*).
     *
     * @param  array  $update  Полный webhook-update
     * @return array{0:string,1:string} [callbackId, payload]
     */
    protected function extractCallback(array $update): array
    {
        $cb = $update['callback'] ?? [];

        return [$cb['callback_id'] ?? '', $cb['payload'] ?? ''];
    }

    /**
     * Извлекает текст из message (message.body.text).
     *
     * @param  array  $message  Объект сообщения
     */
    protected function extractText(array $message): string
    {
        return (string) ($message['body']['text'] ?? '');
    }

    /**
     * Извлекает URL изображения из attachments. msg_id всегда null: MAX-бот не может
     * удалить скрин пользователя, поэтому photo_msg_id не сохраняется.
     *
     * @param  array  $message  Объект сообщения
     * @return array|null ['source'=>URL,'msg_id'=>null] или null
     */
    protected function extractPhoto(array $message): ?array
    {
        $url = $this->extractPhotoUrl($message);
        if ($url === null) {
            return null;
        }

        return ['source' => $url, 'msg_id' => null];
    }

    /**
     * Извлекает прямой URL картинки из attachments входящего сообщения (CDN, без авторизации).
     *
     * @param  array  $message  Объект сообщения
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
     * Извлекает id сообщения. Для MAX не используется (бот не удаляет чужие сообщения).
     *
     * @param  array  $message  Объект сообщения
     */
    protected function extractMessageId(array $message): int|string|null
    {
        return null;
    }

    /**
     * Содержит ли message document. MAX присылает скрины только как image-attachment.
     *
     * @param  array  $message  Объект сообщения
     */
    protected function hasDocument(array $message): bool
    {
        return false;
    }

    /**
     * Скачивает фото по прямому URL во временный файл (WebP→JPEG — в транспорте).
     *
     * @param  string  $source  URL фото (CDN)
     * @return string|null Путь к локальному файлу или null при ошибке
     */
    protected function downloadPhoto(string $source): ?string
    {
        return $this->bot->downloadPhoto($source);
    }

    /**
     * Отправляет сообщение через MAX API.
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $text  Текст
     * @param  array  $keyboard  Inline-клавиатура
     * @return int|string|null Message ID отправленного сообщения (формат MAX «mid.000…») или null при ошибке
     */
    protected function sendMessage(int|string $chatId, string $text, array $keyboard = []): int|string|null
    {
        return $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * Редактирует текст сообщения (редактор категорий).
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $msgId  ID сообщения (строка формата «mid.…»)
     * @param  string  $text  Новый текст
     * @param  array  $keyboard  Inline-клавиатура
     */
    protected function editMessageText(int|string $chatId, int|string $msgId, string $text, array $keyboard = []): void
    {
        $this->bot->editMessageText($chatId, $msgId, $text, $keyboard);
    }

    /**
     * Удаляет сообщение бота.
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $msgId  ID сообщения бота
     */
    protected function deleteMessage(int|string $chatId, int|string $msgId): void
    {
        $this->bot->deleteMessage($chatId, $msgId);
    }

    /**
     * Отвечает на callback (снимает «часики» с кнопки).
     *
     * @param  string  $callbackId  ID callback
     */
    protected function answerCallback(string $callbackId): void
    {
        $this->bot->answerCallback($callbackId);
    }

    /**
     * Отправляет chat action («печатает…»).
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $action  Тип действия
     */
    protected function sendChatAction(int|string $chatId, string $action): void
    {
        $this->bot->sendChatAction($chatId, $action);
    }
}
