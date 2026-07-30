<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppUpdateDownloadMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_attach_external_download_mirror_to_update(): void
    {
        config(['timer.philippine_time_url' => null]);

        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $mirrorUrl = 'https://drive.google.com/file/d/example-release/view?usp=sharing';

        $this->actingAs($admin)
            ->post('/admin/updates', [
                'product_type' => AppUpdate::TYPE_TIMER_APP,
                'version' => '1.2.0',
                'title' => 'TimerApp 1.2.0',
                'description' => 'Adds a faster download mirror.',
                'external_download_url' => $mirrorUrl,
            ])
            ->assertRedirect(route('admin.setup'))
            ->assertSessionHas('success');

        $update = AppUpdate::query()->first();

        $this->assertNotNull($update);
        $this->assertSame(AppUpdate::TYPE_TIMER_APP, $update->product_type);
        $this->assertSame($mirrorUrl, $update->external_download_url);
        $this->assertSame('external-download', $update->file_path);
        $this->assertSame('Timer App Google Drive download', $update->file_name);
        $this->assertSame(0, $update->file_size);

        $update->forceFill(['published_at' => now()->subMinute()])->save();

        $this->getJson('/api/v1/updates/latest')
            ->assertOk()
            ->assertJsonPath('product', AppUpdate::TYPE_TIMER_APP)
            ->assertJsonPath('has_update', true)
            ->assertJsonPath('update.productType', AppUpdate::TYPE_TIMER_APP)
            ->assertJsonPath('update.externalDownloadUrl', $mirrorUrl)
            ->assertJsonPath('update.downloadUrl', route('api.v1.updates.download', $update));

        $this->get(route('api.v1.updates.download', $update))
            ->assertRedirect($mirrorUrl);
    }

    public function test_publishing_one_product_does_not_deactivate_the_other_product(): void
    {
        config(['timer.philippine_time_url' => null]);

        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $wifi = AppUpdate::query()->create([
            'product_type' => AppUpdate::TYPE_DTIMER_WIFI,
            'title' => 'DTimer WiFi 1.0.3',
            'version' => '1.0.3',
            'file_path' => 'external-download',
            'file_name' => 'DTimer WiFi Google Drive download',
            'external_download_url' => 'https://drive.google.com/file/d/wifi/view',
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)->post('/admin/updates', [
            'product_type' => AppUpdate::TYPE_TIMER_APP,
            'version' => '0.0.4',
            'title' => 'Timer App 0.0.4',
            'external_download_url' => 'https://drive.google.com/file/d/timer/view',
        ])->assertRedirect(route('admin.setup'));

        $this->assertTrue($wifi->fresh()->is_active);
        $this->assertTrue(
            AppUpdate::query()
                ->forProduct(AppUpdate::TYPE_TIMER_APP)
                ->where('version', '0.0.4')
                ->firstOrFail()
                ->is_active
        );
    }
}
