<?php

namespace App\Http\Controllers;

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
            'card' => $card,
            'recognizedCashback' => $card->cashback_json,
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
}
