<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bot\MaxConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Long-polling команда для получения обновлений от MAX.
 *
 * Используется для локальной разработки, когда не требуется вебхук.
 * Команда подключается к MAX Bot API (GET /updates) и постоянно запрашивает обновления,
 * двигая курсор marker (int64) по значению из ответа. ⚠️ Webhook и long-polling
 * одновременно не работают — выберите один способ приёма.
 */
final class MaxPollCommand extends Command
{
    protected $signature = 'max:poll';

    protected $description = 'Long-polling MAX /updates (локальная разработка).';

    /**
     * Execute the console command.
     *
     * @param  MaxConversationService  $conv  Сервис обработки диалогов MAX-бота
     * @return int Код завершения (0 - успех)
     */
    public function handle(MaxConversationService $conv): int
    {
        $base = rtrim((string) config('max.api_base'), '/');
        $token = (string) config('max.token');
        // MAX /updates использует курсор marker (int64), НЕ offset/timestamp.
        // null — получить последнее обновление и стартовый marker; далее движемся по marker из ответа.
        $marker = null;

        $this->info('Polling MAX... Ctrl+C to stop.');

        while (true) {
            try {
                $resp = Http::timeout(35)
                    ->withHeaders(['Authorization' => $token])
                    ->withOptions(\App\Services\Max\MaxBotService::caOptions())
                    ->get("{$base}/updates", ['marker' => $marker, 'timeout' => 30]);

                $data = $resp->json() ?? [];

                // Каждый update в своём try/catch: один упавший не должен блокировать movement marker
                // (иначе MAX вернёт ту же пачку → бесконечный переопрос и заливка логов).
                foreach ($data['updates'] ?? [] as $update) {
                    try {
                        $conv->handle($update);
                    } catch (\Throwable $e) {
                        Log::error('MAX handle error: '.$e->getMessage(), ['update_type' => $update['update_type'] ?? null]);
                    }
                }

                // Подтверждаем прочтение всей пачки, даже если часть упала на стороне приложения.
                // Дока MAX: «after you pass marker, all previous updates are considered read».
                if (isset($data['marker'])) {
                    $marker = $data['marker'];
                }
            } catch (\Exception $e) {
                Log::error('MAX poll error: '.$e->getMessage());
                sleep(1);
            }
        }
    }
}
