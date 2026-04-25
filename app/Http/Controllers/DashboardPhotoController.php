<?php

namespace App\Http\Controllers;

use App\Models\DashboardPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardPhotoController extends Controller
{
    public function show(DashboardPhoto $dashboardPhoto): StreamedResponse
    {
        abort_unless($dashboardPhoto->is_visible, 404);
        abort_unless(Storage::disk('public')->exists($dashboardPhoto->image_path), 404);

        return Storage::disk('public')->response($dashboardPhoto->image_path, $dashboardPhoto->image_name, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
