<?php

namespace App\Http\Controllers;

use App\Models\AvailableAllCashback;
use App\Models\Cashback;
use App\Models\Card;
use App\Models\Category;
use App\Services\AiService;
use App\Services\CashbackService;
use App\Services\FileStorageService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

    public function allAvailableCashback()
    {
        $userId = auth()->user()->id;

        // Проверить, есть ли данные для пользователя в AvailableAllCashback
        $userCards = Card::where('user_id', $userId)->pluck('id');
        $hasData = AvailableAllCashback::whereIn('card_id', $userCards)->exists();

        if (!$hasData) {
            // Копировать данные из card_category_cashback
            $cashbacks = Cashback::whereIn('card_id', $userCards)->get();

            foreach ($cashbacks as $cashback) {
                AvailableAllCashback::updateOrCreate(
                    [
                        'card_id' => $cashback->card_id,
                        'category_id' => $cashback->category_id
                    ],
                    [
                        'cashback_percentage' => $cashback->cashback_percentage,
                        'is_check' => false
                    ]
                );
            }
        }

        return view('cashback.all_available_cashback', [
            'title' => 'Ваш кешбэк',
            'cashbackTable' => CashbackService::getAllCard('available'),
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

    public function cardEdit(Card $card, Request $request)
    {
        return view('cashback.card_edit', [
            'title' => 'Редактировать кешбек по карте: ' . $card->number . ' (' . $card->bank->title . ')',
            'cashbacks' => CashbackService::getOneCard($card),
            'card' => $card,
            'recognizedCashback' => $card->cashback_json,
            'fill_recognize' => (bool)$request->get('fill_recognize')
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

    public function downloadCashbackImage(Request $request, Card $card, FileStorageService $fileStorage)
    {
        $request->validate([
            'image' => 'required|image|max:2048',
        ]);

        try {
            $filename = $card->id . '.' . $request->file('image')->extension();

            $fileStorage->save(
                $request->file('image'),
                'card_cashback_image',
                $filename
            );

            $card->cashback_image = $filename;
            $card->cashback_json = null;
            $card->save();

            return back()->with('success', 'Изображение сохранено.');
        } catch (Exception $e) {
            Log::error('Ошибка сохранения изображения для карты ' . $card->id . ': ' . $e->getMessage());

            return back()->with('error', 'Не удалось сохранить изображение. Попробуйте ещё раз.');
        }
    }

    public function destroyCashbackImage(Card $card)
    {
        $card->cashback_image = '';
        $card->cashback_json = null;
        $card->save();

        return back()->with('success', 'Изображение удалено.');
    }

    public function recognizeCashback(Card $card, AiService $ai)
    {
        if($ai->getRecognizedCashback($card)) {
            return back()->with('success', 'Кешбек распознан.');
        }

        return back()->with('error', 'Кешбек не был распознан!');
    }

    public function inlineUpdate(Request $request)
    {
        $request->validate([
            'card_id' => 'required|integer',
            'category_id' => 'required|integer',
            'percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $record = AvailableAllCashback::where('card_id', $request->card_id)
            ->where('category_id', $request->category_id)
            ->firstOrFail();

        $record->cashback_percentage = $request->percent ?: 0;
        $record->save();

        return response()->json(['status' => 'ok']);
    }

    public function togglePin(Request $request)
    {
        $request->validate([
            'card_id' => 'required|integer',
            'category_id' => 'required|integer',
            'is_check' => 'required|boolean',
        ]);

        $record = AvailableAllCashback::where('card_id', $request->card_id)
            ->where('category_id', $request->category_id)
            ->firstOrFail();

        $record->is_check = $request->is_check;
        $record->save();

        return response()->json(['status' => 'ok', 'is_check' => $record->is_check]);
    }
}
