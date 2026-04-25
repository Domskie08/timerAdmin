<?php

namespace App\Http\Controllers;

use App\Models\AppUpdate;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    public function index(): Response
    {
        $updates = AppUpdate::query()
            ->published()
            ->latest('published_at')
            ->get()
            ->map(fn (AppUpdate $update): array => $update->toPublicArray())
            ->values();

        return Inertia::render('SupportPage', [
            'updates' => $updates,
        ]);
    }
}
