<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Тестер',
            'email' => '1@1.ru',
            'password' => Hash::make('11111111'),
            'role_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        User::factory(10)->create();
    }
}
