<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAppUpdateRequest;
use App\Models\AppUpdate;
use App\Support\PhilippineInternetClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AppUpdateController extends Controller
{
    public function store(StoreAppUpdateRequest $request): RedirectResponse
    {
        $package = $request->file('package');
        $publishedAt = PhilippineInternetClock::now();
        $originalName = pathinfo($package->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $package->getClientOriginalExtension();
        $slug = Str::slug($originalName);
        $slug = $slug !== '' ? $slug : 'timerapp-update';
        $storedName = $publishedAt->format('YmdHis').'-'.$slug.'.'.$extension;
        $path = $package->storeAs('updates', $storedName, 'public');

        DB::transaction(function () use ($path, $package, $publishedAt, $request): void {
            AppUpdate::query()->update(['is_active' => false]);

            AppUpdate::query()->create([
                'title' => $request->string('title')->toString(),
                'version' => $request->string('version')->toString(),
                'description' => $request->string('description')->toString() ?: null,
                'file_path' => $path,
                'file_name' => $package->getClientOriginalName(),
                'file_size' => $package->getSize(),
                'is_active' => true,
                'published_at' => $publishedAt,
                'uploaded_by' => $request->user()?->id,
            ]);
        });

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'TimerApp update uploaded and published automatically using the upload time in the Philippines.');
    }

    public function destroy(AppUpdate $appUpdate): RedirectResponse
    {
        $wasActive = $appUpdate->is_active;
        $filePath = $appUpdate->file_path;
        $replacementActivated = false;

        DB::transaction(function () use ($appUpdate, $wasActive, &$replacementActivated): void {
            $appUpdate->delete();

            if (! $wasActive) {
                return;
            }

            $replacement = AppUpdate::query()
                ->published()
                ->latest('published_at')
                ->first()
                ?? AppUpdate::query()->latest('published_at')->first();

            if (! $replacement) {
                return;
            }

            AppUpdate::query()->whereKey($replacement->id)->update(['is_active' => true]);
            $replacementActivated = true;
        });

        if ($filePath && Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }

        $message = 'TimerApp update deleted successfully.';

        if ($wasActive) {
            $message .= $replacementActivated
                ? ' The newest remaining upload is now the live release.'
                : ' No uploaded update is live right now.';
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('success', $message);
    }
}
