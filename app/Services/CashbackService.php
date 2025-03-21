<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Category;
use App\Models\Cashback;
use Illuminate\Support\Collection;

class CashbackService
{
    public static function getAllCard(): array
    {
        $cardsUser = Card::query()->where('user_id', auth()->user()->id)->get();
        $categoryUser = Category::query()->where('user_id', auth()->user()->id)->get();

        $matrix = [];
        foreach ($categoryUser as $category) {
            foreach ($cardsUser as $card) {
                $matrix[$category->id]['category_name'] = $category->title;

                $matrix[$category->id][$card->id]['card_number'] = $card->number;
                $matrix[$category->id][$card->id]['card_id'] = $card->id;
                $matrix[$category->id][$card->id]['bank_name'] = $card->bank->title;

                $cashback = Cashback::query()
                    ->where('card_id', $card->id)
                    ->where('category_id', $category->id)
                    ->first('cashback_percentage');
                $matrix[$category->id][$card->id]['percent'] = $cashback->cashback_percentage ?? '-';
            }
        }

        return $matrix;
    }

    public static function getOneCard(Card $card): Collection
    {
        $categoryUser = Category::query()->where('user_id', auth()->user()->id)->get();

        $matrix = [];
        foreach ($categoryUser as $category) {
            $matrix[$category->id]['category_name'] = $category->title;

            $matrix[$category->id]['card_number'] = $card->number;
            $matrix[$category->id]['card_id'] = $card->id;
            $matrix[$category->id]['bank_name'] = $card->bank->title;

            $cashback = Cashback::query()
                ->where('card_id', $card->id)
                ->where('category_id', $category->id)
                ->first();
            $matrix[$category->id]['percent'] = $cashback->cashback_percentage ?? '';
            $matrix[$category->id]['mcc'] = $cashback->mcc ?? '';
        }

        return collect($matrix);
    }

    public static function updateCard(Card $card, mixed $categories): void
    {
        foreach ($categories as $categoryId => $category) {
            Cashback::query()->updateOrCreate(
                [
                    'card_id' => $card->id,
                    'category_id' => $categoryId
                ], [
                    'cashback_percentage' => $category['percent'] ?? 0,
                    'mcc' => $category['mcc'] ?? ''
                ]
            );
        }
    }
}
