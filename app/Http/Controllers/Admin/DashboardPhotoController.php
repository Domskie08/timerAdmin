<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDashboardPhotoRequest;
use App\Models\DashboardPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DashboardPhotoController extends Controller
{
    public function store(StoreDashboardPhotoRequest $request): RedirectResponse
    {
        $photo = $request->file('photo');
        $originalName = pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $photo->getClientOriginalExtension();
        $slug = Str::slug($originalName);
        $slug = $slug !== '' ? $slug : 'dashboard-photo';
        $storedName = now()->format('YmdHis').'-'.$slug.'.'.$extension;
        $path = $photo->storeAs('dashboard-photos', $storedName, 'public');
        $nextPosition = ((int) DashboardPhoto::query()->max('position')) + 1;

        DashboardPhoto::query()->create([
            'title' => $request->string('photo_title')->toString() ?: null,
            'image_path' => $path,
            'image_name' => $photo->getClientOriginalName(),
            'position' => $nextPosition,
            'is_visible' => true,
            'uploaded_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'Dashboard photo uploaded successfully.');
    }

    public function destroy(DashboardPhoto $dashboardPhoto): RedirectResponse
    {
        $imagePath = $dashboardPhoto->image_path;

        $dashboardPhoto->delete();

        if ($imagePath && Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'Dashboard photo deleted successfully.');
    }
}
