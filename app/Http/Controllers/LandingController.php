<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Card;
use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Support\Renderable;

class LandingController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @return Renderable
     */
    public function index()
    {
        return view('landing',
            [
                $countUsers = User::all()->count(),

                'usersCount' => $countUsers,
                'totalSavings' => $countUsers * 1500,
            ]);
    }
}
