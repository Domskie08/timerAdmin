<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\CoinSaleEvent;
use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LicenseDeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_sticks_license_to_original_device_id(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'device_id' => 'device-original',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('license.device_id', 'device-original')
            ->assertJsonPath('device_secret', $license->fresh()->device_secret)
            ->assertJsonPath('license.device_secret', $license->fresh()->device_secret);

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'device_id' => 'device-other',
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'in_use');

        $this->assertDatabaseHas('licenses', [
            'code' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'device_id' => 'device-original',
            'machine_id' => null,
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
                'device_id' => 'device-original',
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

    public function test_status_rejects_same_device_name_with_different_device_id(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'device_id' => 'device-original',
        ])->assertOk();

        $this->postJson('/api/v1/licenses/status', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'device_id' => 'device-other',
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This device is not linked to the supplied license.');
    }

    public function test_status_ignores_machine_id_for_pc_timer_license(): void
    {
        $license = $this->createLicense([
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
            'activated_at' => now(),
        ]);

        $this->postJson('/api/v1/licenses/status', [
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'machine-other',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('license.device_id', 'device-original')
            ->assertJsonMissingPath('license.machine_id')
            ->assertJsonMissingPath('license.machineId');

        $this->assertNull($license->fresh()->machine_id);
    }

    public function test_status_can_resolve_license_by_device_id_without_license_key(): void
    {
        $license = $this->createLicense([
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
            'activated_at' => now(),
        ]);

        $this->postJson('/api/v1/licenses/status', [
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('license.device_id', 'device-original')
            ->assertJsonMissingPath('license.machine_id')
            ->assertJsonMissingPath('license.machineId')
            ->assertJsonPath('license.license_key', $license->code);

        $this->assertDatabaseHas('licenses', [
            'code' => $license->code,
            'device_id' => 'device-original',
        ]);

        $this->assertNotNull($license->fresh()->last_seen_at);
    }

    public function test_status_returns_frozen_for_unactivated_license_without_starting_expiry(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/status', [
            'license_key' => $license->code,
            'device_id' => 'device-original',
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
        $this->assertNull($freshLicense->device_id);
        $this->assertNull($freshLicense->device_name);
        $this->assertNull($freshLicense->machine_id);
        $this->assertNull($freshLicense->last_seen_at);
    }

    public function test_heartbeat_can_resolve_license_by_device_id_without_license_key(): void
    {
        $license = $this->createLicense([
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
            'activated_at' => now(),
        ]);

        $this->postJson('/api/v1/licenses/heartbeat', [
            'deviceId' => 'device-original',
            'deviceName' => 'OFFICE-PC-01',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('message', 'Heartbeat received.')
            ->assertJsonPath('license.device_id', 'device-original')
            ->assertJsonMissingPath('license.machine_id')
            ->assertJsonMissingPath('license.machineId');

        $this->assertNotNull($license->fresh()->last_seen_at);
    }

    public function test_heartbeat_keeps_pre_provisioned_license_frozen_until_activation(): void
    {
        $license = $this->createLicense([
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
        ]);

        $this->postJson('/api/v1/licenses/heartbeat', [
            'deviceId' => 'device-original',
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
        $this->assertSame('device-original', $freshLicense->device_id);
        $this->assertSame('OFFICE-PC-01', $freshLicense->device_name);
        $this->assertNull($freshLicense->machine_id);
        $this->assertNull($freshLicense->last_seen_at);
    }

    public function test_pc_timer_coin_sales_batch_is_idempotent_per_license_device(): void
    {
        $account = ClientAccount::query()->create([
            'name' => 'Arcade Client',
            'contact_email' => 'client@example.com',
        ]);
        $license = $this->createLicense([
            'client_account_id' => $account->id,
        ]);

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'Counter Timer 01',
            'device_id' => 'coin-device-001',
        ])->assertOk();

        $payload = [
            'license_key' => $license->code,
            'device_secret' => $license->fresh()->device_secret,
            'device_id' => 'coin-device-001',
            'device_name' => 'Counter Timer 01',
            'events' => [
                [
                    'local_event_id' => 'pc-coin-001',
                    'occurred_at' => '2026-07-22 10:00:00',
                    'amount_minor' => 500,
                    'currency' => 'PHP',
                    'pulse_count' => 5,
                ],
                [
                    'local_event_id' => 'pc-coin-002',
                    'occurred_at' => '2026-07-22 10:05:00',
                    'amount_minor' => 1000,
                    'currency' => 'PHP',
                    'pulse_count' => 10,
                ],
            ],
        ];

        $this->postJson('/api/v1/licenses/coin-sales/batch', $payload)
            ->assertOk()
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('duplicates', 0);

        $this->postJson('/api/v1/licenses/coin-sales/batch', $payload)
            ->assertOk()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('duplicates', 2);

        $this->assertSame(2, CoinSaleEvent::query()->count());
        $this->assertSame(1500, (int) CoinSaleEvent::query()->sum('amount_minor'));
        $this->assertDatabaseHas('coin_sale_events', [
            'license_id' => $license->id,
            'dtimer_machine_id' => null,
            'product_type' => License::TYPE_PC_TIMER,
            'local_event_id' => 'pc-coin-001',
        ]);
    }

    public function test_consumed_renewal_license_cannot_be_activated(): void
    {
        $targetLicense = $this->createLicense([
            'code' => '123456789012',
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
            'activated_at' => now(),
        ]);

        $renewalLicense = $this->createLicense([
            'code' => '999999999999',
            'consumed_by_license_id' => $targetLicense->id,
            'consumed_at' => now(),
        ]);

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $renewalLicense->code,
            'device_name' => 'OFFICE-PC-02',
            'device_id' => 'device-other',
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This license code has already been consumed for renewal.');
    }

    public function test_status_without_matching_license_key_or_device_id_returns_inactive(): void
    {
        $this->postJson('/api/v1/licenses/status', [
            'device_id' => 'missing-device',
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
            'device_id' => 'device-original',
            'device_name' => 'OFFICE-PC-01',
            'activated_at' => now(),
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/v1/licenses/status', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'device_id' => 'device-original',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('message', 'Please buy license to activate some feature.');
    }

    public function test_device_id_is_required(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_id');
    }

    public function test_machine_id_alone_does_not_satisfy_device_id_requirement(): void
    {
        $license = $this->createLicense();

        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $license->code,
            'device_name' => 'OFFICE-PC-01',
            'machine_id' => 'legacy-machine-id',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_id');
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
