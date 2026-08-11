<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaxLinkController extends Controller
{
    /**
     * Показывает форму привязки MAX-аккаунта.
     */
    public function show(Request $request): View
    {
        return view('profile.max_link', [
            'maxId' => $request->query('max'),
        ]);
    }

    /**
     * Сохраняет max_id в профиль пользователя.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'max_id' => ['required', 'string', 'max:32', Rule::unique('users', 'max_id')->ignore($request->user()->id)],
        ], [
            'max_id.unique' => 'Этот MAX уже привязан к другому аккаунту.',
        ]);

        $request->user()->update([
            'max_id' => $validated['max_id'],
        ]);

        return redirect()
            ->route('profile')
            ->with('success', 'MAX привязан.');
    }
}
