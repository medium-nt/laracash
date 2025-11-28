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

        $token = config('tg.token');
        $apiUrl = "https://api.telegram.org/bot{$token}/";

        // === 1. Обычное сообщение ===
        if (isset($update['message']) && !isset($update['message']['web_app_data'])) {
            $chatId = $update['message']['chat']['id'];
            $text   = $update['message']['text'] ?? '';

            // Если пользователь написал "app" — отправляем кнопку для Mini App
            if (strtolower($text) === 'app') {
                $params = [
                    'chat_id' => $chatId,
                    'text'    => 'Запусти Mini App',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'Открыть Mini App',
                                    'web_app' => ['url' => 'https://laracash.test66.ru/tg-app']
                                ]
                            ]
                        ]
                    ])
                ];
            } else {
                $params = [
                    'chat_id' => $chatId,
                    'text'    => "Ты написал сейчас: {$text}"
                ];
            }

            file_get_contents($apiUrl . 'sendMessage?' . http_build_query($params));
        }

        // === 2. Данные из Mini App ===
        if (isset($update['message']['web_app_data'])) {
            $chatId = $update['message']['chat']['id'];
            $data   = $update['message']['web_app_data']['data'];

            Log::info("Mini App data: " . $data);

            $params = [
                'chat_id' => $chatId,
                'text'    => "Из Mini App пришло: {$data}"
            ];

            file_get_contents($apiUrl . 'sendMessage?' . http_build_query($params));
        }

        // === 3. Callback‑query (если будут кнопки без Mini App) ===
        if (isset($update['callback_query'])) {
            $chatId = $update['callback_query']['message']['chat']['id'];
            $data   = $update['callback_query']['data'];

            $params = [
                'chat_id' => $chatId,
                'text'    => "Нажата кнопка: {$data}"
            ];

            file_get_contents($apiUrl . 'sendMessage?' . http_build_query($params));
        }

        return response()->json(['status' => 'ok']);
    }
}
