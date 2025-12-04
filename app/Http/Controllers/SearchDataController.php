<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CashbackService;

class SearchDataController extends Controller
{
    /**
     * Получить свежие данные для поиска
     */
    public function getFreshData($token)
    {
        $user = User::query()->where('search_token', $token)->first();
        if (!$user) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        try {
            $data = CashbackService::getAllCardWhichHavePercent($user->id);

            return response()->json([
                'success' => true,
                'data' => $data,
                'timestamp' => now()->timestamp,
                'count' => is_array($data) ? count($data) : 0
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to load data: ' . $e->getMessage()
            ], 500);
        }
    }
}
