<?php

declare(strict_types=1);

use App\Models\Bank;
use App\Models\Card;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'user']);
    Role::firstOrCreate(['name' => 'admin']);
});

test('команда cashback-image:gc зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('cashback-image:gc');
});

test('cashback-image:gc удаляет только файлы-сироты старше N часов', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $bank = Bank::create(['user_id' => $user->id, 'title' => 'B']);
    Card::create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'number' => '1',
        'color' => '#000000',
        'cashback_image' => 'used.jpg',
    ]);

    $disk = Storage::disk('public');
    $disk->put('card_cashback_image/used.jpg', 'x');
    $disk->put('card_cashback_image/orphan.jpg', 'x');

    // Сирота должен быть старше порога (по умолчанию 1 час)
    $old = now()->subHours(2)->getTimestamp();
    touch($disk->path('card_cashback_image/orphan.jpg'), $old);

    Artisan::call('cashback-image:gc');

    expect($disk->exists('card_cashback_image/used.jpg'))->toBeTrue()
        ->and($disk->exists('card_cashback_image/orphan.jpg'))->toBeFalse();
});
