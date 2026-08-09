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
     * Минимальный процент сходства строк (similar_text), при котором
     * распознанная категория считается совпавшей с категорией пользователя.
     */
    private const SIMILARITY_THRESHOLD = 70;

    /**
     * Минимальная длина строки (в символах) для шага сопоставления по вхождению
     * подстроки. Короткие строки («и», «АЗС») через подстроку не сравниваются —
     * иначе они матчат почти любую категорию.
     */
    private const MIN_SUBSTRING_LENGTH = 4;

    private static function getPrompt(): string
    {
        $categories = Category::query()
            ->where('user_id', auth()->user()->id)
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

    public static function downloadFile(Card $card): string
    {
        if (empty($card->cashback_image)) {
            Log::channel('ai_api')->error('Путь к скриншоту кешбека пуст', ['card_id' => $card->id]);

            return '';
        }

        $path = storage_path('app/public/card_cashback_image/'.$card->cashback_image);

        if (! file_exists($path)) {
            Log::channel('ai_api')->error('Файл скриншота кешбека не найден', [
                'card_id' => $card->id,
                'path' => $path,
            ]);

            return '';
        }

        $fileSize = filesize($path);
        Log::channel('ai_api')->info('Загрузка файла', [
            'card_id' => $card->id,
            'size' => $fileSize.' bytes',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.self::getToken(),
        ])->withOptions([
            'verify' => false,
            'timeout' => 90,
        ])->attach(
            'file',
            file_get_contents($path),
            $card->cashback_image
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

    private static function recognizeGigaChat(Card $card): bool
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
                        'content' => self::getPrompt(),
                        'attachments' => [
                            self::downloadFile($card),
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
                                    'content' => self::getPrompt(),
                                    'attachments' => [
                                        self::downloadFile($card),
                                    ],
                                ],
                            ],
                            'stream' => false,
                            'profanity_check' => true,
                        ]);
                    } else {
                        Log::channel('ai_api')
                            ->error('Не удалось обновить токен. Ошибка клиента: '.$response->body());

                        return false;
                    }
                } else {
                    // Другие 4xx ошибки
                    Log::channel('ai_api')
                        ->error('Не удалось распознать кешбек. Ошибка клиента: '.$response->body());

                    return false;
                }
            }

            if ($response->serverError()) {
                // 5xx
                Log::channel('ai_api')
                    ->error('Не удалось распознать кешбек. Ошибка сервера: '.$response->body());

                return false;
            }

            $result = $response->json();

            $decoded = json_decode($result['choices'][0]['message']['content'], true);

            if (! is_array($decoded) || empty($decoded)) {
                Log::channel('ai_api')->error('Не удалось распознать кешбек. Пустой или невалидный ответ модели.', [
                    'card_id' => $card->id,
                    'content' => $result['choices'][0]['message']['content'] ?? null,
                ]);

                return false;
            }

            $decoded = self::mapToUserCategories($decoded, auth()->user()->id);

            $card->cashback_json = $decoded;
            $card->save();

            return true;
        } catch (Exception $e) {
            Log::channel('ai_api')
                ->error('Не удалось распознать кешбек. Ошибка: '.$e->getMessage());

            return false;
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
        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('title')
            ->get(['title', 'keywords']);

        foreach ($recognized as &$item) {
            if (! is_array($item) || ! isset($item['category'])) {
                continue;
            }

            $matched = self::matchCategory((string) $item['category'], $categories);

            if ($matched !== null) {
                $item['category'] = $matched;
            }
        }
        unset($item);

        return $recognized;
    }

    /**
     * Ищет каноничный title категории пользователя для распознанной строки.
     *
     * @param  string  $recognized  Распознанная GigaChat строка категории.
     * @param  \Illuminate\Support\Collection  $categories  Категории пользователя.
     * @return string|null Каноничный title либо null при отсутствии совпадения.
     */
    private static function matchCategory(string $recognized, $categories): ?string
    {
        $needle = self::normalize($recognized);

        if ($needle === '') {
            return null;
        }

        // 1-2. Точное совпадение по названию и по синонимам — самый надёжный вариант.
        foreach ($categories as $category) {
            $title = self::normalize((string) $category->title);

            if ($title === $needle) {
                return $category->title;
            }

            foreach (self::keywordsTokens($category->keywords) as $token) {
                if ($token === $needle) {
                    return $category->title;
                }
            }
        }

        // 3. Вхождение подстроки — отдельным проходом по убыванию длины названия,
        //    чтобы самое специфичное имя побеждало. Короткие строки пропускаем,
        //    иначе «и» или «АЗС» будут матчить почти любую категорию.
        $byTitleLengthDesc = $categories->sortByDesc(fn ($c) => mb_strlen(self::normalize((string) $c->title)));

        foreach ($byTitleLengthDesc as $category) {
            $title = self::normalize((string) $category->title);

            if (mb_strlen($title) < self::MIN_SUBSTRING_LENGTH || mb_strlen($needle) < self::MIN_SUBSTRING_LENGTH) {
                continue;
            }

            if (str_contains($needle, $title) || str_contains($title, $needle)) {
                return $category->title;
            }
        }

        // 4. Наибольшее сходство строк среди всех категорий, если выше порога.
        $bestTitle = null;
        $bestPercent = 0.0;

        foreach ($categories as $category) {
            $title = self::normalize((string) $category->title);
            if ($title === '') {
                continue;
            }

            similar_text($needle, $title, $percent);

            if ($percent > $bestPercent) {
                $bestPercent = $percent;
                $bestTitle = $category->title;
            }
        }

        return $bestPercent >= self::SIMILARITY_THRESHOLD ? $bestTitle : null;
    }

    /**
     * Нормализует строку для сравнения: нижний регистр + обрезка пробелов.
     *
     * @param  string  $value  Исходная строка.
     * @return string Нормализованная строка.
     */
    private static function normalize(string $value): string
    {
        return trim(mb_strtolower($value));
    }

    /**
     * Разбивает поле keywords на нормализованные токены-синонимы.
     *
     * @param  string|null  $keywords  Поле keywords категории.
     * @return string[] Массив нормализованных синонимов.
     */
    private static function keywordsTokens(?string $keywords): array
    {
        if ($keywords === null || trim($keywords) === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', trim($keywords)) ?: [];

        return array_map(fn (string $token) => self::normalize($token), $tokens);
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

    public function getRecognizedCashback(Card $card): bool
    {
        return self::recognizeGigaChat($card);
    }
}
