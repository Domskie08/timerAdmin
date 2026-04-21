<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAppUpdateRequest;
use App\Models\AppUpdate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppUpdateController extends Controller
{
    public function store(StoreAppUpdateRequest $request): RedirectResponse
    {
        $package = $request->file('package');
        $originalName = pathinfo($package->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $package->getClientOriginalExtension();
        $slug = Str::slug($originalName);
        $slug = $slug !== '' ? $slug : 'timerapp-update';
        $storedName = now()->format('YmdHis').'-'.$slug.'.'.$extension;
        $path = $package->storeAs('updates', $storedName, 'public');

        DB::transaction(function () use ($path, $package, $request): void {
            AppUpdate::query()->update(['is_active' => false]);

            AppUpdate::query()->create([
                'title' => $request->string('title')->toString(),
                'version' => $request->string('version')->toString(),
                'description' => $request->string('description')->toString() ?: null,
                'file_path' => $path,
                'file_name' => $package->getClientOriginalName(),
                'file_size' => $package->getSize(),
                'is_active' => true,
                'published_at' => $request->date('published_at') ?? now(),
                'uploaded_by' => $request->user()?->id,
            ]);
        });

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'TimerApp update uploaded. Clients can detect it through the API.');
    }
}
