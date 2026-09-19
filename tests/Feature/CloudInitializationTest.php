<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_environment_roster_initializes_once(): void
    {
        config(['association.initial_roster_base64' => base64_encode(json_encode([
            ['unit' => 1, 'dues_cents' => 31000, 'client_name' => 'Test household', 'residents' => [
                ['name' => 'Test Admin', 'email' => 'admin@example.com', 'is_admin' => true],
            ]],
        ]))]);

        $this->artisan('association:initialize --from-env')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'is_admin' => true]);
        $this->artisan('association:initialize --from-env')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_missing_environment_roster_does_not_initialize(): void
    {
        config(['association.initial_roster_base64' => null]);
        $this->artisan('association:initialize --from-env')->assertFailed();
        $this->assertDatabaseCount('households', 0);
    }
}
