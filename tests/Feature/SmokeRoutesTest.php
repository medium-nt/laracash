<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
 * Smoke: каждый GET-маршрут без параметров должен отвечать 200/редиректом под админом.
 * Сгенерирован скиллом laravel-test-kit; исключения — в $skip с комментарием.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create([
        'role_id' => Role::query()->where('name', 'admin')->first()->id,
    ]);
});

test('все GET-маршруты без параметров отвечают 200 или редиректом под админом', function () {
    $skip = [
        // Мёртвый маршрут: метод optimizeCashback не существует в CashbackController (500).
        // Фича оптимизации кешбэка не реализована — при реализации убрать из skip.
        'cashback/cashback/optimize' => 'мёртвый маршрут: CashbackController@optimizeCashback не существует',
    ];

    $routes = collect(Route::getRoutes()->getRoutes())->filter(function ($route) use ($skip) {
        return in_array('GET', $route->methods())
            && ! str_contains($route->uri(), '{')
            && ! isset($skip[$route->uri()]);
    });

    expect($routes->count())->toBeGreaterThan(0, 'Smoke не нашёл ни одного GET-маршрута — проверь $skip');

    $failed = [];
    foreach ($routes as $route) {
        $url = '/'.$route->uri();
        $status = $this->actingAs($this->admin)->get($url)->getStatusCode();

        if (! in_array($status, [200, 301, 302])) {
            $failed[] = "GET {$url} вернул {$status}";
        }
    }

    expect($failed)->toBe([]);
});
