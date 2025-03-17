<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Bank::query()->create([
            'title' => 'Сбербанк',
            'user_id' => '1',
        ]);

        Bank::query()->create([
            'title' => 'Альфа-банк',
            'user_id' => '1',
        ]);

        Bank::query()->create([
            'title' => 'ВТБ',
            'user_id' => '1',
        ]);
    }
}
