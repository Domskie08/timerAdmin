<?php

namespace Tests\Feature;

use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_revoke_from_bound_machine_releases_license_for_another_machine(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ])->assertOk();

        $this->postJson('/api/v1/licenses/revoke', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'available');

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-02',
            'machine_id' => 'machine-other',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('license.machine_id', 'machine-other');
    }

    public function test_legacy_license_without_machine_id_can_be_revoked_by_matching_device_name(): void
    {
        $license = $this->createLicense([
            'device_name' => 'OFFICE-PC-01',
            'activated_at' => now(),
        ]);

        $this->postJson('/api/v1/licenses/revoke', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-original',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'available');

        $this->assertDatabaseHas('licenses', [
            'code' => $license->code,
            'device_name' => null,
            'machine_id' => null,
        ]);
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
            'expires_at' => now()->addMonth()->toDateString(),
            ...$attributes,
        ]);
    }
}
