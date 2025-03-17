<?php

namespace Database\Seeders;

use App\Models\Card;
use Illuminate\Database\Seeder;

class CardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Card::query()->create([
            'user_id' => '1',
            'bank_id' => '1',
            'number' => '*1234',
            'color' => '#00b30c',
        ]);

        Card::query()->create([
            'user_id' => '1',
            'bank_id' => '2',
            'number' => '*9876',
            'color' => '#d21e1e',
        ]);

        Card::query()->create([
            'user_id' => '1',
            'bank_id' => '3',
            'number' => '*5555',
            'color' => '#007bff',
        ]);
    }
}
