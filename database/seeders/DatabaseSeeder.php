<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->admin()->create([
            'name' => 'Test Admin',
            'email' => 'test@example.com',
        ]);

        User::factory()->planner()->create([
            'name' => 'Planner Demo',
            'email' => 'planner@example.com',
        ]);

        User::factory()->viewer()->create([
            'name' => 'Viewer Demo',
            'email' => 'viewer@example.com',
        ]);
    }
}
