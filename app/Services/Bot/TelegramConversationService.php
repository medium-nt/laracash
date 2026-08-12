<?php

namespace App\Services\Bot;

use App\Models\User;
use App\Services\Telegram\TelegramBotService;

/**
 * Диалоговый движок Telegram-бота: платформенный адаптер поверх общей
 * стейт-машины AbstractBotConversationService.
 *
 * Реализует только Telegram-специфику: парсинг update (message/callback_query),
 * формат inline-кнопок (callback_data), делегацию транспорту TelegramBotService,
 * и возможность удалять сообщения пользователя (включая скрин).
 */
final class TelegramConversationService extends AbstractBotConversationService
{
    /**
     * Создать новый экземпляр сервиса.
     */
    public function __construct(
        private TelegramBotService $bot,
        CashbackImportService $import,
    ) {
        parent::__construct($import);
    }

    /**
     * Роутит update в обработчик сообщения или callback.
     *
     * @param  array  $update  Полный webhook-update
     * @param  int|string  $chatId  ID чата
     * @param  User  $user  Пользователь
     */
    protected function dispatch(array $update, int|string $chatId, User $user): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update, $chatId, $user);
        } else {
            $this->handleMessage($update['message'] ?? [], $chatId, $user);
        }
    }

    // -------------------------------------------------------------------------
    // Platform hooks
    // -------------------------------------------------------------------------

    /**
     * Префикс namespace для cache-ключей: Telegram использует общий корень.
     */
    protected function cacheNamespace(): string
    {
        return '';
    }

    /**
     * Колонка в users для поиска по Telegram id.
     */
    protected function userIdColumn(): string
    {
        return 'telegram_id';
    }

    /**
     * Telegram id пользователя из модели.
     */
    protected function platformUserId(User $user): string
    {
        return (string) $user->telegram_id;
    }

    /**
     * Путь для URL привязки Telegram-аккаунта.
     */
    protected function linkPath(): string
    {
        return '/profile/bot-link?tg=';
    }

    /**
     * Telegram-бот может удалять любые сообщения в чате (включая пользовательские).
     */
    protected function canDeleteUserMessages(): bool
    {
        return true;
    }

    /**
     * Формирует inline-кнопку в формате Telegram API.
     *
     * @param  string  $text  Текст кнопки
     * @param  string  $data  Callback-data
     * @return array ['text'=>..,'callback_data'=>..]
     */
    protected function makeButton(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }

    /**
     * Извлекает chat id из Telegram-update.
     *
     * @param  array  $update  Полный webhook-update
     */
    protected function extractChatId(array $update): int|string|null
    {
        return $update['message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id']
            ?? null;
    }

    /**
     * Извлекает Telegram id пользователя из update (автор сообщения или нажатия кнопки).
     *
     * @param  array  $update  Полный webhook-update
     */
    protected function extractUserId(array $update): string
    {
        return (string) ($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? '');
    }

    /**
     * Актуален ли update: бот — личный ассистент, игнорируем групповые чаты
     * (ответ в группу = спам и утечка telegram_id участников).
     *
     * @param  array  $update  Полный webhook-update
     * @param  int|string  $chatId  ID чата
     */
    protected function isRelevantUpdate(array $update, int|string $chatId): bool
    {
        $chatType = $update['message']['chat']['type']
            ?? $update['callback_query']['message']['chat']['type']
            ?? null;

        return $chatType === null || $chatType === 'private';
    }

    /**
     * Извлекает [callbackId, payload] из callback_query.
     *
     * @param  array  $update  Полный webhook-update
     * @return array{0:string,1:string} [callbackId, data]
     */
    protected function extractCallback(array $update): array
    {
        $cb = $update['callback_query'] ?? [];

        return [$cb['id'] ?? '', $cb['data'] ?? ''];
    }

    /**
     * Извлекает текст из message.
     *
     * @param  array  $message  Объект сообщения
     */
    protected function extractText(array $message): string
    {
        return (string) ($message['text'] ?? '');
    }

    /**
     * Извлекает самое крупное фото из message (file_id) + id сообщения со скрином.
     *
     * @param  array  $message  Объект сообщения
     * @return array|null ['source'=>file_id,'msg_id'=>int|null] или null
     */
    protected function extractPhoto(array $message): ?array
    {
        if (! isset($message['photo'])) {
            return null;
        }

        $photo = end($message['photo']);

        return [
            'source' => $photo['file_id'] ?? '',
            'msg_id' => $message['message_id'] ?? null,
        ];
    }

    /**
     * Извлекает id сообщения (для удаления пользовательского ввода).
     *
     * @param  array  $message  Объект сообщения
     */
    protected function extractMessageId(array $message): int|string|null
    {
        return $message['message_id'] ?? null;
    }

    /**
     * Содержит ли message document (файл вместо изображения).
     *
     * @param  array  $message  Объект сообщения
     */
    protected function hasDocument(array $message): bool
    {
        return isset($message['document']);
    }

    /**
     * Скачивает фото по file_id во временный файл.
     *
     * @param  string  $source  file_id фото
     * @return string|null Путь к локальному файлу или null при ошибке
     */
    protected function downloadPhoto(string $source): ?string
    {
        return $this->bot->downloadPhoto($source);
    }

    /**
     * Отправляет сообщение через Telegram API.
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $text  Текст
     * @param  array  $keyboard  Inline-клавиатура
     * @return int|string|null Message ID отправленного сообщения или null при ошибке
     */
    protected function sendMessage(int|string $chatId, string $text, array $keyboard = []): int|string|null
    {
        return $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * Редактирует текст сообщения (редактор категорий).
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $msgId  ID сообщения
     * @param  string  $text  Новый текст
     * @param  array  $keyboard  Inline-клавиатура
     */
    protected function editMessageText(int|string $chatId, int|string $msgId, string $text, array $keyboard = []): void
    {
        $this->bot->editMessageText($chatId, (int) $msgId, $text, $keyboard);
    }

    /**
     * Удаляет сообщение.
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $msgId  ID сообщения
     */
    protected function deleteMessage(int|string $chatId, int|string $msgId): void
    {
        $this->bot->deleteMessage($chatId, (int) $msgId);
    }

    /**
     * Отвечает на callback (снимает «часики» с кнопки).
     *
     * @param  string  $callbackId  ID callback query
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
