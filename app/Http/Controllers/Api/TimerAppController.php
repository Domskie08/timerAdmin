<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActivateLicenseRequest;
use App\Http\Requests\Api\HeartbeatRequest;
use App\Http\Requests\Api\StatusLicenseRequest;
use App\Http\Requests\Api\StorePcTimerCoinSalesBatchRequest;
use App\Models\AppUpdate;
use App\Models\CoinSaleEvent;
use App\Models\License;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimerAppController extends Controller
{
    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $license = $this->resolveLicenseForUpdate($request->string('license_key')->toString());

            if (! $license->isPcTimerLicense()) {
                return $this->errorResponse($license, 'Use a PC TimerApp license for TimerApp activation.', 409, 'wrong_license_type');
            }

            if ($license->isExpired()) {
                return $this->errorResponse($license, 'License has expired.', 422, 'expired');
            }

            $deviceName = $request->string('device_name')->toString();
            $deviceId = $request->string('device_id')->toString();

            if ($license->isConsumedForRenewal()) {
                return $this->errorResponse($license, 'This license code has already been consumed for renewal.', 409, 'inactive');
            }

            if ($this->licenseAssignedToAnotherDevice($license, $deviceId)) {
                return response()->json([
                    'success' => false,
                    'status' => 'in_use',
                    'message' => 'License is already assigned to another device. Revoke it from the registered device before using it elsewhere.',
                    'license' => $license->fresh()?->toApiArray(),
                ], 409);
            }

            if (! $license->device_id) {
                $license->device_id = $deviceId;
            }
            $license->device_name = $deviceName;

            if (! $license->isActivated()) {
                $license->activated_at = now();
                $license->expires_at = License::expiryDateForDuration(
                    $license->resolvedDuration(),
                    $license->activated_at
                );
            } elseif (! $license->expires_at) {
                $license->expires_at = License::expiryDateForDuration(
                    $license->resolvedDuration(),
                    $license->activated_at ?? now()
                );
            }

            $license = $this->syncObservedDeviceMetadata($license, $request, true);

            return $this->successResponse($license, 'License activated successfully.');
        });
    }

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $deviceId = $request->string('device_id')->toString();
        $licenseKey = $request->string('license_key')->toString();
        $license = $this->resolveLicenseByKeyOrDevice($licenseKey, $deviceId);

        if (! $license) {
            return response()->json([
                'success' => false,
                'status' => 'inactive',
                'message' => 'Please buy license to activate some feature.',
                'license' => null,
            ], 404);
        }

        if (! $license->isPcTimerLicense()) {
            return $this->errorResponse($license, 'Use a PC TimerApp license for TimerApp heartbeat.', 409, 'wrong_license_type');
        }

        if ($license->isConsumedForRenewal()) {
            return $this->errorResponse($license, 'This license code has already been consumed for renewal.', 409, 'inactive');
        }

        if ($license->isFrozen()) {
            if ($this->licenseAssignedToAnotherDevice(
                $license,
                $deviceId
            )) {
                return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
            }

            if ($license->resolvedDeviceId()) {
                $license = $this->syncObservedDeviceMetadata($license, $request, false);
            }

            return $this->successResponse($license, 'License is frozen until activated from Settings.');
        }

        if (! $this->licenseMatchesCurrentDevice($license, $deviceId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        if ($license->isExpired()) {
            return $this->errorResponse($license, 'Please buy license to activate some feature.', 422, 'expired');
        }

        $license = $this->syncObservedDeviceMetadata($license, $request, true);

        return $this->successResponse($license, 'Heartbeat received.');
    }

    public function storeCoinSales(StorePcTimerCoinSalesBatchRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $license = $this->resolveLicenseForUpdate($request->string('license_key')->toString());
            $deviceId = $request->string('device_id')->toString();

            if (! hash_equals((string) $license->device_secret, $request->string('device_secret')->toString())) {
                return $this->errorResponse($license, 'Device secret does not match this license.', 401, 'unauthorized');
            }

            if (! $license->isPcTimerLicense()) {
                return $this->errorResponse($license, 'Use a PC TimerApp license for TimerApp coin sales.', 409, 'wrong_license_type');
            }

            if (! $license->client_account_id) {
                return $this->errorResponse($license, 'Claim this license from the client admin portal before sending PC Timer coin sales.', 409, 'unclaimed');
            }

            if ($license->isConsumedForRenewal()) {
                return $this->errorResponse($license, 'This license code has already been consumed for renewal.', 409, 'inactive');
            }

            if ($license->isFrozen()) {
                return $this->errorResponse($license, 'Activate this PC Timer license before sending coin sales.', 409, 'inactive');
            }

            if (! $this->licenseMatchesCurrentDevice($license, $deviceId)) {
                return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
            }

            if ($license->isExpired()) {
                return $this->errorResponse($license, 'Please buy license to activate some feature.', 422, 'expired');
            }

            $license = $this->syncObservedDeviceMetadata($license, $request, true);
            $accepted = 0;
            $duplicates = 0;

            foreach ($request->validated('events') as $event) {
                $sale = CoinSaleEvent::query()->firstOrCreate(
                    [
                        'license_id' => $license->id,
                        'local_event_id' => $event['local_event_id'],
                    ],
                    [
                        'client_account_id' => $license->client_account_id,
                        'dtimer_machine_id' => null,
                        'product_type' => License::TYPE_PC_TIMER,
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

            return response()->json([
                'success' => true,
                'status' => $license->toApiArray()['status'],
                'message' => 'PC Timer coin sales batch received.',
                'license' => $license->toApiArray(),
                'accepted' => $accepted,
                'duplicates' => $duplicates,
                'rejected' => 0,
            ]);
        });
    }

    public function status(StatusLicenseRequest $request): JsonResponse
    {
        $deviceId = $request->string('device_id')->toString();
        $licenseKey = $request->string('license_key')->toString();
        $license = $this->resolveLicenseByKeyOrDevice($licenseKey, $deviceId);

        if (! $license) {
            return response()->json([
                'success' => false,
                'status' => 'inactive',
                'message' => 'Please buy license to activate some feature.',
                'license' => null,
            ], 404);
        }

        if (! $license->isPcTimerLicense()) {
            return $this->errorResponse($license, 'Use a PC TimerApp license for TimerApp status checks.', 409, 'wrong_license_type');
        }

        if ($license->isConsumedForRenewal()) {
            return $this->errorResponse($license, 'This license code has already been consumed for renewal.', 409, 'inactive');
        }

        if ($license->isFrozen()) {
            if ($this->licenseAssignedToAnotherDevice(
                $license,
                $deviceId
            )) {
                return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
            }

            if ($license->resolvedDeviceId()) {
                $license = $this->syncObservedDeviceMetadata($license, $request, false);
            }

            return $this->successResponse($license, 'License is frozen until activated from Settings.');
        }

        if (! $this->licenseMatchesCurrentDevice($license, $deviceId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        if ($license->isExpired()) {
            return $this->errorResponse($license, 'Please buy license to activate some feature.', 422, 'expired');
        }

        $license = $this->syncObservedDeviceMetadata($license, $request, true);

        return $this->successResponse($license, 'License is valid.');
    }

    public function latestUpdate(Request $request): JsonResponse
    {
        $productType = $this->requestedUpdateProduct($request);
        $latest = AppUpdate::query()
            ->forProduct($productType)
            ->published()
            ->where('is_active', true)
            ->latest('published_at')
            ->first();

        if (! $latest) {
            return response()->json([
                'product' => $productType,
                'has_update' => false,
                'update' => null,
            ]);
        }

        $currentVersion = $request->string('current_version')->toString();
        $hasUpdate = $currentVersion === '' || version_compare($latest->version, $currentVersion, '>');

        return response()->json([
            'product' => $productType,
            'has_update' => $hasUpdate,
            'update' => $latest->toPublicArray(),
        ]);
    }

    public function updates(Request $request): JsonResponse
    {
        $productType = $this->requestedUpdateProduct($request);
        $currentVersion = $request->string('current_version')->trim()->toString();
        $onlyNewer = $request->boolean('only_newer');
        $updates = AppUpdate::query()
            ->forProduct($productType)
            ->published()
            ->latest('published_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->filter(
                fn (AppUpdate $update): bool => ! $onlyNewer
                    || $currentVersion === ''
                    || version_compare($update->version, $currentVersion, '>')
            )
            ->values()
            ->map(fn (AppUpdate $update): array => $update->toPublicArray());

        return response()->json([
            'product' => $productType,
            'current_version' => $currentVersion ?: null,
            'has_updates' => $updates->isNotEmpty(),
            'updates' => $updates,
        ]);
    }

    public function download(AppUpdate $appUpdate): RedirectResponse|StreamedResponse
    {
        abort_unless($appUpdate->isPublished(), 404);

        if ($appUpdate->external_download_url) {
            return redirect()->away($appUpdate->external_download_url);
        }

        return Storage::disk('public')->download($appUpdate->file_path, $appUpdate->file_name);
    }

    private function requestedUpdateProduct(Request $request): string
    {
        $productType = $request->string('product')->trim()->toString() ?: AppUpdate::TYPE_TIMER_APP;
        if (! array_key_exists($productType, AppUpdate::PRODUCT_LABELS)) {
            throw ValidationException::withMessages([
                'product' => 'Select timer_app or dtimer_wifi.',
            ]);
        }

        return $productType;
    }

    private function resolveLicense(string $licenseKey): License
    {
        return License::query()
            ->where('code', $licenseKey)
            ->firstOrFail();
    }

    private function resolveLicenseByKeyOrDevice(?string $licenseKey, string $deviceId): ?License
    {
        if ($licenseKey) {
            return License::query()
                ->where('code', $licenseKey)
                ->first();
        }

        return License::query()
            ->where('product_type', License::TYPE_PC_TIMER)
            ->where('device_id', $deviceId)
            ->first();
    }

    private function resolveLicenseForUpdate(string $licenseKey): License
    {
        return License::query()
            ->where('code', $licenseKey)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function licenseAssignedToAnotherDevice(License $license, string $deviceId): bool
    {
        if (! $license->resolvedDeviceId()) {
            return false;
        }

        return ! hash_equals($license->resolvedDeviceId(), $deviceId);
    }

    private function licenseMatchesCurrentDevice(License $license, string $deviceId): bool
    {
        return (bool) $license->resolvedDeviceId() && hash_equals($license->resolvedDeviceId(), $deviceId);
    }

    private function syncObservedDeviceMetadata(License $license, Request $request, bool $recordPresence): License
    {
        $deviceName = $request->string('device_name')->toString();
        if ($deviceName !== '') {
            $license->device_name = $deviceName;
        }

        $appVersion = $request->string('app_version')->toString();
        if ($appVersion !== '') {
            $license->app_version = $appVersion;
        }

        if ($recordPresence) {
            $license->last_seen_at = now();
            $license->last_seen_ip = $request->ip();
        }

        $license->save();

        return $license->fresh();
    }

    private function successResponse(?License $license, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => $license?->toApiArray()['status'] ?? 'active',
            'message' => $message,
            'device_id' => $license?->resolvedDeviceId(),
            'deviceId' => $license?->resolvedDeviceId(),
            'device_secret' => $license?->device_secret,
            'deviceSecret' => $license?->device_secret,
            'license' => $license?->toApiArray(),
        ]);
    }

    private function errorResponse(?License $license, string $message, int $statusCode, string $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => $status,
            'message' => $message,
            'device_id' => $license?->resolvedDeviceId(),
            'deviceId' => $license?->resolvedDeviceId(),
            'device_secret' => $license?->device_secret,
            'deviceSecret' => $license?->device_secret,
            'license' => $license?->toApiArray(),
        ], $statusCode);
    }
}
