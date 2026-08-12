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
    /**
     * Генерирует промпт для GigaChat с категориями пользователя.
     *
     * @param  int  $userId  ID пользователя.
     * @return string Текст промпта.
     */
    private static function getPrompt(int $userId): string
    {
        $categories = Category::query()
            ->where('user_id', $userId)
            ->get(['title', 'keywords'])
            ->map(function (Category $category) {
                $keywords = trim((string) $category->keywords);

                return $keywords !== ''
                    ? "«{$category->title}» (синонимы: {$keywords})"
                    : "«{$category->title}»";
            })
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

    /**
     * Загружает файл в GigaChat и возвращает его ID.
     *
     * @param  string  $filePath  Абсолютный путь к файлу.
     * @param  string  $originalName  Оригинальное имя файла.
     * @return string ID файла в GigaChat или пустая строка при ошибке.
     */
    private static function downloadFile(string $filePath, string $originalName): string
    {
        if (! file_exists($filePath)) {
            Log::channel('ai_api')->error('Файл не найден', [
                'path' => $filePath,
            ]);

            return '';
        }

        $fileSize = filesize($filePath);
        Log::channel('ai_api')->info('Загрузка файла', [
            'path' => $filePath,
            'size' => $fileSize.' bytes',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.self::getToken(),
        ])->withOptions([
            'verify' => false,
            'timeout' => 90,
        ])->attach(
            'file',
            file_get_contents($filePath),
            $originalName
        )->post('https://gigachat.devices.sberbank.ru/api/v1/files', [
            'purpose' => 'general',
        ]);

        if (! $response->successful()) {
            Log::channel('ai_api')->error('Ошибка загрузки файла', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->json('id', '');
    }

    /**
     * Распознаёт кешбэк на изображении через GigaChat.
     *
     * @param  int  $userId  ID пользователя.
     * @param  string  $filePath  Абсолютный путь к файлу изображения.
     * @return array|null Распознанный массив [{category, cashback}, ...] или null при ошибке.
     */
    private static function recognize(int $userId, string $filePath): ?array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.self::getToken(),
            ])->withOptions([
                'verify' => false,
            ])->post('https://gigachat.devices.sberbank.ru/api/v1/chat/completions', [
                'model' => 'GigaChat-2-Pro',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => self::getPrompt($userId),
                        'attachments' => [
                            self::downloadFile($filePath, basename($filePath)),
                        ],
                    ],
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
                            'Authorization' => 'Bearer '.$newToken,
                        ])->withOptions([
                            'verify' => false,
                        ])->post('https://gigachat.devices.sberbank.ru/api/v1/chat/completions', [
                            'model' => 'GigaChat-2-Pro',
                            'messages' => [
                                [
                                    'role' => 'user',
                                    'content' => self::getPrompt($userId),
                                    'attachments' => [
                                        self::downloadFile($filePath, basename($filePath)),
                                    ],
                                ],
                            ],
                            'stream' => false,
                            'profanity_check' => true,
                        ]);
                    } else {
                        Log::channel('ai_api')
                            ->error('Не удалось обновить токен. Ошибка клиента: '.$response->body());

                        return null;
                    }
                } else {
                    // Другие 4xx ошибки
                    Log::channel('ai_api')
                        ->error('Не удалось распознать кешбек. Ошибка клиента: '.$response->body());

                    return null;
                }
            }

            if ($response->serverError()) {
                // 5xx
                Log::channel('ai_api')
                    ->error('Не удалось распознать кешбек. Ошибка сервера: '.$response->body());

                return null;
            }

            $result = $response->json();

            $decoded = json_decode($result['choices'][0]['message']['content'], true);

            if (! is_array($decoded) || empty($decoded)) {
                Log::channel('ai_api')->error('Не удалось распознать кешбек. Пустой или невалидный ответ модели.', [
                    'user_id' => $userId,
                    'content' => $result['choices'][0]['message']['content'] ?? null,
                ]);

                return null;
            }

            return self::mapToUserCategories($decoded, $userId);
        } catch (Exception $e) {
            Log::channel('ai_api')
                ->error('Не удалось распознать кешбек. Ошибка: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Сопоставляет распознанные GigaChat категории с категориями пользователя:
     * точное совпадение по title -> по синонимам (keywords) -> вхождение подстроки
     * -> сходство строк не ниже порога. Несопоставленные строки возвращаются как есть.
     *
     * @param  array  $recognized  Ответ GigaChat: [{category, cashback}, ...].
     * @param  int  $userId  ID владельца категорий.
     * @return array Тот же массив, где совпавшие category заменены на каноничные title.
     */
    private static function mapToUserCategories(array $recognized, int $userId): array
    {
        $matcher = new CategoryMatcher;

        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('title')
            ->get(['id', 'title', 'keywords']);

        foreach ($recognized as &$item) {
            if (! is_array($item) || ! isset($item['category'])) {
                continue;
            }

            $matched = $matcher->match((string) $item['category'], $categories);

            if ($matched !== null) {
                $item['category'] = $matched->title;
            }
        }
        unset($item);

        return $recognized;
    }

    private static function getToken(): string
    {
        return Cache::remember('gigachat_token', 1700, function () { // 1700 секунд = 28 минут (меньше чем 30)
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
                'RqUID' => (string) Str::uuid(),
                'Authorization' => 'Basic '.config('services.gigachat.auth_key'),
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

    /**
     * Распознаёт кешбэк на изображении через GigaChat (публичный метод-обёртка для импорта).
     *
     * @param  int  $userId  ID пользователя.
     * @param  string  $filePath  Абсолютный путь к файлу изображения.
     * @return array|null Распознанный массив [{category, cashback}, ...] или null при ошибке.
     */
    public function recognizeForImport(int $userId, string $filePath): ?array
    {
        return self::recognize($userId, $filePath);
    }

    /**
     * Распознаёт и сохраняет кешбэк для карты (веб-обёртка).
     *
     * @param  Card  $card  Карта для распознавания.
     * @return bool True если распознавание успешно, false в противном случае.
     */
    public function getRecognizedCashback(Card $card): bool
    {
        if (empty($card->cashback_image)) {
            return false;
        }

        $path = storage_path('app/public/card_cashback_image/'.$card->cashback_image);
        $recognized = self::recognize($card->user_id, $path);

        if ($recognized === null) {
            return false;
        }

        $card->cashback_json = $recognized;
        $card->save();

        return true;
    }
}
