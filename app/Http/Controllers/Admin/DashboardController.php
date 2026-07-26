<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $licenses = License::query()
            ->with([
                'clientAccount:id,name',
                'consumedFor:id,code',
                'renewalCodes' => fn ($query) => $query
                    ->select(['id', 'code', 'duration', 'expires_at', 'created_at', 'consumed_at', 'consumed_by_license_id'])
                    ->latest('consumed_at'),
            ])
            ->latest()
            ->get();

        $frozenLicenses = $licenses->filter(fn (License $license): bool => $license->isFrozen())->count();
        $provisionedLicenses = $licenses->filter(fn (License $license): bool => $license->isProvisioned())->count();
        $activeDevices = $licenses->filter(fn (License $license): bool => $license->isCurrentlyActive())->count();
        $expiredLicenses = $licenses->filter(fn (License $license): bool => $license->isExpired())->count();
        $pcLicenses = $licenses->filter(fn (License $license): bool => $license->resolvedProductType() === License::TYPE_PC_TIMER)->count();
        $pisoWifiLicenses = $licenses->filter(fn (License $license): bool => $license->isPisoWifiLicense())->count();

        return Inertia::render('admin/DashboardPage', [
            'stats' => [
                'totalLicenses' => $licenses->count(),
                'pcLicenses' => $pcLicenses,
                'pisoWifiLicenses' => $pisoWifiLicenses,
                'frozenLicenses' => $frozenLicenses,
                'provisionedLicenses' => $provisionedLicenses,
                'activeDevices' => $activeDevices,
                'expiredLicenses' => $expiredLicenses,
                'activeWindowMinutes' => config('timer.active_window_minutes'),
            ],
            'licenseDurations' => License::durationOptions(),
            'defaultLicenseDuration' => License::defaultDuration(),
            'licenses' => $licenses
                ->map(fn (License $license): array => $license->toAdminArray())
                ->values(),
        ]);
    }
}
