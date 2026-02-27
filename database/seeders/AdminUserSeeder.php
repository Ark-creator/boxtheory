<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'istorya707@gmail.com'], // Checks if this user exists
            [
                'name' => 'Main Admin',
                'password' => Hash::make('Password123'),
                'role' => 'admin',
                'is_approved' => true,
            ]
        );
    }
}