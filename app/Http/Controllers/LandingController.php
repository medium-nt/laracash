<?php

namespace App\Http\Controllers;

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
                'demoUserToken' => User::where('email', '1@1.ru')
                    ->first()
                    ->search_token
            ]);
    }
}
