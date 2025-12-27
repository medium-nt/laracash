<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Card;
use App\Models\Category;
use Illuminate\Contracts\Support\Renderable;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return Renderable
     */
    public function index()
    {
        $res = Bank::where('user_id', auth()->id())->exists()
            && Card::where('user_id', auth()->id())->exists()
            && Category::where('user_id', auth()->id())->exists();

        return view('home',
            [
                'is_configured' => $res,
                'daysUntilMonthEnd' => abs(now()->endOfMonth()->diffInDays(now())),
                'search_token' => auth()->user()->search_token
            ]);
    }
}
