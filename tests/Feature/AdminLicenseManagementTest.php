<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminLicenseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_frozen_license_from_dashboard(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/licenses', [
                'duration' => '3_months',
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success');

        $license = License::query()->latest('id')->first();

        $this->assertNotNull($license);
        $this->assertSame('3_months', $license->duration);
        $this->assertNull($license->activated_at);
        $this->assertIsString($license->device_secret);
        $this->assertSame(64, strlen($license->device_secret));
        $this->assertSame('Frozen', $license->status()->value);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSeeText($license->device_secret);
    }

    public function test_admin_can_create_lifetime_dtimer_wifi_license_without_duration(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/licenses/pisowifi')
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success');

        $license = License::query()->latest('id')->first();

        $this->assertNotNull($license);
        $this->assertSame(License::TYPE_PISO_WIFI, $license->product_type);
        $this->assertNull($license->duration);
        $this->assertNull($license->expires_at);
        $this->assertTrue($license->isLifetimeLicense());
    }

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
            'duration' => '1_month',
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

    public function test_admin_can_consume_new_license_code_to_renew_a_bound_license(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00');

        try {
            $admin = User::query()->create([
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => 'secret-password',
                'is_admin' => true,
            ]);

            $targetLicense = License::query()->create([
                'code' => '123456789012',
                'duration' => '1_month',
                'expires_at' => '2026-06-15',
                'device_id' => 'dtimer-device-001',
                'device_name' => 'DTIMER-01',
                'machine_id' => 'machine-001',
                'activated_at' => Carbon::parse('2026-05-01 09:00:00'),
            ]);

            $renewalLicense = License::query()->create([
                'code' => '999999999999',
                'duration' => '3_months',
                'expires_at' => '2026-08-10',
            ]);

            $this->actingAs($admin)
                ->post("/admin/licenses/{$targetLicense->id}/renew", [
                    'renew_license_code' => $renewalLicense->code,
                    'target_license_id' => $targetLicense->id,
                ])
                ->assertRedirect(route('admin.dashboard'))
                ->assertSessionHas('success');

            $this->assertSame('2026-09-15', $targetLicense->fresh()->expires_at?->toDateString());
            $this->assertSame($targetLicense->id, $renewalLicense->fresh()->consumed_by_license_id);
            $this->assertNotNull($renewalLicense->fresh()->consumed_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_renew_an_expired_bound_license_from_today(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00');

        try {
            $admin = User::query()->create([
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => 'secret-password',
                'is_admin' => true,
            ]);

            $targetLicense = License::query()->create([
                'code' => '123456789012',
                'duration' => '1_month',
                'expires_at' => '2026-05-01',
                'device_id' => 'dtimer-device-001',
                'device_name' => 'DTIMER-01',
                'machine_id' => 'machine-001',
                'activated_at' => Carbon::parse('2026-04-01 09:00:00'),
            ]);

            $renewalLicense = License::query()->create([
                'code' => '999999999999',
                'duration' => '1_month',
                'expires_at' => '2026-06-10',
            ]);

            $this->actingAs($admin)
                ->post("/admin/licenses/{$targetLicense->id}/renew", [
                    'renew_license_code' => $renewalLicense->code,
                    'target_license_id' => $targetLicense->id,
                ])
                ->assertRedirect(route('admin.dashboard'))
                ->assertSessionHas('success');

            $this->assertSame('2026-06-10', $targetLicense->fresh()->expires_at?->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_renew_a_bound_license_even_before_activation(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00');

        try {
            $admin = User::query()->create([
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => 'secret-password',
                'is_admin' => true,
            ]);

            $targetLicense = License::query()->create([
                'code' => '123456789012',
                'duration' => '1_month',
                'expires_at' => '2026-06-15',
                'device_id' => 'dtimer-device-001',
                'device_name' => 'DTIMER-01',
                'machine_id' => 'machine-001',
                'activated_at' => null,
            ]);

            $renewalLicense = License::query()->create([
                'code' => '999999999999',
                'duration' => '1_month',
                'expires_at' => '2026-06-10',
            ]);

            $this->actingAs($admin)
                ->post("/admin/licenses/{$targetLicense->id}/renew", [
                    'renew_license_code' => $renewalLicense->code,
                    'target_license_id' => $targetLicense->id,
                ])
                ->assertRedirect(route('admin.dashboard'))
                ->assertSessionHas('success');

            $this->assertSame('2026-07-15', $targetLicense->fresh()->expires_at?->toDateString());
            $this->assertSame($targetLicense->id, $renewalLicense->fresh()->consumed_by_license_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_cannot_reuse_a_consumed_renew_license_code(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00');

        try {
            $admin = User::query()->create([
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => 'secret-password',
                'is_admin' => true,
            ]);

            $firstTarget = License::query()->create([
                'code' => '123456789012',
                'duration' => '1_month',
                'expires_at' => '2026-06-01',
                'device_id' => 'dtimer-device-001',
                'device_name' => 'DTIMER-01',
                'activated_at' => Carbon::parse('2026-05-01 09:00:00'),
            ]);

            $secondTarget = License::query()->create([
                'code' => '111111111111',
                'duration' => '1_month',
                'expires_at' => '2026-06-01',
                'device_id' => 'dtimer-device-002',
                'device_name' => 'DTIMER-02',
                'activated_at' => Carbon::parse('2026-05-01 09:00:00'),
            ]);

            $renewalLicense = License::query()->create([
                'code' => '999999999999',
                'duration' => '3_months',
                'expires_at' => '2026-08-10',
            ]);

            $this->actingAs($admin)
                ->post("/admin/licenses/{$firstTarget->id}/renew", [
                    'renew_license_code' => $renewalLicense->code,
                    'target_license_id' => $firstTarget->id,
                ])
                ->assertRedirect(route('admin.dashboard'))
                ->assertSessionHas('success');

            $this->actingAs($admin)
                ->post("/admin/licenses/{$secondTarget->id}/renew", [
                    'renew_license_code' => $renewalLicense->code,
                    'target_license_id' => $secondTarget->id,
                ])
                ->assertRedirect(route('admin.dashboard'))
                ->assertSessionHasErrors('renew_license_code');

            $this->assertSame('2026-06-01', $secondTarget->fresh()->expires_at?->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_cannot_renew_a_license_without_a_bound_device(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $targetLicense = License::query()->create([
            'code' => '123456789012',
            'duration' => '1_month',
            'expires_at' => '2026-06-10',
        ]);

        $renewalLicense = License::query()->create([
            'code' => '999999999999',
            'duration' => '3_months',
            'expires_at' => '2026-08-10',
        ]);

        $this->actingAs($admin)
            ->post("/admin/licenses/{$targetLicense->id}/renew", [
                'renew_license_code' => $renewalLicense->code,
                'target_license_id' => $targetLicense->id,
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('renew_license_code');

        $this->assertNull($renewalLicense->fresh()->consumed_by_license_id);
        $this->assertNull($renewalLicense->fresh()->consumed_at);
    }

    public function test_admin_cannot_renew_lifetime_dtimer_wifi_license(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $targetLicense = License::query()->create([
            'code' => '123456789012',
            'product_type' => License::TYPE_PISO_WIFI,
            'device_id' => 'dtimer-device-001',
            'device_name' => 'DTIMER-01',
            'activated_at' => now(),
        ]);

        $renewalLicense = License::query()->create([
            'code' => '999999999999',
            'product_type' => License::TYPE_PISO_WIFI,
        ]);

        $this->actingAs($admin)
            ->post("/admin/licenses/{$targetLicense->id}/renew", [
                'renew_license_code' => $renewalLicense->code,
                'target_license_id' => $targetLicense->id,
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('renew_license_code');

        $this->assertNull($renewalLicense->fresh()->consumed_by_license_id);
        $this->assertNull($renewalLicense->fresh()->consumed_at);
    }
}
