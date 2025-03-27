<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUsersRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UsersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $data = array();
        $data['users'] = User::query()->paginate(10);
        $data['title'] = 'Пользователи';

        return view('users.index', $data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('users.create', [
            'title' => 'Добавить пользователя',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUsersRequest $request): RedirectResponse
    {
        $validate = $request->safe()->toArray();
        User::query()->create($validate);

        return redirect()->route('users.index')->with('success', 'Пользователь добавлен');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user): View
    {
        return view('users.edit', [
            'title' => 'Изменить пользователя',
            'user' => $user,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->saved($request, $user);

        return redirect()->route('users.index')->with('success', 'Изменения сохранены.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): RedirectResponse
    {
        User::query()->findOrFail($user->id)->delete();

        return redirect()->route('users.index')->with('success', 'Пользователь удален');
    }

    public function profile(): View
    {
        return view('users.profile', [
            'title' => 'Профиль',
            'user' => auth()->user()
        ]);
    }

    public function profileUpdate(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $this->saved($request, $user);

        return redirect()->route('profile')->with('success', 'Изменения сохранены.');
    }

    public function generateSearchToken(): RedirectResponse
    {
        $user = auth()->user();
        $user->search_token = Str::random();
        $user->save();

        return redirect()->route('profile')->with('success', 'Новый код персональной ссылки сгенерирован.');
    }

    private function saved(Request $request, User $user): void
    {
        $rules = [
            'name' => 'required|string|min:2|max:255',
            'email' => 'required|email|max:255'
        ];

        if ($request->filled('password')) {
            $rules['password'] = 'required|string|min:6';
        }

        $validatedData = $request->validate($rules);
        $user->update($validatedData);

        if ($request->filled('password')) {
            $user->password = bcrypt($validatedData['password']);
            $user->save();
        }
    }
}
