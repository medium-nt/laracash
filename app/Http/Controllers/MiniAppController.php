<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MiniAppController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->input('data');
        $initData = $request->input('initData'); // передаём из JS

        if (!$this->validateTelegramInitData($initData)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        Log::info('Mini App data received:', ['data' => $data]);

        // Тут можно слать данные дальше боту или в базу
        return response()->json(['status' => 'ok']);
    }

    private function validateTelegramInitData($initData)
    {
        $token = config('tg.token'); // BOT TOKEN
        $dataCheckString = '';
        parse_str($initData, $params);

        $hash = $params['hash'] ?? null;
        unset($params['hash']);

        foreach ($params as $key => $value) {
            $dataCheckString .= "$key=$value\n";
        }
        $dataCheckString = rtrim($dataCheckString, "\n");

        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($calculatedHash, $hash);
    }
}
