<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DtimerHeartbeatRequest;
use App\Http\Requests\Api\LinkDtimerMachineRequest;
use App\Http\Requests\Api\StoreCoinSalesBatchRequest;
use App\Models\CoinSaleEvent;
use App\Models\DtimerMachine;
use App\Models\License;
use App\Models\LicenseRevocation;
use App\Services\LicenseRevocationProcessor;
use App\Support\DtimerDeviceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DtimerWifiController extends Controller
{
    public function __construct(private readonly LicenseRevocationProcessor $revocationProcessor)
    {
    }

    public function link(LinkDtimerMachineRequest $request): JsonResponse
    {
        $this->processDueRevocationsForLicenseKey($request->string('license_key')->toString());

        return DB::transaction(function () use ($request): JsonResponse {
            $license = $this->licenseForUpdate($request->string('license_key')->toString());

            if ($response = $this->rejectUnusableLicense($license, false)) {
                return $response;
            }

            $deviceId = $request->string('device_id')->toString();
            $macHash = DtimerDeviceIdentity::hashMacAddress($request->string('mac_address')->toString());
            $macDisplay = DtimerDeviceIdentity::maskMacAddress($request->string('mac_address')->toString());

            $machineWithMac = DtimerMachine::query()
                ->where('mac_address_hash', $macHash)
                ->lockForUpdate()
                ->first();

            if ($machineWithMac && (int) $machineWithMac->client_account_id !== (int) $license->client_account_id) {
                return $this->errorResponse('mac_in_use', 'This MAC address is already owned by another client account.', 409, $license);
            }

            if ($machineWithMac?->license_id && (int) $machineWithMac->license_id !== (int) $license->id) {
                return $this->errorResponse('mac_in_use', 'This DTimer machine is already linked to another license.', 409, $license, $machineWithMac);
            }

            $machineForLicense = DtimerMachine::query()
                ->where('license_id', $license->id)
                ->lockForUpdate()
                ->first();

            if ($machineForLicense && ! hash_equals($machineForLicense->mac_address_hash, $macHash)) {
                return $this->errorResponse('license_in_use', 'This license is already linked to another DTimer machine.', 409, $license, $machineForLicense);
            }

            if (! $machineForLicense && $license->resolvedDeviceId() && ! hash_equals($license->resolvedDeviceId(), $deviceId)) {
                return $this->errorResponse('license_in_use', 'This license is already linked to another device ID.', 409, $license);
            }

            if ($this->hasPendingRevocation($license) && $machineForLicense && ! hash_equals($machineForLicense->mac_address_hash, $macHash)) {
                return $this->errorResponse('revocation_pending', 'This license has a pending revocation. It can link to new hardware after the 30-day period.', 409, $license, $machineForLicense);
            }

            $machine = $machineWithMac ?: new DtimerMachine([
                'client_account_id' => $license->client_account_id,
                'mac_address_hash' => $macHash,
                'mac_address_display' => $macDisplay,
            ]);

            $machine->forceFill([
                'client_account_id' => $license->client_account_id,
                'license_id' => $license->id,
                'mac_address_hash' => $macHash,
                'mac_address_display' => $macDisplay,
                'unlinked_at' => null,
            ]);

            $this->syncMachineTelemetry($machine, $request, true);
            $machine->save();

            $license = $this->syncLicenseFromMachine($license, $machine, $request, true);

            return $this->successResponse($license, $machine->fresh('license'), 'DTimer WiFi machine linked successfully.');
        });
    }

    public function heartbeat(DtimerHeartbeatRequest $request): JsonResponse
    {
        $this->processDueRevocationsForLicenseKey($request->string('license_key')->toString());

        return DB::transaction(function () use ($request): JsonResponse {
            [$license, $machine, $response] = $this->authenticatedMachineForUpdate($request);

            if ($response) {
                return $response;
            }

            $this->syncMachineTelemetry($machine, $request, true);
            $machine->save();

            $license = $this->syncLicenseFromMachine($license, $machine, $request, false);

            return $this->successResponse($license, $machine->fresh('license'), 'DTimer heartbeat received.');
        });
    }

    public function storeCoinSales(StoreCoinSalesBatchRequest $request): JsonResponse
    {
        $this->processDueRevocationsForLicenseKey($request->string('license_key')->toString());

        return DB::transaction(function () use ($request): JsonResponse {
            [$license, $machine, $response] = $this->authenticatedMachineForUpdate($request);

            if ($response) {
                return $response;
            }

            $this->syncMachineTelemetry($machine, $request, true);
            $machine->save();
            $license = $this->syncLicenseFromMachine($license, $machine, $request, false);

            $accepted = 0;
            $duplicates = 0;

            foreach ($request->validated('events') as $event) {
                $sale = CoinSaleEvent::query()->firstOrCreate(
                    [
                        'dtimer_machine_id' => $machine->id,
                        'local_event_id' => $event['local_event_id'],
                    ],
                    [
                        'client_account_id' => $machine->client_account_id,
                        'license_id' => $license->id,
                        'occurred_at' => CarbonImmutable::parse($event['occurred_at']),
                        'received_at' => now(),
                        'amount_minor' => (int) $event['amount_minor'],
                        'currency' => strtoupper((string) ($event['currency'] ?? 'PHP')),
                        'pulse_count' => (int) ($event['pulse_count'] ?? 0),
                        'session_id' => $event['session_id'] ?? null,
                        'user_slot' => $event['user_slot'] ?? null,
                        'metadata' => $event['metadata'] ?? null,
                    ]
                );

                if ($sale->wasRecentlyCreated) {
                    $accepted++;
                } else {
                    $duplicates++;
                }
            }

            return $this->successResponse($license, $machine->fresh('license'), 'Coin sales batch received.', [
                'accepted' => $accepted,
                'duplicates' => $duplicates,
                'rejected' => 0,
            ]);
        });
    }

    /**
     * @return array{0: License|null, 1: DtimerMachine|null, 2: JsonResponse|null}
     */
    private function authenticatedMachineForUpdate(Request $request): array
    {
        $license = $this->licenseForUpdate($request->string('license_key')->toString());

        if (! hash_equals((string) $license->device_secret, $request->string('device_secret')->toString())) {
            return [null, null, $this->errorResponse('unauthorized', 'Device secret does not match this license.', 401, $license)];
        }

        if ($response = $this->rejectUnusableLicense($license, true)) {
            return [null, null, $response];
        }

        $macHash = DtimerDeviceIdentity::hashMacAddress($request->string('mac_address')->toString());
        $machine = DtimerMachine::query()
            ->where('license_id', $license->id)
            ->where('mac_address_hash', $macHash)
            ->lockForUpdate()
            ->first();

        if (! $machine) {
            return [null, null, $this->errorResponse('machine_not_linked', 'This DTimer machine is not linked to the supplied license.', 409, $license)];
        }

        if ((int) $machine->client_account_id !== (int) $license->client_account_id) {
            return [null, null, $this->errorResponse('machine_not_linked', 'This DTimer machine is not linked to the license owner.', 409, $license, $machine)];
        }

        return [$license, $machine, null];
    }

    private function processDueRevocationsForLicenseKey(string $licenseKey): void
    {
        $license = License::query()
            ->where('code', $licenseKey)
            ->first();

        if ($license) {
            $this->revocationProcessor->processDue($license);
        }
    }

    private function licenseForUpdate(string $licenseKey): License
    {
        return License::query()
            ->where('code', $licenseKey)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function rejectUnusableLicense(License $license, bool $requireLinkedMachine): ?JsonResponse
    {
        if (! $license->client_account_id) {
            return $this->errorResponse('unclaimed', 'Claim this license from the client admin portal before linking a DTimer WiFi machine.', 409, $license);
        }

        if ($license->isConsumedForRenewal()) {
            return $this->errorResponse('inactive', 'This license code has already been consumed for renewal.', 409, $license);
        }

        if ($license->isExpired()) {
            return $this->errorResponse('expired', 'This license has expired.', 422, $license);
        }

        if ($requireLinkedMachine && ! $license->resolvedDeviceId()) {
            return $this->errorResponse('machine_not_linked', 'This license has no linked DTimer machine.', 409, $license);
        }

        return null;
    }

    private function hasPendingRevocation(License $license): bool
    {
        return LicenseRevocation::query()
            ->where('license_id', $license->id)
            ->where('status', LicenseRevocation::STATUS_PENDING)
            ->exists();
    }

    private function syncMachineTelemetry(DtimerMachine $machine, Request $request, bool $recordPresence): void
    {
        $machine->device_id = $request->string('device_id')->toString();

        $deviceName = $request->string('device_name')->toString();
        if ($deviceName !== '') {
            $machine->device_name = $deviceName;
        }

        $machineId = $request->string('machine_id')->toString();
        if ($machineId !== '') {
            $machine->machine_id = $machineId;
        }

        $appVersion = $request->string('app_version')->toString();
        if ($appVersion !== '') {
            $machine->app_version = $appVersion;
        }

        $firmwareVersion = $request->string('firmware_version')->toString();
        if ($firmwareVersion !== '') {
            $machine->firmware_version = $firmwareVersion;
        }

        $wifiStatus = $request->string('wifi_status')->toString();
        if ($wifiStatus !== '') {
            $machine->wifi_status = $wifiStatus;
        }

        $timerStatus = $request->string('timer_status')->toString();
        if ($timerStatus !== '') {
            $machine->timer_status = $timerStatus;
        }

        if ($request->has('connected_users')) {
            $machine->connected_users = max(0, (int) $request->input('connected_users'));
        }

        if ($request->has('active_sessions')) {
            $machine->active_sessions = max(0, (int) $request->input('active_sessions'));
        }

        if ($recordPresence) {
            $machine->last_seen_at = now();
            $machine->last_seen_ip = $request->ip();
        }
    }

    private function syncLicenseFromMachine(License $license, DtimerMachine $machine, Request $request, bool $activateIfNeeded): License
    {
        $license->device_id = $machine->device_id;
        $license->device_name = $machine->device_name;
        $license->machine_id = $machine->machine_id;

        if ($machine->app_version) {
            $license->app_version = $machine->app_version;
        }

        if ($activateIfNeeded && ! $license->isActivated()) {
            $license->activated_at = now();
            $license->expires_at = License::expiryDateForDuration($license->resolvedDuration(), $license->activated_at);
        }

        $license->last_seen_at = now();
        $license->last_seen_ip = $request->ip();
        $license->save();

        return $license->fresh();
    }

    private function successResponse(License $license, DtimerMachine $machine, string $message, array $extra = []): JsonResponse
    {
        $pendingRevocation = LicenseRevocation::query()
            ->where('license_id', $license->id)
            ->where('status', LicenseRevocation::STATUS_PENDING)
            ->latest('requested_at')
            ->first();

        return response()->json([
            'success' => true,
            'status' => $license->toApiArray()['status'],
            'message' => $message,
            'license' => $license->toApiArray(),
            'machine' => $machine->toApiArray(),
            'revocation' => $pendingRevocation?->toClientArray(),
            ...$extra,
        ]);
    }

    private function errorResponse(
        string $status,
        string $message,
        int $statusCode,
        ?License $license = null,
        ?DtimerMachine $machine = null
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'status' => $status,
            'message' => $message,
            'license' => $license?->toApiArray(),
            'machine' => $machine?->toApiArray(),
        ], $statusCode);
    }
}
