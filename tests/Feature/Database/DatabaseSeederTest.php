<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_default_operational_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $planner = User::query()->where('email', 'planner@example.com')->firstOrFail();
        $viewer = User::query()->where('email', 'viewer@example.com')->firstOrFail();

        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue($admin->can_upload_csv);
        $this->assertTrue(Hash::check('password', $admin->password));

        $this->assertSame(User::ROLE_PLANNER, $planner->role);
        $this->assertFalse($planner->can_upload_csv);

        $this->assertSame(User::ROLE_VIEWER, $viewer->role);
        $this->assertFalse($viewer->can_upload_csv);
    }
}
