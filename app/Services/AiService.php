<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Category;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiService
{
    private static function getPrompt(): string
    {
        $categories = Category::query()
            ->where('user_id', auth()->user()->id)
            ->pluck('title')
            ->implode(', ');

        return "На картинке скриншот категорий кешбека и их размера в процентах.
        Так же вот список категорий которые у меня есть: {$categories}.
        Распознай картинку и верни в виде массива с ключами 'category' и 'cashback'
        (без знака процентов и без markdown разметки. Только данные!) сопоставив мои категории с теми что на картинке.
        Сопоставь только те категории, которые полностью совпадают по названию или имеют минимальное различие (не более одного слова).
        Не пытайся сопоставлять категории, если они не имеют явного сходства в названии.
        Если в категории на картинке есть слово 'и', то пытайся сопоставить с несколькими категориями.
        Не придумывай как можно сопоставить категории если даже они сходятся общим единым смыслом/термином.
        Такси, метро, Ж/Д и самолеты - это разные категории!
        Заправки и АЗС - это одна категория!
        Кафе и рестораны - это одна категория, а фастфуд - это другая.
        ";
    }

    public static function downloadFile(Card $card): string
    {
        $path = storage_path('app/public/card_cashback_image/' . $card->id . '.jpg');

        if (!file_exists($path)) {
            return '';
        }

        $fileSize = filesize($path);
        Log::channel('ai_api')->info("Загрузка файла", [
            'card_id' => $card->id,
            'size' => $fileSize . ' bytes'
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . self::getToken(),
        ])->withOptions([
            'verify' => false,
            'timeout' => 90,
        ])->attach(
            'file',
            file_get_contents($path),
            '1.jpg'
        )->post('https://gigachat.devices.sberbank.ru/api/v1/files', [
            'purpose' => 'general',
        ]);

        if (!$response->successful()) {
            Log::channel('ai_api')->error('Ошибка загрузки файла', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
        }

        return $response->json('id', '');
    }

    private static function recognizeGigaChat(Card $card): bool
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . self::getToken(),
            ])->withOptions([
                'verify' => false,
            ])->post('https://gigachat.devices.sberbank.ru/api/v1/chat/completions', [
                'model' => 'GigaChat-2-Pro',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => self::getPrompt(),
                        "attachments" => [
                            self::downloadFile($card),
                        ]
                    ]
                ],
                'stream' => false,
                'profanity_check' => true,
            ]);

            if ($response->clientError()) {
                // Если ошибка 401 (недействительный токен), пробуем обновить
                if ($response->status() === 401) {
                    $newToken = self::refreshToken();
                    if ($newToken) {
                        // Повторяем запрос с новым токеном
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $newToken,
                        ])->withOptions([
                            'verify' => false,
                        ])->post('https://gigachat.devices.sberbank.ru/api/v1/chat/completions', [
                            'model' => 'GigaChat-2-Pro',
                            'messages' => [
                                [
                                    'role' => 'user',
                                    'content' => self::getPrompt(),
                                    "attachments" => [
                                        self::downloadFile($card),
                                    ]
                                ]
                            ],
                            'stream' => false,
                            'profanity_check' => true,
                        ]);
                    } else {
                        Log::channel('ai_api')
                            ->error('Не удалось обновить токен. Ошибка клиента: ' . $response->body());
                        return false;
                    }
                } else {
                    // Другие 4xx ошибки
                    Log::channel('ai_api')
                        ->error('Не удалось распознать кешбек. Ошибка клиента: ' . $response->body());
                    return false;
                }
            }

            if ($response->serverError()) {
                // 5xx
                Log::channel('ai_api')
                    ->error('Не удалось распознать кешбек. Ошибка сервера: ' . $response->body());
                return false;
            }

            $result = $response->json();

            $decoded = json_decode($result['choices'][0]['message']['content'], true);

            $card->cashback_json = $decoded;
            $card->save();

            return true;
        } catch (Exception $e) {
            Log::channel('ai_api')
                ->error('Не удалось распознать кешбек. Ошибка: ' . $e->getMessage());
            return false;
        }
    }

    private static function getToken(): string
    {
        return Cache::remember('gigachat_token', 1700, function () { // 1700 секунд = 28 минут (меньше чем 30)
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
                'RqUID' => (string) Str::uuid(),
                'Authorization' => 'Basic MDE5OTk2NDYtYmI2ZC03Mjc5LWE1ZGItYTBjMDYxMTdiMDA3Ojk2MDU0N2ZkLTZlOWEtNDA3Ni1hYWEyLThkMDUyMmE2NTY0Zg==',
            ])->withOptions([
                    'verify' => false,
                ])
                ->asForm()->post('https://ngw.devices.sberbank.ru:9443/api/v2/oauth', [
                'scope' => 'GIGACHAT_API_PERS',
            ]);

            if (empty($response->json('access_token'))) {
                return '';
            }

            return $response->json('access_token');
        });
    }

    private static function refreshToken(): string
    {
        Cache::forget('gigachat_token');
        return self::getToken();
    }

    public function getRecognizedCashback(Card $card): bool
    {
        return self::recognizeGigaChat($card);
    }
}
