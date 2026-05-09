<?php

namespace Tests\Feature;

use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LicenseDeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_sticks_license_to_original_machine_id(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('license.machine_id', 'machine-original');

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-other',
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'in_use');

        $this->assertDatabaseHas('licenses', [
            'code' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ]);
    }

    public function test_activation_starts_expiry_from_the_activation_time(): void
    {
        Carbon::setTestNow('2026-01-10 08:00:00');
        $license = $this->createLicense();

        Carbon::setTestNow('2026-03-15 09:30:00');

        try {
            $this->postJson('/api/v1/licenses/activate', [
                'license_key' => $license->code,
                'device_name' => 'OFFICE-PC-01',
                'machine_id' => 'machine-original',
            ])
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('status', 'active')
                ->assertJsonPath('license.expires_at', '2026-04-15');

            $activatedLicense = $license->fresh();

            $this->assertSame('2026-03-15 09:30:00', $activatedLicense->activated_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-04-15', $activatedLicense->expires_at?->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_status_rejects_same_device_name_with_different_machine_id(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ])->assertOk();

        $this->postJson('/api/v1/licenses/status', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-other',
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This device is not linked to the supplied license.');
    }

    public function test_status_can_resolve_license_by_machine_id_without_license_key(): void
    {
        $license = $this->createLicense([
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
            'activated_at' => now(),
        ]);

        $this->postJson('/api/v1/licenses/status', [
            'machine_id' => 'machine-original',
            'device_name' => 'OFFICE-PC-01',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('license.machine_id', 'machine-original')
            ->assertJsonPath('license.license_key', $license->code);

        $this->assertDatabaseHas('licenses', [
            'code' => $license->code,
            'machine_id' => 'machine-original',
        ]);

        $this->assertNotNull($license->fresh()->last_seen_at);
    }

    public function test_status_returns_frozen_for_unactivated_license_without_starting_expiry(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/status', [
            'license_key' => $license->code,
            'machine_id' => 'machine-original',
            'device_name' => 'OFFICE-PC-01',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'frozen')
            ->assertJsonPath('message', 'License is frozen until activated from Settings.')
            ->assertJsonPath('license.provision_status', 'not_provisioned')
            ->assertJsonPath('license.expires_at', null);

        $freshLicense = $license->fresh();

        $this->assertNull($freshLicense->activated_at);
        $this->assertNull($freshLicense->last_seen_at);
    }

    public function test_heartbeat_can_resolve_license_by_machine_id_without_license_key(): void
    {
        $license = $this->createLicense([
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
            'activated_at' => now(),
        ]);

        $this->postJson('/api/v1/licenses/heartbeat', [
            'machineId' => 'machine-original',
            'deviceName' => 'OFFICE-PC-01',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('message', 'Heartbeat received.')
            ->assertJsonPath('license.machine_id', 'machine-original');

        $this->assertNotNull($license->fresh()->last_seen_at);
    }

    public function test_heartbeat_keeps_pre_provisioned_license_frozen_until_activation(): void
    {
        $license = $this->createLicense([
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ]);

        $this->postJson('/api/v1/licenses/heartbeat', [
            'machineId' => 'machine-original',
            'deviceName' => 'OFFICE-PC-01',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'frozen')
            ->assertJsonPath('message', 'License is frozen until activated from Settings.')
            ->assertJsonPath('license.provision_status', 'provisioned')
            ->assertJsonPath('license.expires_at', null);

        $freshLicense = $license->fresh();

        $this->assertNull($freshLicense->activated_at);
        $this->assertNull($freshLicense->last_seen_at);
    }

    public function test_status_without_matching_license_key_or_machine_id_returns_inactive(): void
    {
        $this->postJson('/api/v1/licenses/status', [
            'machine_id' => 'missing-machine',
            'device_name' => 'OFFICE-PC-01',
        ])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('message', 'Please buy license to activate some feature.')
            ->assertJsonPath('license', null);
    }

    public function test_status_returns_buy_license_message_for_expired_license(): void
    {
        $license = $this->createLicense([
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
            'activated_at' => now(),
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/v1/licenses/status', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('message', 'Please buy license to activate some feature.');
    }

    public function test_machine_id_is_required(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('machine_id');
    }

    private function createLicense(array $attributes = []): License
    {
        return License::query()->create([
            'code' => '123456789012',
            'duration' => '1_month',
            'expires_at' => now()->addMonth()->toDateString(),
            ...$attributes,
        ]);
    }
}
