<?php

namespace App\Http\Controllers;

use App\Models\AppUpdate;
use App\Models\NewsPost;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        $news = NewsPost::query()
            ->publiclyVisible()
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (NewsPost $post): array => $post->toPublicArray())
            ->values();

        $latestUpdate = AppUpdate::query()
            ->published()
            ->where('is_active', true)
            ->latest('published_at')
            ->first();

        return Inertia::render('HomePage', [
            'news' => $news,
            'latestUpdate' => $latestUpdate?->toPublicArray(),
        ]);
    }
}

