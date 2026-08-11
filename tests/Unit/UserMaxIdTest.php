<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Активируем RefreshDatabase для этого файла
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__FILE__);

beforeEach(function () {
    // Создаём роли для тестов (factory использует rand(1, 2))
    Role::create(['name' => 'user']);
    Role::create(['name' => 'admin']);
});

test('user можно создать с max_id', function () {
    $user = User::factory()->create(['max_id' => '123456789']);

    expect($user->max_id)->toBe('123456789');
});

test('max_id уникален', function () {
    User::factory()->create(['max_id' => '111']);
    User::factory()->create(['max_id' => '111']);
})->throws(\Illuminate\Database\QueryException::class);
