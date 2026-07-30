<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppUpdateIndexApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_published_updates_and_can_filter_to_newer_versions(): void
    {
        $older = $this->createUpdate('1.0.0', now()->subDays(2));
        $newer = $this->createUpdate('1.2.0', now()->subDay());
        $future = $this->createUpdate('2.0.0', now()->addDay());

        $this->getJson('/api/v1/updates')
            ->assertOk()
            ->assertJsonPath('has_updates', true)
            ->assertJsonCount(2, 'updates')
            ->assertJsonPath('updates.0.id', $newer->id)
            ->assertJsonPath('updates.1.id', $older->id)
            ->assertJsonMissing(['id' => $future->id]);

        $this->getJson('/api/v1/updates?current_version=1.0.0&only_newer=1')
            ->assertOk()
            ->assertJsonPath('current_version', '1.0.0')
            ->assertJsonCount(1, 'updates')
            ->assertJsonPath('updates.0.version', '1.2.0');
    }

    private function createUpdate(string $version, mixed $publishedAt): AppUpdate
    {
        return AppUpdate::query()->create([
            'title' => "DTimer {$version}",
            'version' => $version,
            'description' => "Release {$version}",
            'file_path' => 'external-download',
            'file_name' => "dtimer-orange-pi_{$version}_all.deb",
            'file_size' => 0,
            'external_download_url' => "https://downloads.example.com/{$version}",
            'is_active' => true,
            'published_at' => $publishedAt,
        ]);
    }
}
