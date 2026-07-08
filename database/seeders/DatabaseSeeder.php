<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        AdminUser::query()->updateOrCreate([
            'email' => 'admin@example.com',
        ], [
            'password_hash' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }
}
