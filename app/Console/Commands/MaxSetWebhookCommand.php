<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Подписка на webhook MAX Bot API.
 *
 * В отличие от Telegram (setWebhook), MAX использует подписку: POST /subscriptions
 * с указанием URL, списка update_types и секрета (придёт в header X-Max-Bot-Secret).
 * Требует публичный HTTPS.
 */
final class MaxSetWebhookCommand extends Command
{
    protected $signature = 'max:setwebhook';

    protected $description = 'Подписаться на webhook MAX (нужен публичный HTTPS).';

    /**
     * Выполнить команду.
     *
     * @return int Код завершения (0 - успех, 1 - ошибка конфигурации или запроса)
     */
    public function handle(): int
    {
        $token = (string) config('max.token');
        $secret = (string) config('max.webhook_secret');
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($token === '' || $secret === '' || $appUrl === '' || $appUrl === 'http://localhost') {
            $this->error('Не заданы MAX_BOT_TOKEN / MAX_WEBHOOK_SECRET / APP_URL в .env.');
            $this->comment('Заполни их, выполни «php artisan config:clear» и повтори команду.');

            return self::FAILURE;
        }

        $webhookUrl = preg_replace('#^http://#i', 'https://', $appUrl).'/api/max/webhook';
        $base = rtrim((string) config('max.api_base'), '/');

        $resp = Http::timeout(15)->withHeaders(['Authorization' => $token])->post("{$base}/subscriptions", [
            'url' => $webhookUrl,
            'update_types' => ['message_created', 'message_callback', 'bot_started'],
            'secret' => $secret,
        ]);

        if (! $resp->successful()) {
            $this->error('subscriptions завершился ошибкой: '.$resp->body());

            return self::FAILURE;
        }

        $this->info('Webhook MAX установлен: '.$webhookUrl);
        $this->comment('Update types: message_created, message_callback, bot_started.');

        return self::SUCCESS;
    }
}
