<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CoinSaleEvent;
use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PcTimerController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->user()->clientAccount()->firstOrFail();
        $today = now()->startOfDay();

        $licenses = License::query()
            ->where('client_account_id', $account->id)
            ->where('product_type', License::TYPE_PC_TIMER)
            ->latest()
            ->get();

        $licenseIds = $licenses->pluck('id');

        $allSales = CoinSaleEvent::query()
            ->select('license_id', DB::raw('COUNT(*) as sales_count'), DB::raw('COALESCE(SUM(amount_minor), 0) as sales_amount_minor'), DB::raw('COALESCE(SUM(pulse_count), 0) as pulse_count'))
            ->where('client_account_id', $account->id)
            ->whereIn('license_id', $licenseIds)
            ->where('product_type', License::TYPE_PC_TIMER)
            ->groupBy('license_id')
            ->get()
            ->keyBy('license_id');

        $todaySales = CoinSaleEvent::query()
            ->select('license_id', DB::raw('COUNT(*) as sales_count'), DB::raw('COALESCE(SUM(amount_minor), 0) as sales_amount_minor'))
            ->where('client_account_id', $account->id)
            ->whereIn('license_id', $licenseIds)
            ->where('product_type', License::TYPE_PC_TIMER)
            ->where('occurred_at', '>=', $today)
            ->groupBy('license_id')
            ->get()
            ->keyBy('license_id');

        $recentSales = CoinSaleEvent::query()
            ->where('client_account_id', $account->id)
            ->whereIn('license_id', $licenseIds)
            ->where('product_type', License::TYPE_PC_TIMER)
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->groupBy('license_id');

        return Inertia::render('client/PcTimerPage', [
            'licenses' => $licenses
                ->map(function (License $license) use ($allSales, $todaySales, $recentSales): array {
                    $total = $allSales->get($license->id);
                    $today = $todaySales->get($license->id);

                    return [
                        'id' => $license->id,
                        'licenseKey' => $license->code,
                        'deviceId' => $license->resolvedDeviceId(),
                        'deviceName' => $license->device_name,
                        'deviceSecret' => $license->device_secret,
                        'status' => $license->status()->value,
                        'provisionStatus' => $license->provisionStatus(),
                        'durationLabel' => $license->resolvedDurationLabel(),
                        'expiresAt' => $license->effectiveExpiryDate()?->toDateString(),
                        'activatedAt' => $license->activated_at?->toIso8601String(),
                        'lastSeenAt' => $license->last_seen_at?->toIso8601String(),
                        'appVersion' => $license->app_version,
                        'salesCount' => (int) ($total?->sales_count ?? 0),
                        'salesAmountMinor' => (int) ($total?->sales_amount_minor ?? 0),
                        'pulseCount' => (int) ($total?->pulse_count ?? 0),
                        'todaySalesCount' => (int) ($today?->sales_count ?? 0),
                        'todaySalesAmountMinor' => (int) ($today?->sales_amount_minor ?? 0),
                        'recentSales' => ($recentSales->get($license->id) ?? collect())
                            ->map(fn (CoinSaleEvent $sale): array => $sale->toClientArray())
                            ->values(),
                    ];
                })
                ->values(),
            'stats' => [
                'totalDevices' => $licenses->count(),
                'onlineDevices' => $licenses->filter(fn (License $license): bool => $license->isCurrentlyActive())->count(),
                'totalSalesAmountMinor' => (int) CoinSaleEvent::query()
                    ->where('client_account_id', $account->id)
                    ->where('product_type', License::TYPE_PC_TIMER)
                    ->sum('amount_minor'),
                'todaySalesAmountMinor' => (int) CoinSaleEvent::query()
                    ->where('client_account_id', $account->id)
                    ->where('product_type', License::TYPE_PC_TIMER)
                    ->where('occurred_at', '>=', $today)
                    ->sum('amount_minor'),
                'activeWindowMinutes' => config('timer.active_window_minutes'),
            ],
        ]);
    }
}
