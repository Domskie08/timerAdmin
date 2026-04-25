<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppUpdate;
use App\Models\DashboardPhoto;
use App\Models\License;
use App\Models\NewsPost;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $licenses = License::query()
            ->latest()
            ->get();

        $availableLicenses = $licenses->filter(fn (License $license): bool => $license->status()->value === 'Available')->count();
        $activeDevices = $licenses->filter(fn (License $license): bool => $license->status()->value === 'Active')->count();
        $inactiveDevices = $licenses->filter(fn (License $license): bool => $license->status()->value === 'Inactive')->count();
        $expiredLicenses = $licenses->filter(fn (License $license): bool => $license->status()->value === 'Expired')->count();

        return Inertia::render('admin/DashboardPage', [
            'stats' => [
                'totalLicenses' => $licenses->count(),
                'availableLicenses' => $availableLicenses,
                'activeDevices' => $activeDevices,
                'inactiveDevices' => $inactiveDevices,
                'expiredLicenses' => $expiredLicenses,
                'activeWindowMinutes' => config('timer.active_window_minutes'),
            ],
            'licenseDurations' => License::durationOptions(),
            'defaultLicenseDuration' => License::defaultDuration(),
            'licenses' => $licenses
                ->map(fn (License $license): array => $license->toAdminArray())
                ->values(),
            'news' => NewsPost::query()
                ->with('creator:id,name')
                ->latest('published_at')
                ->get()
                ->map(fn (NewsPost $post): array => $post->toAdminArray())
                ->values(),
            'updates' => AppUpdate::query()
                ->with('uploader:id,name')
                ->latest('published_at')
                ->get()
                ->map(fn (AppUpdate $update): array => $update->toAdminArray())
                ->values(),
            'dashboardPhotos' => DashboardPhoto::query()
                ->with('uploader:id,name')
                ->orderBy('position')
                ->latest()
                ->get()
                ->map(fn (DashboardPhoto $photo): array => $photo->toAdminArray())
                ->values(),
        ]);
    }
}
