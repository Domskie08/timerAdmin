<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppUpdate;
use App\Models\DashboardPhoto;
use App\Models\NewsPost;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/SetupPage', [
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
