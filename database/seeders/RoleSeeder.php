<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::create(
            ['name' => 'client']
        );

        Role::create(
            ['name' => 'admin']
        );
    }
}
