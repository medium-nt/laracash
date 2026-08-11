<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Регистрация webhook в Telegram Bot API.
 *
 * Передаёт Telegram адрес webhook (APP_URL/api/telegram/webhook) и secret_token,
 * которым подписывается каждый запрос (его проверяет TelegramWebhookController).
 * Требует, чтобы сервер был доступен по HTTPS — Telegram не примет http/localhost.
 */
final class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:setwebhook';

    protected $description = 'Зарегистрировать webhook в Telegram (нужен публичный HTTPS).';

    /**
     * Выполнить команду.
     *
     * @return int Код завершения (0 - успех, 1 - ошибка конфигурации или запроса)
     */
    public function handle(): int
    {
        $token = (string) config('tg.token');
        $secret = (string) config('tg.webhook_secret');
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($token === '' || $secret === '' || $appUrl === '' || $appUrl === 'http://localhost') {
            $this->error('Не заданы TG_BOT_TOKEN / TG_WEBHOOK_SECRET / APP_URL в .env.');
            $this->comment('Заполни их, выполни «php artisan config:clear» и повтори команду.');

            return self::FAILURE;
        }

        // Telegram требует https и не принимает localhost/локальные адреса.
        $webhookUrl = preg_replace('#^http://#i', 'https://', $appUrl).'/api/telegram/webhook';

        $base = rtrim((string) config('tg.api_base'), '/').'/bot'.$token;

        $resp = Http::timeout(15)->post("{$base}/setWebhook", [
            'url' => $webhookUrl,
            'secret_token' => $secret,
        ]);

        if (! $resp->successful() || ! $resp->json('ok')) {
            $this->error('setWebhook завершился ошибкой: '.$resp->body());

            return self::FAILURE;
        }

        $this->info('Webhook установлен: '.$webhookUrl);
        $this->comment('Проверь статус доставки: GET https://api.telegram.org/bot<TOKEN>/getWebhookInfo (поле last_error_message).');

        return self::SUCCESS;
    }
}
