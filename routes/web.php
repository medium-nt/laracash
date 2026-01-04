<?php

use App\Http\Controllers\FileUploadController;
use App\Models\Bank;
use App\Models\Card;
use App\Models\Cashback;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', [App\Http\Controllers\LandingController::class, 'index'])
    ->name('landing');

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::get('/search/{token}', [App\Http\Controllers\SearchController::class, 'index'])
    ->name('search.index');

Route::get('/search/{token}/manifest', [App\Http\Controllers\SearchController::class, 'manifest'])
    ->name('search.manifest');

Route::get('/api/search-data/{token}', [App\Http\Controllers\SearchDataController::class, 'getFreshData'])
    ->name('search.data.fresh');

Route::post('/upload', [FileUploadController::class, 'store'])->name('upload.store');

Route::get('/tg-app', function () {
    return view('tg-app');
});

Route::middleware('auth')->group(function () {

    Route::prefix('/profile')->group(function () {
        Route::get('', [App\Http\Controllers\UsersController::class, 'profile'])->name('profile');
        Route::put('', [App\Http\Controllers\UsersController::class, 'profileUpdate'])->name('profile.update');
        Route::get('/generate_search_token', [App\Http\Controllers\UsersController::class, 'generateSearchToken'])
            ->name('profile.generate_search_token');
    });

    Route::prefix('/categories')->group(function () {
        Route::get('', [App\Http\Controllers\CategoryController::class, 'index'])
            ->can('viewAny', Category::class)->name('categories.index');
        Route::get('/create', [App\Http\Controllers\CategoryController::class, 'create'])
            ->can('create', Category::class)->name('categories.create');
        Route::post('', [App\Http\Controllers\CategoryController::class, 'store'])
            ->can('create', Category::class)->name('categories.store');
        Route::get('/{category}/edit', [App\Http\Controllers\CategoryController::class, 'edit'])
            ->can('update', 'category')->name('categories.edit');
        Route::put('/update/{category}', [App\Http\Controllers\CategoryController::class, 'update'])
            ->can('update', 'category')->name('categories.update');
        Route::delete('/{category}', [App\Http\Controllers\CategoryController::class, 'destroy'])
            ->can('delete', 'category')->name('categories.destroy');

        Route::get('/fill', [App\Http\Controllers\CategoryController::class, 'fillInDefaultValues'])
            ->name('categories.fill_default');

        Route::post('/change_important', [App\Http\Controllers\CategoryController::class, 'changeImportant'])
            ->can('viewAny', Card::class)
            ->name('categories.change_important');
    });

    Route::prefix('/cards')->group(function () {
        Route::get('', [App\Http\Controllers\CardController::class, 'index'])
            ->can('viewAny', Card::class)->name('cards.index');
        Route::get('/create', [App\Http\Controllers\CardController::class, 'create'])
            ->can('create', Card::class)->name('cards.create');
        Route::post('', [App\Http\Controllers\CardController::class, 'store'])
            ->can('create', Card::class)->name('cards.store');
        Route::get('/{card}/edit', [App\Http\Controllers\CardController::class, 'edit'])
            ->can('update', 'card')->name('cards.edit');
        Route::put('/update/{card}', [App\Http\Controllers\CardController::class, 'update'])
            ->can('update', 'card')->name('cards.update');
        Route::delete('/{card}', [App\Http\Controllers\CardController::class, 'destroy'])
            ->can('delete', 'card')->name('cards.destroy');

        Route::post('/number_update', [App\Http\Controllers\CardController::class, 'numberUpdate'])
            ->can('viewAny', Card::class)
            ->name('cards.number_update');
    });

    Route::prefix('/cashback')->group(function () {
        Route::get('', [App\Http\Controllers\CashbackController::class, 'index'])
            ->can('viewAny', Cashback::class)
            ->name('cashback.index');

        Route::get('all_available_cashback', [App\Http\Controllers\CashbackController::class, 'allAvailableCashback'])
            ->can('viewAny', Cashback::class)
            ->name('cashback.all_available_cashback');

        Route::post('/inline-update', [App\Http\Controllers\CashbackController::class, 'inlineUpdate'])
            ->can('viewAny', Cashback::class)
            ->name('cashback.inline_update');

        Route::post('/toggle-pin', [App\Http\Controllers\CashbackController::class, 'togglePin'])
            ->can('viewAny', Cashback::class)
            ->name('cashback.toggle_pin');

        Route::get('/category/{category}/show', [App\Http\Controllers\CashbackController::class, 'categoryShow'])
            ->can('view', 'category')->name('cashback.category_show');

        Route::get('/card/{card}/edit', [App\Http\Controllers\CashbackController::class, 'cardEdit'])
            ->can('update', 'card')
            ->name('cashback.card_edit');

        Route::put('/update/{card}', [App\Http\Controllers\CashbackController::class, 'cardUpdate'])
            ->can('update', 'card')
            ->name('cashback.card_update');

        Route::put('/upload/{card}', [App\Http\Controllers\CashbackController::class, 'downloadCashbackImage'])
            ->can('update', 'card')
            ->name('cashback.download_cashback_image');

        Route::delete('/delete_cashback_image/{card}', [App\Http\Controllers\CashbackController::class, 'destroyCashbackImage'])
            ->can('delete', 'card')
            ->name('cashback.delete_cashback_image');

        Route::get('/recognize/{card}', [App\Http\Controllers\CashbackController::class, 'recognizeCashback'])
            ->can('create', 'card')
            ->name('cashback.recognize_cashback');

        // Маршруты для оптимизации кешбэков
        Route::prefix('cashback')->group(function () {
            Route::get('/optimize', [App\Http\Controllers\CashbackController::class, 'optimizeCashback'])
                ->name('cashback.optimize');

            Route::post('/apply_optimization', [App\Http\Controllers\CashbackController::class, 'applyOptimization'])
                ->name('cashback.apply_optimization');
        });
    });

    Route::prefix('/banks')->group(function () {
        Route::get('', [App\Http\Controllers\BankController::class, 'index'])
            ->can('viewAny', Bank::class)->name('banks.index');
        Route::get('/create', [App\Http\Controllers\BankController::class, 'create'])
            ->can('create', Bank::class)->name('banks.create');
        Route::post('', [App\Http\Controllers\BankController::class, 'store'])
            ->can('create', Bank::class)->name('banks.store');
        Route::get('/{bank}/edit', [App\Http\Controllers\BankController::class, 'edit'])
            ->can('update', 'bank')->name('banks.edit');
        Route::put('/update/{bank}', [App\Http\Controllers\BankController::class, 'update'])
            ->can('update', 'bank')->name('banks.update');
        Route::delete('/{bank}', [App\Http\Controllers\BankController::class, 'destroy'])
            ->can('delete', 'bank')->name('banks.destroy');
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
