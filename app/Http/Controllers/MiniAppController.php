<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MiniAppController extends Controller
{
    public function handle(Request $request)
    {
        // 1) ВАЖНО: с клиента присылай именно Telegram.WebApp.initData
        $initData = (string) $request->input('initData', '');
        $data     = $request->input('data');

        $isValid = $this->validateTelegramInitData($initData, config('tg.token'));

        if (!$isValid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        Log::info('Mini App data received:', ['data' => $data]);

        // тут твоя логика (сохранение, связь с Telegram-аккаунтом и т.д.)
        return response()->json(['status' => 'ok']);
    }

    /**
     * Validates Telegram WebApp initData per official spec.
     *
     * @link https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app
     */
    private function validateTelegramInitData(string $initData, string $botToken): bool
    {
        if ($initData === '' || $botToken === '') {
            return false;
        }

        // Разбираем оригинальные пары "key=value" без порчи формата
        // parse_str превращает "+" в пробелы — это ломает подпись.
        // Поэтому разбираем вручную.
        $pairs = explode('&', $initData);
        $params = [];
        $hash = null;

        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }

            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                // ключ без значения — пропускаем
                continue;
            }

            $rawKey = substr($pair, 0, $eqPos);
            $rawVal = substr($pair, $eqPos + 1);

            // URL-decode по правилам Telegram (rawurldecode, чтобы "+" не превращался в пробел)
            $key = rawurldecode($rawKey);
            $val = rawurldecode($rawVal);

            if ($key === 'hash') {
                $hash = $val;
                continue;
            }

            $params[$key] = $val;
        }

        if (!$hash) {
            return false;
        }

        // Сортировка по ключу (ASCII)
        ksort($params);

        // Формируем data_check_string в виде "key=value" на каждой строке
        // Значения — уже URL-decoded (как требует спецификация).
        $dataCheckLines = [];
        foreach ($params as $k => $v) {
            $dataCheckLines[] = $k . '=' . $v;
        }
        $dataCheckString = implode("\n", $dataCheckLines);

        // Секретный ключ — sha256(BOT_TOKEN) в бинарном виде
        $secretKey = hash('sha256', $botToken, true);

        // Вычисляем HMAC-SHA256(data_check_string, secretKey) -> hex lower
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        // Для отладки:
        Log::debug('initData params sorted', $params);
        Log::debug('dataCheckString', ['s' => $dataCheckString]);
        Log::debug('calculatedHash', ['h' => $calculatedHash]);
        Log::debug('receivedHash', ['h' => $hash]);

        // Сравниваем подписи
        return hash_equals($calculatedHash, $hash);
    }
}
