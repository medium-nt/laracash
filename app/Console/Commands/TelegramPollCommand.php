<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bot\BotConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Long-polling команда для получения обновлений от Telegram.
 *
 * Используется для локальной разработки, когда не требуется вебхук.
 * Команда подключается к Telegram Bot API и постоянно запрашивает обновления.
 */
final class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Long-polling Telegram getUpdates (локальная разработка).';

    /**
     * Execute the console command.
     *
     * @param  BotConversationService  $conv  Сервис обработки диалогов бота
     * @return int Код завершения (0 - успех)
     */
    public function handle(BotConversationService $conv): int
    {
        $base = rtrim((string) config('tg.api_base'), '/').'/bot'.config('tg.token');
        $offset = 0;

        $this->info('Polling... Ctrl+C to stop.');

        while (true) {
            try {
                $resp = Http::timeout(35)->get("{$base}/getUpdates", ['offset' => $offset, 'timeout' => 30]);

                foreach ($resp->json('result', []) as $update) {
                    $offset = $update['update_id'] + 1;
                    $conv->handle($update);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Telegram poll error: '.$e->getMessage());
                sleep(1);
            }
        }

        return Command::SUCCESS;
    }
}
