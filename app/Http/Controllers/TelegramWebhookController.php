<?php

namespace App\Http\Controllers;

use App\Services\Bot\BotConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Контроллер для обработки Telegram webhook.
 *
 * Принимает update от Telegram Bot API через webhook.
 * Проверяет секретный токен и делегирует обработку в BotConversationService.
 */
class TelegramWebhookController extends Controller
{
    /**
     * Создать новый экземпляр контроллера.
     */
    public function __construct(
        private BotConversationService $conversation
    ) {}

    /**
     * Обработать входящий webhook запрос от Telegram.
     *
     * @param  Request  $request  Запрос с update от Telegram
     */
    public function __invoke(Request $request): \Illuminate\Http\Response
    {
        // Fail-closed проверка секрета
        $expected = (string) config('tg.webhook_secret');
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = $request->json()->all();

        // Идемпотентность по update_id: Telegram ретраит доставку, если ответ не пришёл
        // вовремя (AI может тормозить). Cache::add атомарно — второй проход пропустится.
        $updateId = $update['update_id'] ?? null;
        if ($updateId !== null && ! Cache::add("bot.update.{$updateId}", true, 600)) {
            return response('OK', 200);
        }

        // Делегируем обработку в conversation service
        $this->conversation->handle($update);

        return response('OK', 200);
    }
}
