<?php

namespace Database\Seeders;

use App\Models\NewsPost;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@timerapp.local'],
            [
                'name' => 'Timer Admin',
                'password' => 'changeme123',
                'is_admin' => true,
            ]
        );

        NewsPost::query()->updateOrCreate(
            ['title' => 'TimerAdmin portal is live'],
            [
                'body' => 'Welcome to the new license center. Admins can create activator codes, upload TimerApp updates, and publish news here.',
                'published_at' => now(),
                'is_pinned' => true,
                'created_by' => $admin->id,
            ]
        );
    }
}
