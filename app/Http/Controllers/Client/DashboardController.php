<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CoinSaleEvent;
use App\Models\DtimerMachine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->user()->clientAccount()->firstOrFail();
        $today = now()->startOfDay();

        $machines = DtimerMachine::query()
            ->where('client_account_id', $account->id)
            ->with('license:id,code,expires_at,activated_at,duration')
            ->latest()
            ->get();

        $recentSales = CoinSaleEvent::query()
            ->where('client_account_id', $account->id)
            ->with('dtimerMachine:id,device_name')
            ->latest('occurred_at')
            ->limit(8)
            ->get()
            ->map(fn (CoinSaleEvent $sale): array => [
                ...$sale->toClientArray(),
                'machineName' => $sale->dtimerMachine?->device_name ?: 'DTimer machine',
            ])
            ->values();

        return Inertia::render('client/DashboardPage', [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'contactEmail' => $account->contact_email,
            ],
            'stats' => [
                'machines' => $machines->count(),
                'onlineMachines' => $machines->filter(fn (DtimerMachine $machine): bool => $machine->isOnline())->count(),
                'linkedLicenses' => $machines->filter(fn (DtimerMachine $machine): bool => (bool) $machine->license_id)->count(),
                'totalSalesAmountMinor' => (int) CoinSaleEvent::query()->where('client_account_id', $account->id)->sum('amount_minor'),
                'todaySalesAmountMinor' => (int) CoinSaleEvent::query()->where('client_account_id', $account->id)->where('occurred_at', '>=', $today)->sum('amount_minor'),
                'todaySalesCount' => CoinSaleEvent::query()->where('client_account_id', $account->id)->where('occurred_at', '>=', $today)->count(),
                'activeWindowMinutes' => config('timer.active_window_minutes'),
            ],
            'machines' => $machines
                ->map(fn (DtimerMachine $machine): array => [
                    ...$machine->toApiArray(),
                    'licenseKey' => $machine->license?->code,
                    'expiresAt' => $machine->license?->effectiveExpiryDate()?->toDateString(),
                ])
                ->values(),
            'recentSales' => $recentSales,
        ]);
    }
}
