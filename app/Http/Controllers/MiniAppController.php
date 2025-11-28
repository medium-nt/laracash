<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MiniAppController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->input('data');
        Log::info('Mini App data received:', ['data' => $data]);

        // Тут можно шлёть данные дальше боту или в базу
        return response()->json(['status' => 'ok']);
    }
}
