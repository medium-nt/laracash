<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();
        Log::info('Telegram update:', $update);

        if (isset($update['message'])) {
            $chatId = $update['message']['chat']['id'];
            $text   = $update['message']['text'] ?? '';

            $token = config('tg.token');
            $url   = "https://api.telegram.org/bot{$token}/sendMessage";

            $params = [
                'chat_id' => $chatId,
                'text'    => "Ты написал: {$text}"
            ];

            file_get_contents($url . '?' . http_build_query($params));
        }

        return response()->json(['status' => 'ok']);
    }
}
