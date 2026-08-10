<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Создаём роли для тестов
    Role::create(['name' => 'client']);
    Role::create(['name' => 'admin']);
});

test('гость перенаправляется на логин', function () {
    $this->get('/profile/bot-link?tg=42')->assertRedirect('/login');
});

test('авторизованный видит страницу привязки', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/profile/bot-link?tg=42')
        ->assertOk()
        ->assertSee('Привязать');
});

test('авторизованный сохраняет telegram_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/profile/bot-link', ['telegram_id' => '42'])
        ->assertRedirect(route('profile'));
    expect($user->fresh()->telegram_id)->toBe('42');
});

test('нельзя привязать уже занятый telegram_id', function () {
    // Первый пользователь уже привязал telegram_id = 42
    $user1 = User::factory()->create(['telegram_id' => '42']);

    // Второй пользователь пытается привязать тот же telegram_id
    $user2 = User::factory()->create();

    $this->actingAs($user2)
        ->post('/profile/bot-link', ['telegram_id' => '42'])
        ->assertSessionHasErrors('telegram_id');

    // Проверяем, что у второго пользователя telegram_id не изменился
    expect($user2->fresh()->telegram_id)->toBeNull();
});
