<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CoinSaleEvent;
use App\Models\DtimerMachine;
use App\Models\LicenseRevocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DtimerWifiController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->user()->clientAccount()->firstOrFail();
        $today = now()->startOfDay();

        $machines = DtimerMachine::query()
            ->where('client_account_id', $account->id)
            ->with('license:id,code,product_type,duration,expires_at,activated_at,device_secret')
            ->latest()
            ->get();

        $machineIds = $machines->pluck('id');

        $allSales = CoinSaleEvent::query()
            ->select('dtimer_machine_id', DB::raw('COUNT(*) as sales_count'), DB::raw('COALESCE(SUM(amount_minor), 0) as sales_amount_minor'), DB::raw('COALESCE(SUM(pulse_count), 0) as pulse_count'))
            ->where('client_account_id', $account->id)
            ->whereIn('dtimer_machine_id', $machineIds)
            ->groupBy('dtimer_machine_id')
            ->get()
            ->keyBy('dtimer_machine_id');

        $todaySales = CoinSaleEvent::query()
            ->select('dtimer_machine_id', DB::raw('COUNT(*) as sales_count'), DB::raw('COALESCE(SUM(amount_minor), 0) as sales_amount_minor'))
            ->where('client_account_id', $account->id)
            ->whereIn('dtimer_machine_id', $machineIds)
            ->where('occurred_at', '>=', $today)
            ->groupBy('dtimer_machine_id')
            ->get()
            ->keyBy('dtimer_machine_id');

        $pendingRevocations = LicenseRevocation::query()
            ->where('client_account_id', $account->id)
            ->where('status', LicenseRevocation::STATUS_PENDING)
            ->whereIn('license_id', $machines->pluck('license_id')->filter())
            ->latest('requested_at')
            ->get()
            ->keyBy('license_id');

        $recentSales = CoinSaleEvent::query()
            ->where('client_account_id', $account->id)
            ->whereIn('dtimer_machine_id', $machineIds)
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->groupBy('dtimer_machine_id');

        return Inertia::render('client/DtimerWifiPage', [
            'machines' => $machines
                ->map(function (DtimerMachine $machine) use ($allSales, $todaySales, $pendingRevocations, $recentSales): array {
                    $total = $allSales->get($machine->id);
                    $today = $todaySales->get($machine->id);

                    return [
                        ...$machine->toApiArray(),
                        'licenseKey' => $machine->license?->code,
                        'licenseStatus' => $machine->license?->status()->value,
                        'expiresAt' => $machine->license?->effectiveExpiryDate()?->toDateString(),
                        'salesCount' => (int) ($total?->sales_count ?? 0),
                        'salesAmountMinor' => (int) ($total?->sales_amount_minor ?? 0),
                        'pulseCount' => (int) ($total?->pulse_count ?? 0),
                        'todaySalesCount' => (int) ($today?->sales_count ?? 0),
                        'todaySalesAmountMinor' => (int) ($today?->sales_amount_minor ?? 0),
                        'pendingRevocation' => $machine->license_id ? $pendingRevocations->get($machine->license_id)?->toClientArray() : null,
                        'recentSales' => ($recentSales->get($machine->id) ?? collect())
                            ->map(fn (CoinSaleEvent $sale): array => $sale->toClientArray())
                            ->values(),
                    ];
                })
                ->values(),
            'stats' => [
                'totalMachines' => $machines->count(),
                'onlineMachines' => $machines->filter(fn (DtimerMachine $machine): bool => $machine->isOnline())->count(),
                'totalSalesAmountMinor' => (int) CoinSaleEvent::query()
                    ->where('client_account_id', $account->id)
                    ->whereIn('dtimer_machine_id', $machineIds)
                    ->sum('amount_minor'),
                'activeSessions' => $machines->sum('active_sessions'),
                'connectedUsers' => $machines->sum('connected_users'),
                'activeWindowMinutes' => config('timer.active_window_minutes'),
            ],
        ]);
    }
}
