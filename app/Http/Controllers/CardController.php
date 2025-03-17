<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Card;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('cards.index', [
            'title' => 'Карты',
            'cards' => Card::query()->where('user_id', auth()->user()->id)->paginate(10),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('cards.create', [
            'title' => 'Добавить карту',
            'banks' => Bank::query()->where('user_id', auth()->user()->id)->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'number' => 'required|string|max:255|min:2',
            'bank_id' => 'required|exists:banks,id',
            'color' => 'required|string|max:10|min:2',
        ]);

        Card::query()->create([
            'user_id' => auth()->user()->id,
            'number' => $request->number,
            'bank_id' => $request->bank_id,
            'color' => $request->color
        ]);

        return redirect()->route('cards.index')->with('success', 'Карта добавлена');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Card $card): View
    {
        return view('cards.edit', [
            'title' => 'Изменить карту',
            'banks' => Bank::query()->where('user_id', auth()->user()->id)->get(),
            'card' => $card
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Card $card): RedirectResponse
    {
        $request->validate([
            'number' => 'required|string|max:255|min:2',
            'bank_id' => 'required|exists:banks,id',
            'color' => 'required|string|max:10|min:2',
        ]);

        $card->update([
            'number' => $request->number,
            'bank_id' => $request->bank_id,
            'color' => $request->color
        ]);

        return redirect()->route('cards.index')->with('success', 'Изменения сохранены.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Card $card): RedirectResponse
    {
        $card->delete();

        return redirect()->route('cards.index')->with('success', 'Карта удалена');
    }
}
