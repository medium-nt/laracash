<?php

namespace App\Services\Bot;

use App\Models\Card;
use App\Models\Category;
use App\Services\AiService;
use App\Services\CashbackService;

class CashbackImportService
{
    /**
     * Create a new service instance.
     */
    public function __construct(private AiService $ai) {}

    /**
     * Распознаёт фото и делит результат на категории юзера (saved) и прочие (skipped).
     *
     * @param  int  $userId  ID пользователя.
     * @param  int  $cardId  ID карты.
     * @param  string[]  $photoPaths  Абсолютные пути к фото на диске.
     * @return array{saved: list<array{category_id:int,title:string,percent:mixed}>, skipped: list<array{title:string,percent:mixed}>, raw: array, card_id: int}
     */
    public function import(int $userId, int $cardId, array $photoPaths): array
    {
        // Категории пользователя: norm => ['id', 'title'(каноничный)].
        // Нормализованный ключ (как в ensureCategories) — чтобы saved/skipped
        // согласовывались с тем, что реально создастся/присоединится при apply.
        $byNorm = [];
        foreach (Category::query()->where('user_id', $userId)->get(['id', 'title']) as $category) {
            $byNorm[self::norm($category->title)] = ['id' => $category->id, 'title' => $category->title];
        }

        $saved = [];
        $skipped = [];
        $skippedSeen = []; // norm => true, для дедупа
        $raw = [];

        foreach ($photoPaths as $path) {
            // Распознаём фото через AI сервис
            $recognized = $this->ai->recognizeForImport($userId, $path);

            if ($recognized === null) {
                // Если распознавание неудачно, пропускаем это фото
                continue;
            }

            // Обрабатываем распознанные категории
            foreach ($recognized as $item) {
                $raw[] = $item;

                $title = trim((string) ($item['category'] ?? ''));
                $percent = (float) ($item['cashback'] ?? 0);
                $norm = self::norm($title);

                if ($title !== '' && isset($byNorm[$norm])) {
                    // Категория существует у пользователя — добавляем в saved (каноничный title)
                    $saved[] = [
                        'category_id' => $byNorm[$norm]['id'],
                        'title' => $byNorm[$norm]['title'],
                        'percent' => $percent,
                    ];
                } elseif ($title !== '' && ! isset($skippedSeen[$norm])) {
                    // Категории нет у пользователя — добавляем в skipped (с процентом)
                    $skippedSeen[$norm] = true;
                    $skipped[] = ['title' => $title, 'percent' => $percent];
                }
            }
        }

        return [
            'saved' => $saved,
            'skipped' => $skipped,
            'raw' => $raw,
            'card_id' => $cardId,
        ];
    }

    /**
     * Применяет распознанный кешбэк: создаёт недостающие категории, сохраняет
     * card.cashback_json и записи в pivot через CashbackService::updateCard.
     *
     * @param  int  $userId  ID пользователя.
     * @param  int  $cardId  ID карты.
     * @param  array  $raw  Распознанный массив [{category, cashback}, ...].
     * @return array{created: list<string>} Список названий созданных категорий.
     */
    public function apply(int $userId, int $cardId, array $raw): array
    {
        // Если распознанных данных нет - не перезаписываем cashback_json
        if (empty($raw)) {
            return ['created' => []];
        }

        // Получаем карту с проверкой владельца (user-scoping)
        $card = Card::query()
            ->where('id', $cardId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Создаём недостающие категории (с защитой от дублей)
        [$raw, $created] = $this->ensureCategories($userId, $raw);

        // Сохраняем raw JSON в карточку целиком, как есть
        $card->cashback_json = $raw;
        $card->save();

        // Получаем категории пользователя: title => id
        $userTitles = Category::query()
            ->where('user_id', $userId)
            ->pluck('id', 'title');

        // Формируем массив для обновления только для категорий с валидным percent > 0
        $categories = [];
        foreach ($raw as $item) {
            $title = (string) ($item['category'] ?? '');

            if (isset($userTitles[$title])) {
                // Извлекаем числовое значение, убираем возможный '%'
                $percentValue = (string) ($item['cashback'] ?? '0');
                $percent = (float) trim($percentValue, '%');

                // Пропускаем категории с percent <= 0 или нечисловыми значениями
                if ($percent > 0) {
                    $categories[$userTitles[$title]] = [
                        'percent' => $percent,
                        'mcc' => '',
                    ];
                }
            }
        }

        // Обновляем pivot таблицу через CashbackService
        CashbackService::updateCard($card, $categories);

        return ['created' => $created];
    }

    /**
     * Гарантирует, что все категории из raw существуют у пользователя:
     * точное совпадение (нормализованное) переиспользует существующую, недостающее — создаётся.
     *
     * Синонимы/подстроки НЕ схлопываются здесь: они уже обработаны fuzzy-маппингом
     * на этапе recognize (AiService::mapToUserCategories). Точный матч защищает
     * от дублей при ручном вводе (регистр/лишние пробелы), остальное — ответственность пользователя.
     *
     * @param  int  $userId  ID пользователя.
     * @param  array  $raw  Распознанный массив (мутируется: category приводится к каноничному title).
     * @return array{0: array, 1: list<string>} [raw, список созданных titles].
     */
    private function ensureCategories(int $userId, array $raw): array
    {
        $existing = Category::query()
            ->where('user_id', $userId)
            ->get(['id', 'title']);

        // norm => каноничный title
        $byNorm = [];
        foreach ($existing as $category) {
            $byNorm[self::norm((string) $category->title)] = $category->title;
        }

        $created = [];
        foreach ($raw as &$item) {
            $title = trim((string) ($item['category'] ?? ''));
            if ($title === '') {
                continue;
            }

            $norm = self::norm($title);

            if (isset($byNorm[$norm])) {
                // Присоединяем к существующей (каноничный title)
                $item['category'] = $byNorm[$norm];

                continue;
            }

            // Создаём новую категорию (keywords автогенерируем из названия)
            Category::create([
                'user_id' => $userId,
                'title' => $title,
                'keywords' => $title,
            ]);
            $byNorm[$norm] = $title;
            $created[] = $title;
        }
        unset($item);

        return [$raw, $created];
    }

    /**
     * Нормализует название для сравнения: нижний регистр + схлопывание пробелов + trim.
     */
    private static function norm(string $title): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($title)));
    }
}
