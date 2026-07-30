<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAppUpdateRequest;
use App\Models\AppUpdate;
use App\Support\PhilippineInternetClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AppUpdateController extends Controller
{
    public function store(StoreAppUpdateRequest $request): RedirectResponse
    {
        $publishedAt = PhilippineInternetClock::now();
        $externalDownloadUrl = $request->string('external_download_url')->trim()->toString();
        $productType = $request->string('product_type')->toString();
        $productLabel = AppUpdate::PRODUCT_LABELS[$productType];

        DB::transaction(function () use ($publishedAt, $request, $externalDownloadUrl, $productType, $productLabel): void {
            AppUpdate::query()
                ->forProduct($productType)
                ->update(['is_active' => false]);

            AppUpdate::query()->create([
                'product_type' => $productType,
                'title' => $request->string('title')->toString(),
                'version' => $request->string('version')->toString(),
                'description' => $request->string('description')->toString() ?: null,
                'file_path' => 'external-download',
                'file_name' => "{$productLabel} Google Drive download",
                'file_size' => 0,
                'external_download_url' => $externalDownloadUrl,
                'is_active' => true,
                'published_at' => $publishedAt,
                'uploaded_by' => $request->user()?->id,
            ]);
        });

        return redirect()
            ->route('admin.setup')
            ->with('success', "{$productLabel} update published with the Google Drive download link.");
    }

    public function destroy(AppUpdate $appUpdate): RedirectResponse
    {
        $wasActive = $appUpdate->is_active;
        $filePath = $appUpdate->file_path;
        $productType = $appUpdate->product_type;
        $productLabel = AppUpdate::PRODUCT_LABELS[$productType] ?? 'Software';
        $replacementActivated = false;

        DB::transaction(function () use ($appUpdate, $wasActive, $productType, &$replacementActivated): void {
            $appUpdate->delete();

            if (! $wasActive) {
                return;
            }

            $replacement = AppUpdate::query()
                ->forProduct($productType)
                ->published()
                ->latest('published_at')
                ->first()
                ?? AppUpdate::query()
                    ->forProduct($productType)
                    ->latest('published_at')
                    ->first();

            if (! $replacement) {
                return;
            }

            AppUpdate::query()->whereKey($replacement->id)->update(['is_active' => true]);
            $replacementActivated = true;
        });

        if ($filePath && Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }

        $message = "{$productLabel} update deleted successfully.";

        if ($wasActive) {
            $message .= $replacementActivated
                ? ' The newest remaining upload is now the live release.'
                : ' No uploaded update is live right now.';
        }

        return redirect()
            ->route('admin.setup')
            ->with('success', $message);
    }
}
