<?php

namespace Database\Seeders;

use App\Models\User;
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
        $defaultPassword = Hash::make('password');

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test Admin',
                'password' => $defaultPassword,
                'role' => User::ROLE_ADMIN,
                'can_upload_csv' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'planner@example.com'],
            [
                'name' => 'Planner Demo',
                'password' => $defaultPassword,
                'role' => User::ROLE_PLANNER,
                'can_upload_csv' => false,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'viewer@example.com'],
            [
                'name' => 'Viewer Demo',
                'password' => $defaultPassword,
                'role' => User::ROLE_VIEWER,
                'can_upload_csv' => false,
                'email_verified_at' => now(),
            ]
        );
    }
}
