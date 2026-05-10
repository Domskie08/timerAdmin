<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActivateLicenseRequest;
use App\Http\Requests\Api\HeartbeatRequest;
use App\Http\Requests\Api\StatusLicenseRequest;
use App\Models\AppUpdate;
use App\Models\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimerAppController extends Controller
{
    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $license = $this->resolveLicenseForUpdate($request->string('license_key')->toString());

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
        $latest = AppUpdate::query()
            ->published()
            ->where('is_active', true)
            ->latest('published_at')
            ->first();

        if (! $latest) {
            return response()->json([
                'has_update' => false,
                'update' => null,
            ]);
        }

        $currentVersion = $request->string('current_version')->toString();
        $hasUpdate = $currentVersion === '' || version_compare($latest->version, $currentVersion, '>');

        return response()->json([
            'has_update' => $hasUpdate,
            'update' => $latest->toPublicArray(),
        ]);
    }

    public function download(AppUpdate $appUpdate): StreamedResponse
    {
        abort_unless($appUpdate->isPublished(), 404);

        return Storage::disk('public')->download($appUpdate->file_path, $appUpdate->file_name);
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

        $machineId = $request->string('machine_id')->toString();
        if ($machineId !== '') {
            $license->machine_id = $machineId;
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
            'machine_id' => $license?->machine_id,
            'machineId' => $license?->machine_id,
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
            'machine_id' => $license?->machine_id,
            'machineId' => $license?->machine_id,
            'device_secret' => $license?->device_secret,
            'deviceSecret' => $license?->device_secret,
            'license' => $license?->toApiArray(),
        ], $statusCode);
    }
}
