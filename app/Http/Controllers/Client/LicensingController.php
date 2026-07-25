<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\DtimerMachine;
use App\Models\License;
use App\Models\LicenseRevocation;
use App\Services\LicenseRevocationProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LicensingController extends Controller
{
    public function __construct(private readonly LicenseRevocationProcessor $revocationProcessor)
    {
    }

    public function index(Request $request): Response
    {
        $account = $request->user()->clientAccount()->firstOrFail();
        $this->revocationProcessor->processDue();

        $licenses = License::query()
            ->where('client_account_id', $account->id)
            ->with([
                'dtimerMachine',
                'pendingRevocations',
            ])
            ->latest()
            ->get();

        return Inertia::render('client/LicensingPage', [
            'licenses' => $licenses
                ->map(fn (License $license): array => [
                    'id' => $license->id,
                    'licenseKey' => $license->code,
                    'productType' => $license->resolvedProductType(),
                    'productTypeLabel' => $license->productTypeLabel(),
                    'deviceSecret' => $license->device_secret,
                    'durationLabel' => $license->resolvedDurationLabel(),
                    'isLifetime' => $license->isLifetimeLicense(),
                    'status' => $license->status()->value,
                    'provisionStatus' => $license->provisionStatus(),
                    'expiryDate' => $license->effectiveExpiryDate()?->toDateString(),
                    'activatedAt' => $license->activated_at?->toIso8601String(),
                    'lastSeenAt' => $license->last_seen_at?->toIso8601String(),
                    'deviceId' => $license->resolvedDeviceId(),
                    'deviceName' => $license->device_name,
                    'appVersion' => $license->app_version,
                    'machine' => $license->dtimerMachine?->toApiArray(),
                    'pendingRevocation' => $license->pendingRevocations->first()?->toClientArray(),
                ])
                ->values(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function claim(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'license_key' => ['required', 'digits:12', 'exists:licenses,code'],
            'product_type' => ['required', 'string', Rule::in([License::TYPE_PC_TIMER, License::TYPE_PISO_WIFI])],
        ]);

        $account = $request->user()->clientAccount()->firstOrFail();

        return DB::transaction(function () use ($account, $validated): RedirectResponse {
            $license = License::query()
                ->where('code', $validated['license_key'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($license->isConsumedForRenewal()) {
                throw ValidationException::withMessages([
                    'license_key' => 'This license code has already been consumed for renewal.',
                ]);
            }

            if ($license->resolvedProductType() !== $validated['product_type']) {
                throw ValidationException::withMessages([
                    'license_key' => 'This license key does not match the selected product type.',
                ]);
            }

            if ($license->client_account_id && (int) $license->client_account_id !== (int) $account->id) {
                throw ValidationException::withMessages([
                    'license_key' => 'This license is already claimed by another client account.',
                ]);
            }

            if (! $license->client_account_id) {
                $license->client_account_id = $account->id;
                $license->save();
            }

            return redirect()
                ->route('client.licensing')
                ->with('success', "License {$license->code} is now attached to your client account.");
        });
    }

    /**
     * @throws ValidationException
     */
    public function requestRevocation(Request $request, License $license): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $request->user()->clientAccount()->firstOrFail();
        $this->revocationProcessor->processDue($license);

        return DB::transaction(function () use ($account, $license, $request, $validated): RedirectResponse {
            $lockedLicense = License::query()
                ->whereKey($license->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless((int) $lockedLicense->client_account_id === (int) $account->id, 404);

            if (! $lockedLicense->isPisoWifiLicense()) {
                throw ValidationException::withMessages([
                    'reason' => 'Only DTimer WiFi machines can be revoked from the client portal.',
                ]);
            }

            $pendingExists = LicenseRevocation::query()
                ->where('license_id', $lockedLicense->id)
                ->where('status', LicenseRevocation::STATUS_PENDING)
                ->exists();

            if ($pendingExists) {
                throw ValidationException::withMessages([
                    'reason' => 'This license already has a pending 30-day revocation.',
                ]);
            }

            $machine = DtimerMachine::query()
                ->where('license_id', $lockedLicense->id)
                ->lockForUpdate()
                ->first();

            if (! $machine && ! $lockedLicense->hasBoundDevice()) {
                throw ValidationException::withMessages([
                    'reason' => 'Only a linked DTimer WiFi machine can be revoked.',
                ]);
            }

            LicenseRevocation::query()->create([
                'client_account_id' => $account->id,
                'license_id' => $lockedLicense->id,
                'dtimer_machine_id' => $machine?->id,
                'requested_by' => $request->user()?->id,
                'status' => LicenseRevocation::STATUS_PENDING,
                'reason' => $validated['reason'] ?? null,
                'requested_at' => now(),
                'effective_at' => now()->addDays(config('timer.revocation_wait_days')),
            ]);

            return redirect()
                ->route('client.licensing')
                ->with('success', 'Revocation requested. The machine will unlink from this license after 30 days.');
        });
    }
}
