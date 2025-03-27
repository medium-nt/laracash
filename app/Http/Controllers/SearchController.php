<?php

namespace App\Http\Controllers;

use App\Services\CashbackService;

class SearchController extends Controller
{
    public function index($token, $category = null, $mcc = null)
    {
        $user = auth()->user();

        if ($user->search_token !== $token) {
            abort(401, 'Неверный код клиента!');
        }

        return view('search.index', [
            'category' => $category,
            'mcc' => $mcc,
            'token' => $user->search_token,
            'categoriesCashback' => CashbackService::getAllCardWhichHavePercent($user->id, $category, $mcc)
        ]);
    }
}
