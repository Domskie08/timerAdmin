<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\CoinSaleEvent;
use App\Models\DtimerMachine;
use App\Models\License;
use App\Models\LicenseRevocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientDtimerWifiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_admin_can_access_client_pages_but_not_super_admin_license_creation(): void
    {
        $client = $this->createClientUser();
        $admin = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $this->actingAs($client)
            ->get('/client/dtimer-wifi')
            ->assertOk();

        $this->actingAs($client)
            ->post('/admin/licenses', ['duration' => '1_month'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/client/dtimer-wifi')
            ->assertForbidden();
    }

    public function test_client_can_claim_license_and_link_dtimer_machine_by_mac_address(): void
    {
        $client = $this->createClientUser();
        $license = $this->createLicense(['code' => '123456789012']);

        $this->actingAs($client)
            ->post('/client/licenses/claim', [
                'license_key' => $license->code,
            ])
            ->assertRedirect(route('client.licensing'))
            ->assertSessionHas('success');

        $this->assertSame($client->client_account_id, $license->fresh()->client_account_id);

        $this->postJson('/api/v1/dtimer/machines/link', [
            'license_key' => $license->code,
            'device_id' => 'orange-pi-original',
            'device_name' => 'Shop DTimer 01',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'machine_id' => 'machine-original',
            'wifi_status' => 'online',
            'timer_status' => 'running',
            'connected_users' => 3,
            'active_sessions' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('license.device_id', 'orange-pi-original')
            ->assertJsonPath('machine.deviceName', 'Shop DTimer 01')
            ->assertJsonPath('machine.macAddress', 'AA:BB:**:**:22:33');

        $this->assertDatabaseHas('dtimer_machines', [
            'client_account_id' => $client->client_account_id,
            'license_id' => $license->id,
            'device_id' => 'orange-pi-original',
            'device_name' => 'Shop DTimer 01',
            'connected_users' => 3,
            'active_sessions' => 2,
        ]);
    }

    public function test_same_mac_can_relink_after_sd_card_changes_device_id(): void
    {
        $client = $this->createClientUser();
        $license = $this->createLicense([
            'code' => '123456789012',
            'client_account_id' => $client->client_account_id,
        ]);

        $this->linkMachine($license, 'orange-pi-original', 'AA:BB:CC:11:22:33')
            ->assertOk();

        $this->linkMachine($license, 'orange-pi-after-sd-reinstall', 'AA:BB:CC:11:22:33')
            ->assertOk()
            ->assertJsonPath('license.device_id', 'orange-pi-after-sd-reinstall');

        $this->assertSame('orange-pi-after-sd-reinstall', $license->fresh()->device_id);

        $this->linkMachine($license, 'different-hardware', 'AA:BB:CC:44:55:66')
            ->assertStatus(409)
            ->assertJsonPath('status', 'license_in_use');
    }

    public function test_coin_sales_batch_is_idempotent_per_machine(): void
    {
        $client = $this->createClientUser();
        $license = $this->createLicense([
            'code' => '123456789012',
            'client_account_id' => $client->client_account_id,
        ]);

        $this->linkMachine($license, 'orange-pi-original', 'AA:BB:CC:11:22:33')
            ->assertOk();

        $payload = [
            'license_key' => $license->code,
            'device_secret' => $license->fresh()->device_secret,
            'device_id' => 'orange-pi-original',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'events' => [
                [
                    'local_event_id' => 'coin-001',
                    'occurred_at' => '2026-07-22 10:00:00',
                    'amount_minor' => 500,
                    'currency' => 'PHP',
                    'pulse_count' => 5,
                ],
                [
                    'local_event_id' => 'coin-002',
                    'occurred_at' => '2026-07-22 10:05:00',
                    'amount_minor' => 1000,
                    'currency' => 'PHP',
                    'pulse_count' => 10,
                ],
            ],
        ];

        $this->postJson('/api/v1/dtimer/coin-sales/batch', $payload)
            ->assertOk()
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('duplicates', 0);

        $this->postJson('/api/v1/dtimer/coin-sales/batch', $payload)
            ->assertOk()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('duplicates', 2);

        $this->assertSame(2, CoinSaleEvent::query()->count());
        $this->assertSame(1500, (int) CoinSaleEvent::query()->sum('amount_minor'));
    }

    public function test_revocation_unlinks_machine_after_thirty_days(): void
    {
        Carbon::setTestNow('2026-07-22 10:00:00');

        try {
            $client = $this->createClientUser();
            $license = $this->createLicense([
                'code' => '123456789012',
                'client_account_id' => $client->client_account_id,
            ]);

            $this->linkMachine($license, 'orange-pi-original', 'AA:BB:CC:11:22:33')
                ->assertOk();

            $this->actingAs($client)
                ->post("/client/licenses/{$license->id}/revocations", [
                    'reason' => 'Damaged Orange Pi',
                ])
                ->assertRedirect(route('client.licensing'))
                ->assertSessionHas('success');

            $this->assertDatabaseHas('license_revocations', [
                'license_id' => $license->id,
                'status' => LicenseRevocation::STATUS_PENDING,
            ]);

            $this->linkMachine($license, 'replacement-before-window', 'AA:BB:CC:44:55:66')
                ->assertStatus(409);

            Carbon::setTestNow('2026-08-21 10:01:00');
            $this->artisan('dtimer:process-revocations')
                ->assertExitCode(0);

            $this->assertNull($license->fresh()->device_id);
            $this->assertNull(DtimerMachine::query()->where('device_id', 'orange-pi-original')->first()?->license_id);

            $this->linkMachine($license, 'replacement-after-window', 'AA:BB:CC:44:55:66')
                ->assertOk()
                ->assertJsonPath('license.device_id', 'replacement-after-window');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createClientUser(): User
    {
        $account = ClientAccount::query()->create([
            'name' => 'Arcade Client',
            'contact_email' => 'client@example.com',
        ]);

        return User::query()->create([
            'name' => 'Client Admin',
            'email' => 'client@example.com',
            'password' => 'secret-password',
            'is_admin' => false,
            'client_account_id' => $account->id,
        ]);
    }

    private function createLicense(array $attributes = []): License
    {
        return License::query()->create([
            'code' => '123456789012',
            'product_type' => License::TYPE_PISO_WIFI,
            'duration' => '1_month',
            'expires_at' => now()->addMonth()->toDateString(),
            ...$attributes,
        ]);
    }

    private function linkMachine(License $license, string $deviceId, string $macAddress)
    {
        return $this->postJson('/api/v1/dtimer/machines/link', [
            'license_key' => $license->code,
            'device_id' => $deviceId,
            'device_name' => 'Shop DTimer 01',
            'mac_address' => $macAddress,
            'machine_id' => 'machine-guid',
        ]);
    }
}
