<?php

namespace App\Http\Controllers;

use App\Models\User;

class SearchController extends Controller
{
    public function index($token)
    {
        $user = User::query()->where('search_token', $token)->first();
        if (!$user) {
            abort(401, 'Неверный код клиента!');
        }

        return view('search.index', [
            'user' => $user,
        ]);
    }
}
