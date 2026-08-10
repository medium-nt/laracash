<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BotLinkController extends Controller
{
    /**
     * Показывает форму привязки Telegram аккаунта.
     */
    public function show(Request $request): View
    {
        return view('profile.bot_link', [
            'tg' => $request->query('tg'),
        ]);
    }

    /**
     * Сохраняет telegram_id в профиль пользователя.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_id' => ['required', 'string', 'max:32', Rule::unique('users', 'telegram_id')->ignore($request->user()->id)],
        ], [
            'telegram_id.unique' => 'Этот Telegram уже привязан к другому аккаунту.',
        ]);

        $request->user()->update([
            'telegram_id' => $validated['telegram_id'],
        ]);

        return redirect()
            ->route('profile')
            ->with('success', 'Telegram привязан.');
    }
}
