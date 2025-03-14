<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::prefix('admin')->middleware('auth')->group(function () {

    Route::prefix('/profile')->group(function () {
        Route::get('', [App\Http\Controllers\UsersController::class, 'profile'])->name('profile');
        Route::put('', [App\Http\Controllers\UsersController::class, 'profileUpdate'])->name('profile.update');
    });

    Route::prefix('/users')->group(function () {
        Route::get('', [App\Http\Controllers\UsersController::class, 'index'])
            ->can('viewAny', User::class)->name('users.index');

        Route::get('/create', [App\Http\Controllers\UsersController::class, 'create'])
            ->can('create', User::class)->name('users.create');

        Route::post('/store', [App\Http\Controllers\UsersController::class, 'store'])
            ->can('create', User::class)->name('users.store');

        Route::get('/{user}/edit', [App\Http\Controllers\UsersController::class, 'edit'])
            ->can('update', 'user')->name('users.edit');

        Route::put('/update/{user}', [App\Http\Controllers\UsersController::class, 'update'])
            ->can('update', 'user')->name('users.update');

        Route::delete('/delete/{user}', [App\Http\Controllers\UsersController::class, 'destroy'])
            ->can('delete', 'user')->name('users.destroy');
    });

});
