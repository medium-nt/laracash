<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'client']);
    Role::create(['name' => 'admin']);
});

test('гость перенаправляется на логин', function () {
    $this->get('/profile/max-link?max=42')->assertRedirect('/login');
});

test('авторизованный видит страницу привязки', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/profile/max-link?max=42')
        ->assertOk()
        ->assertSee('Привязать');
});

test('авторизованный сохраняет max_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/profile/max-link', ['max_id' => '42'])
        ->assertRedirect(route('profile'));
    expect($user->fresh()->max_id)->toBe('42');
});

test('нельзя привязать уже занятый max_id', function () {
    $user1 = User::factory()->create(['max_id' => '42']);
    $user2 = User::factory()->create();

    $this->actingAs($user2)
        ->post('/profile/max-link', ['max_id' => '42'])
        ->assertSessionHasErrors('max_id');

    expect($user2->fresh()->max_id)->toBeNull();
});
