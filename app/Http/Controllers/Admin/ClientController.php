<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\CoinSaleEvent;
use App\Models\DtimerMachine;
use App\Models\License;
use App\Models\LicenseRevocation;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        $accounts = ClientAccount::query()
            ->with([
                'users:id,name,email,client_account_id',
                'licenses:id,code,product_type,client_account_id,device_id,device_name,activated_at,expires_at,duration',
                'dtimerMachines.license:id,code,product_type,expires_at,activated_at,duration',
                'licenseRevocations' => fn ($query) => $query->latest('requested_at'),
            ])
            ->latest()
            ->get();

        $salesByAccount = CoinSaleEvent::query()
            ->select('client_account_id', DB::raw('COUNT(*) as sales_count'), DB::raw('COALESCE(SUM(amount_minor), 0) as sales_amount_minor'))
            ->groupBy('client_account_id')
            ->get()
            ->keyBy('client_account_id');

        return Inertia::render('admin/ClientsPage', [
            'stats' => [
                'clientAccounts' => $accounts->count(),
                'pcTimers' => License::query()->where('product_type', License::TYPE_PC_TIMER)->whereNotNull('client_account_id')->count(),
                'dtimerMachines' => DtimerMachine::query()->count(),
                'pendingRevocations' => LicenseRevocation::query()->where('status', LicenseRevocation::STATUS_PENDING)->count(),
                'totalSalesAmountMinor' => (int) CoinSaleEvent::query()->sum('amount_minor'),
            ],
            'clients' => $accounts
                ->map(function (ClientAccount $account) use ($salesByAccount): array {
                    $sales = $salesByAccount->get($account->id);

                    return [
                        'id' => $account->id,
                        'name' => $account->name,
                        'contactEmail' => $account->contact_email,
                        'createdAt' => $account->created_at?->toIso8601String(),
                        'users' => $account->users
                            ->map(fn ($user): array => [
                                'name' => $user->name,
                                'email' => $user->email,
                            ])
                            ->values(),
                        'licenseCount' => $account->licenses->count(),
                        'pcTimerLicenseCount' => $account->licenses->where('product_type', License::TYPE_PC_TIMER)->count(),
                        'dtimerWifiLicenseCount' => $account->licenses->where('product_type', License::TYPE_PISO_WIFI)->count(),
                        'machineCount' => $account->dtimerMachines->count(),
                        'onlineMachineCount' => $account->dtimerMachines->filter(fn (DtimerMachine $machine): bool => $machine->isOnline())->count(),
                        'pendingRevocationCount' => $account->licenseRevocations->where('status', LicenseRevocation::STATUS_PENDING)->count(),
                        'salesCount' => (int) ($sales?->sales_count ?? 0),
                        'salesAmountMinor' => (int) ($sales?->sales_amount_minor ?? 0),
                        'machines' => $account->dtimerMachines
                            ->map(fn (DtimerMachine $machine): array => [
                                ...$machine->toApiArray(),
                                'licenseKey' => $machine->license?->code,
                            ])
                            ->values(),
                    ];
                })
                ->values(),
        ]);
    }
}
