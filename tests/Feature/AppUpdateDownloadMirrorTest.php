<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppUpdateDownloadMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_attach_external_download_mirror_to_update(): void
    {
        Storage::fake('public');
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
                'version' => '1.2.0',
                'title' => 'TimerApp 1.2.0',
                'description' => 'Adds a faster download mirror.',
                'external_download_url' => $mirrorUrl,
                'package' => UploadedFile::fake()->create('TimerApp-1.2.0.zip', 512, 'application/zip'),
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success');

        $update = AppUpdate::query()->first();

        $this->assertNotNull($update);
        $this->assertSame($mirrorUrl, $update->external_download_url);

        $update->forceFill(['published_at' => now()->subMinute()])->save();

        $this->getJson('/api/v1/updates/latest')
            ->assertOk()
            ->assertJsonPath('has_update', true)
            ->assertJsonPath('update.externalDownloadUrl', $mirrorUrl)
            ->assertJsonPath('update.downloadUrl', route('api.v1.updates.download', $update));
    }
}
