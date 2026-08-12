<?php

namespace App\Services\Max;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Транспорт к MAX Bot API (raw HTTP).
 *
 * Зеркало публичных сигнатур TelegramBotService, чтобы MaxConversationService
 * повторял логику TelegramConversationService 1-в-1 (отличия — только формат update/cleanup).
 *
 * Ключевые отличия MAX от TG:
 *  - auth: header Authorization: <token> (БЕЗ "Bearer");
 *  - получатель сообщения передаётся query-параметром chat_id (не в теле);
 *  - кнопки — attachments[].type=inline_keyboard;
 *  - ответ POST /messages — идентификатор сообщения в message.body.mid (строка "mid.000...");
 *  - в диалоге бот может удалять ТОЛЬКО свои сообщения (не юзера).
 */
class MaxBotService
{
    /**
     * Базовый URL MAX Bot API.
     */
    private function base(): string
    {
        return rtrim((string) config('max.api_base'), '/');
    }

    /**
     * Опции SSL-проверки.
     *
     * На проде (Beget) системный CA-бандл не содержит Russian Trusted Root CA, которым
     * подписан platform-api2.max.ru → cURL error 60. Если задан MAX_CACERT (путь к PEM =
     * Mozilla bundle + Russian root), верифицируем по нему. Локально конфиг пуст → опция
     * не передаётся (используется системный cacert из php.ini).
     *
     * Static + public: переиспользуется в консольных командах (max:setwebhook/max:poll),
     * которые шлют Http напрямую, минуя сервис.
     *
     * @return array Опции для Http::withOptions() (['verify' => путь] или [])
     */
    public static function caOptions(): array
    {
        $cacert = config('max.cacert');

        return is_string($cacert) && $cacert !== '' ? ['verify' => $cacert] : [];
    }

    /**
     * HTTP-клиент с авторизацией MAX (timeout 15 c, header Authorization, опциональная SSL-проверка по MAX_CACERT).
     */
    private function http(): PendingRequest
    {
        return Http::timeout(15)
            ->withHeaders(['Authorization' => (string) config('max.token')])
            ->withOptions(self::caOptions());
    }

    /**
     * Собирает тело запроса сообщения MAX (текст в HTML + опциональная inline-клавиатура).
     *
     * @param  string  $text  Текст сообщения (HTML)
     * @param  array  $keyboard  Массив строк кнопок в формате MAX (пусто — без клавиатуры)
     * @return array Тело для POST/PUT /messages
     */
    private function buildPayload(string $text, array $keyboard): array
    {
        $payload = [
            'text' => $text,
            'format' => 'html',
        ];

        if ($keyboard) {
            $payload['attachments'] = [
                ['type' => 'inline_keyboard', 'payload' => ['buttons' => $keyboard]],
            ];
        }

        return $payload;
    }

    /**
     * Отправляет текстовое сообщение (опционально с inline-клавиатурой).
     *
     * @param  int|string  $chatId  ID чата (передаётся в query)
     * @param  string  $text  Текст сообщения (HTML)
     * @param  array  $keyboard  Массив строк кнопок в формате MAX: [[['type'=>'callback','text'=>..,'payload'=>..]], ...]
     * @return int|string|null Message ID отправленного сообщения или null при ошибке
     */
    public function sendMessage(int|string $chatId, string $text, array $keyboard = []): int|string|null
    {
        $payload = $this->buildPayload($text, $keyboard);

        $query = http_build_query(['chat_id' => $chatId]);
        $response = $this->http()->post("{$this->base()}/messages?{$query}", $payload);

        if (! $response->successful()) {
            // [ДИАГНОСТИКА] transport молчал при ошибках — теперь логируем причину не-200
            Log::warning('MAX sendMessage !ok', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 300),
            ]);

            return null;
        }

        // MAX: идентификатор сообщения — message.body.mid (строка вида "mid.000..."), НЕ message.message_id.
        return $response->json('message.body.mid');
    }

    /**
     * Редактирует текст сообщения (опционально с inline-клавиатурой).
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $messageId  mid сообщения бота (строка в MAX)
     * @param  string  $text  Новый текст (HTML)
     * @param  array  $keyboard  Массив строк кнопок MAX
     */
    public function editMessageText(int|string $chatId, int|string $messageId, string $text, array $keyboard = []): void
    {
        $payload = $this->buildPayload($text, $keyboard);

        $query = http_build_query(['chat_id' => $chatId, 'message_id' => $messageId]);
        $this->http()->put("{$this->base()}/messages?{$query}", $payload);
    }

    /**
     * Удаляет сообщение в чате.
     *
     * ⚠️ В диалоге MAX бот может удалять ТОЛЬКО СВОИ сообщения (не пользователя).
     * Используется для cleanup transient-сообщений бота. Ошибки игнорируются (fire-and-forget).
     *
     * @param  int|string  $chatId  ID чата
     * @param  int|string  $messageId  mid сообщения бота (строка в MAX)
     */
    public function deleteMessage(int|string $chatId, int|string $messageId): void
    {
        $query = http_build_query(['chat_id' => $chatId, 'message_id' => $messageId]);
        $this->http()->delete("{$this->base()}/messages?{$query}");
    }

    /**
     * Показывает статус «печатает…» в шапке чата (индикатор долгой операции).
     *
     * @param  int|string  $chatId  ID чата
     * @param  string  $action  Тип статуса (по умолчанию 'typing')
     */
    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        $this->http()->post("{$this->base()}/chats/{$chatId}/actions", [
            'type' => $action,
        ]);
    }

    /**
     * Устанавливает список команд бота (нативное меню MAX).
     *
     * @param  array  $commands  Массив [['command'=>string, 'description'=>string], ...]
     * @return bool Успешен ли запрос.
     */
    public function setMyCommands(array $commands): bool
    {
        return $this->http()->patch("{$this->base()}/me/commands", [
            'commands' => $commands,
        ])->successful();
    }

    /**
     * Подтверждает callback (снимает «часики» на кнопке).
     *
     * @param  string  $callbackId  ID callback
     * @param  string|null  $text  Опциональный текст уведомления
     */
    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->http()->post("{$this->base()}/answers", array_filter([
            'callback_id' => $callbackId,
            'notification' => $text,
        ], fn ($v) => $v !== null));
    }

    /**
     * Скачивает фото по URL → абсолютный путь во storage/app/temp/max/.
     *
     * MAX не отдаёт download-endpoint по токену: входящий image-attachment содержит
     * прямой URL (CDN, без авторизации). Скачиваем и сохраняем локально для распознавания.
     *
     * ⚠️ MAX присылает фото в формате WebP (несмотря на расширение .jpg), а GigaChat
     * принимает только JPEG/PNG. Конвертируем не-JPEG в JPEG через GD
     * (imagecreatefromstring автоопределяет формат, imagejpeg нормализует).
     *
     * @param  string  $url  Прямой URL медиафайла из входящего attachment
     * @return string|null Абсолютный путь к скачанному (и при необходимости конвертируемому) файлу или null при ошибке
     */
    public function downloadPhoto(string $url): ?string
    {
        $response = Http::timeout(15)->withOptions($this->caOptions())->get($url);

        if (! $response->successful()) {
            return null;
        }

        $contents = $response->body();
        $local = 'temp/max/'.uniqid('ph_', true).'.jpg';
        Storage::disk('local')->put($local, $contents);
        $path = Storage::disk('local')->path($local);

        // Не JPEG (WebP/PNG/...) → конвертируем в JPEG, иначе GigaChat отвергнет файл.
        if (! str_starts_with($contents, "\xFF\xD8\xFF")) {
            $image = @imagecreatefromstring($contents);
            // Битый WebP / не-картинка (HTML-ошибка CDN) → не скармливать GigaChat и не хранить битый файл.
            if ($image === false) {
                Log::warning('MAX downloadPhoto: imagecreatefromstring failed (битый WebP/не картинка)');
                Storage::disk('local')->delete($local);

                return null;
            }
            imagejpeg($image, $path, 90);
            imagedestroy($image);
        }

        return $path;
    }
}
