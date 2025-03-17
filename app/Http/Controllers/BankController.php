<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Bank::class);

        return view('banks.index', [
            'title' => 'Банки',
            'banks' => Bank::query()->where('user_id', auth()->user()->id)->paginate(10)
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('banks.create', [
            'title' => 'Добавить банк'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255|min:2',
        ]);

        Bank::query()->create([
            'title' => $request->title,
            'user_id' => auth()->user()->id,
        ]);

        return redirect()->route('banks.index')->with('success', 'Банк добавлен');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Bank $bank): View
    {
        return view('banks.edit', [
            'title' => 'Изменить банк',
            'bank' => $bank,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Bank $bank): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255|min:2',
        ]);

        $bank->update([
            'title' => $request->title,
        ]);

        return redirect()->route('banks.index')->with('success', 'Изменения сохранены.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bank $bank): RedirectResponse
    {
        $bank->delete();

        return redirect()->route('banks.index')->with('success', 'Банк удален');
    }
}
