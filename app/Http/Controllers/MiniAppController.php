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

    private function validateTelegramInitData(string $initData): bool
    {
        parse_str($initData, $params);

        if (!isset($params['hash'])) {
            return false;
        }

        $hash = $params['hash'];

        Log::info('Mini App hash received:', ['hash' => $hash]);

        unset($params['hash']);

        // сортировка по ключу ASCII
        ksort($params);

        $dataCheckArr = [];
        foreach ($params as $key => $value) {
            $dataCheckArr[] = "$key=$value";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        // секретный ключ — sha256 от BOT_TOKEN (raw binary)
        $botToken = config('tg.token');
        $secretKey = hash('sha256', $botToken, true);

        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        Log::info($dataCheckString);
        Log::info($calculatedHash);

        return hash_equals($calculatedHash, $hash);
    }
}
