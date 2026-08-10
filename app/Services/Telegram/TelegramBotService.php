<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TelegramBotService
{
    /**
     * Базовый URL для Telegram Bot API.
     */
    private function base(): string
    {
        $token = config('tg.token');
        $api = rtrim((string) config('tg.api_base'), '/');

        return "{$api}/bot{$token}";
    }

    /**
     * Отправляет текстовое сообщение (опционально с inline-клавиатурой).
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $text  Текст сообщения
     * @param  array  $keyboard  Массив с inline-клавиатурой
     * @return int|string|null Message ID отправленного сообщения или null при ошибке
     */
    public function sendMessage(int|string $chatId, string $text, array $keyboard = []): int|string|null
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }

        $response = Http::timeout(15)->post("{$this->base()}/sendMessage", $payload);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('result.message_id');
    }

    /**
     * Редактирует текст сообщения (опционально с inline-клавиатурой).
     *
     * @param  int|string  $chatId  ID чата
     * @param  int  $messageId  ID сообщения
     * @param  string  $text  Текст сообщения
     * @param  array  $keyboard  Массив с inline-клавиатурой
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $keyboard = []): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }

        Http::timeout(15)->post("{$this->base()}/editMessageText", $payload);
    }

    /**
     * Удаляет сообщение в чате.
     *
     * Используется для очистки чата (cleanup): удаление транзитных сообщений бота
     * и сообщений пользователя, чтобы актуальное меню оставалось последним.
     * Ошибки (сообщение уже удалено/старое) игнорируются — запрос «выстрелил и забыл».
     *
     * @param  int|string  $chatId  ID чата
     * @param  int  $messageId  ID сообщения
     */
    public function deleteMessage(int|string $chatId, int $messageId): void
    {
        Http::timeout(15)->post("{$this->base()}/deleteMessage", [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Показывает статус «печатает…» / «отправляет фото…» в шапке чата.
     *
     * Действует ~5 секунд, используется как индикатор долгой операции (например,
     * распознавание скриншота). Для операций дольше 5 секунд нужно повторить.
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $action  Тип статуса: 'typing', 'upload_photo', 'find_location' и др.
     */
    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        Http::timeout(15)->post("{$this->base()}/sendChatAction", [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    /**
     * Устанавливает список команд бота (нативное меню Telegram — кнопка «Меню»).
     *
     * @param  array  $commands  Массив [['command'=>string, 'description'=>string], ...]
     * @return bool Успешен ли запрос.
     */
    public function setMyCommands(array $commands): bool
    {
        return Http::timeout(15)->post("{$this->base()}/setMyCommands", [
            'commands' => $commands,
        ])->successful();
    }

    /**
     * Подтверждает callback-запрос (снимает «часики» на кнопке).
     *
     * @param  string  $callbackId  ID callback query
     * @param  string|null  $text  Опциональный текст уведомления
     */
    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        Http::timeout(15)->post("{$this->base()}/answerCallbackQuery", array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
        ], fn ($v) => $v !== null));
    }

    /**
     * Скачивает фото по file_id → абсолютный путь во storage/app/temp/tg/.
     *
     * @param  string  $fileId  ID файла в Telegram
     * @return string|null Абсолютный путь к скачанному файлу или null при ошибке
     */
    public function downloadPhoto(string $fileId): ?string
    {
        $response = Http::timeout(15)->get("{$this->base()}/getFile", ['file_id' => $fileId]);

        if (! $response->successful()) {
            return null;
        }

        $meta = $response->json();
        $filePath = $meta['result']['file_path'] ?? null;

        if (! $filePath) {
            return null;
        }

        $token = config('tg.token');
        $fileResponse = Http::timeout(15)->get(rtrim((string) config('tg.api_base'), '/')."/file/bot{$token}/{$filePath}");

        if (! $fileResponse->successful()) {
            return null;
        }

        $contents = $fileResponse->body();

        $local = 'temp/tg/'.uniqid('ph_', true).'.png';
        Storage::disk('local')->put($local, $contents);

        return Storage::disk('local')->path($local);
    }
}
