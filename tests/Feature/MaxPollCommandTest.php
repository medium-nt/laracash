<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('команда max:poll зарегистрирована', function () {
    expect(array_keys(Artisan::all()))->toContain('max:poll');
});
