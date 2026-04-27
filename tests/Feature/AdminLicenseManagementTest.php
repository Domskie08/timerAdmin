<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLicenseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_license_from_dashboard(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $license = License::query()->create([
            'code' => '123456789012',
            'expires_at' => now()->addMonth()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->delete("/admin/licenses/{$license->id}")
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success', "License {$license->code} deleted successfully.");

        $this->assertDatabaseMissing('licenses', [
            'id' => $license->id,
        ]);
    }
}
