<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Category;
use App\Services\CashbackService;
use Illuminate\Http\Request;

class CashbackController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('cashback.index', [
            'title' => 'Ваш кешбэк',
            'cashbackTable' => CashbackService::getAllCard(),
            'allCardUser' => Card::query()->where('user_id', auth()->user()->id)->get(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function categoryShow(Category $category)
    {
        return view('cashback.category_show', [
            'title' => 'Кешбек по категории',
            'category' => $category
        ]);
    }

    public function cardEdit(Card $card)
    {
        return view('cashback.card_edit', [
            'title' => 'Редактировать кешбек по карте: ' . $card->number . ' (' . $card->bank->title . ')',
            'cashbacks' => CashbackService::getOneCard($card),
            'card' => $card
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function cardUpdate(Request $request, Card $card)
    {
        $validate = $request->validate([
            'categories' => 'required|array',
            'categories.*.percent' => 'nullable|numeric|min:0|max:100',
            'categories.*.mcc' => 'nullable|string|max:255',
        ]);

        CashbackService::updateCard($card, $validate['categories']);

        return redirect()->route('cashback.card_edit' , ['card' => $card->id])
            ->with('success', 'Изменения сохранены.');
    }
}
