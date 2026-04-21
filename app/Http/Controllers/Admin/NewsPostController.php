<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNewsPostRequest;
use App\Models\NewsPost;
use Illuminate\Http\RedirectResponse;

class NewsPostController extends Controller
{
    public function store(StoreNewsPostRequest $request): RedirectResponse
    {
        NewsPost::query()->create([
            'title' => $request->string('title')->toString(),
            'body' => $request->string('body')->toString(),
            'is_pinned' => $request->boolean('is_pinned'),
            'published_at' => $request->date('published_at') ?? now(),
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'News post published on the home page.');
    }
}

