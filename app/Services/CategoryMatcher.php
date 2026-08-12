<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Сопоставляет свободный текст названия категории с категориями пользователя.
 *
 * Единый источник истины для fuzzy-мэтчинга (используется и распознаванием фото
 * через AiService, и текстовым вводом в ботах) и для нормализации названий.
 *
 * Алгоритм 4 шага (по возрастанию толерантности):
 *  1. точное совпадение по title;
 *  2. точное совпадение по токену-синониму (поле keywords);
 *  3. вхождение подстроки (по убыванию длины названия, с min-length guard);
 *  4. сходство строк similar_text не ниже порога.
 */
class CategoryMatcher
{
    /**
     * Минимальный процент сходства строк (similar_text), при котором
     * строка считается совпавшей с категорией пользователя.
     */
    public const SIMILARITY_THRESHOLD = 70;

    /**
     * Минимальная длина строки (в символах) для шага сопоставления по вхождению
     * подстроки. Короткие строки («и», «АЗС») через подстроку не сравниваются —
     * иначе они матчат почти любую категорию.
     */
    public const MIN_SUBSTRING_LENGTH = 4;

    /**
     * Нормализует название для сравнения: нижний регистр + схлопывание пробелов + trim.
     *
     * Единая нормализация для всех путей (AiService, боты, CashbackImportService).
     */
    public static function normalize(string $title): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($title)));
    }

    /**
     * Разбивает поле keywords на нормализованные токены-синонимы.
     *
     * @param  string|null  $keywords  Поле keywords категории.
     * @return string[] Массив нормализованных синонимов.
     */
    public static function keywordsTokens(?string $keywords): array
    {
        if ($keywords === null || trim($keywords) === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', trim($keywords)) ?: [];

        return array_map(fn (string $token) => self::normalize($token), $tokens);
    }

    /**
     * Ищет категорию пользователя, соответствующую свободному тексту.
     *
     * Грузит категории пользователя и делегирует в match(). Удобно для точечного
     * вызова (ручной ввод в боте); для массового (распознавание фото) используйте
     * match() с заранее загруженной коллекцией — чтобы не плодить запросы к БД.
     *
     * @param  int  $userId  ID владельца категорий.
     * @param  string  $title  Свободный текст названия.
     * @return Category|null Каноничная категория (id + title) либо null.
     */
    public function findForUser(int $userId, string $title): ?Category
    {
        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('title')
            ->get(['id', 'title', 'keywords']);

        return $this->match($title, $categories);
    }

    /**
     * Точное (нормализованное) совпадение — БЕЗ fuzzy.
     *
     * Для «принудительно новой» категории (маркер «+» в боте): точное совпадение
     * переиспользуется, а синонимы/подстроки/similar_text НЕ срабатывают, чтобы
     * введённое название не подменялось похожей существующей категорией.
     *
     * @param  int  $userId  ID владельца категорий.
     * @param  string  $title  Введённое название.
     * @return Category|null Существующая категория с тем же нормализованным title либо null.
     */
    public function findExactForUser(int $userId, string $title): ?Category
    {
        $categories = Category::query()
            ->where('user_id', $userId)
            ->get(['id', 'title']);

        return $this->matchExact($title, $categories);
    }

    /**
     * Сопоставляет свободный текст с коллекцией категорий пользователя.
     *
     * @param  string  $title  Свободный текст названия.
     * @param  Collection<int, Category>  $categories  Категории пользователя.
     * @return Category|null Каноничная категория либо null при отсутствии совпадения.
     */
    public function match(string $title, Collection $categories): ?Category
    {
        $input = self::normalize($title);

        if ($input === '') {
            return null;
        }

        // 1-2. Точное совпадение по названию и по синонимам — самый надёжный вариант.
        foreach ($categories as $category) {
            if (self::normalize((string) $category->title) === $input) {
                return $category;
            }

            foreach (self::keywordsTokens($category->keywords) as $token) {
                if ($token === $input) {
                    return $category;
                }
            }
        }

        // 3. Вхождение подстроки — отдельным проходом по убыванию длины названия,
        //    чтобы самое специфичное имя побеждало. Короткие строки пропускаем,
        //    иначе «и» или «АЗС» будут матчить почти любую категорию.
        $byTitleLengthDesc = $categories->sortByDesc(fn ($c) => mb_strlen(self::normalize((string) $c->title)));

        foreach ($byTitleLengthDesc as $category) {
            $normalizedTitle = self::normalize((string) $category->title);

            if (mb_strlen($normalizedTitle) < self::MIN_SUBSTRING_LENGTH || mb_strlen($input) < self::MIN_SUBSTRING_LENGTH) {
                continue;
            }

            if (str_contains($input, $normalizedTitle) || str_contains($normalizedTitle, $input)) {
                return $category;
            }
        }

        // 4. Наибольшее сходство строк среди всех категорий, если выше порога.
        $bestCategory = null;
        $bestPercent = 0.0;

        foreach ($categories as $category) {
            $normalizedTitle = self::normalize((string) $category->title);
            if ($normalizedTitle === '') {
                continue;
            }

            similar_text($input, $normalizedTitle, $percent);

            if ($percent > $bestPercent) {
                $bestPercent = $percent;
                $bestCategory = $category;
            }
        }

        return $bestPercent >= self::SIMILARITY_THRESHOLD ? $bestCategory : null;
    }

    /**
     * Точное (нормализованное) совпадение по коллекции — БЕЗ fuzzy.
     *
     * Симметрично match(): для массового вызова с заранее загруженной коллекцией,
     * чтобы не плодить запросы к БД. Для «принудительно новой» категории (маркер
     * «+» в боте): точное совпадение переиспользуется, а синонимы/подстроки/
     * similar_text НЕ срабатывают, чтобы название не подменялось похожей категорией.
     *
     * @param  string  $title  Введённое название.
     * @param  Collection<int, Category>  $categories  Категории пользователя.
     * @return Category|null Существующая категория с тем же нормализованным title либо null.
     */
    public function matchExact(string $title, Collection $categories): ?Category
    {
        $input = self::normalize($title);

        if ($input === '') {
            return null;
        }

        return $categories->first(fn ($c) => self::normalize((string) $c->title) === $input);
    }
}
