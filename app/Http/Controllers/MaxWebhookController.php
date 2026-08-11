<?php

namespace App\Http\Controllers;

use App\Services\Bot\MaxConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Контроллер для обработки MAX webhook.
 *
 * Принимает update от MAX Bot API через webhook.
 * Проверяет секретный токен (header X-Max-Bot-API-Secret) и делегирует в MaxConversationService.
 */
class MaxWebhookController extends Controller
{
    /**
     * Создать новый экземпляр контроллера.
     */
    public function __construct(
        private MaxConversationService $conversation
    ) {}

    /**
     * Обработать входящий webhook запрос от MAX.
     *
     * @param  Request  $request  Запрос с update от MAX
     */
    public function __invoke(Request $request): \Illuminate\Http\Response
    {
        // Fail-closed проверка секрета MAX (header X-Max-Bot-API-Secret — подтверждено реальным вебхуком)
        $expected = (string) config('max.webhook_secret');
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Max-Bot-API-Secret'))) {
            abort(403);
        }

        $update = $request->json()->all();

        // Идемпотентность: MAX ретраит доставку при медленном ответе (GigaChat может тормозить).
        // Дедуп по callback_id (для нажатий кнопок) или message_id (для сообщений), TTL 600 c.
        $id = $update['callback_id'] ?? $update['message']['message_id'] ?? $update['timestamp'] ?? null;
        if ($id !== null && ! Cache::add("bot.update.max.{$id}", true, 600)) {
            return response('OK', 200);
        }

        $this->conversation->handle($update);

        return response('OK', 200);
    }
}
