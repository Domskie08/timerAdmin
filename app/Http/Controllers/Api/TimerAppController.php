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
            $machineId = $request->string('machine_id')->toString();

            if ($this->licenseAssignedToAnotherDevice($license, $machineId)) {
                return response()->json([
                    'success' => false,
                    'status' => 'in_use',
                    'message' => 'License is already assigned to another device. Revoke it from the registered device before using it elsewhere.',
                    'license' => $license->fresh()?->toApiArray(),
                ], 409);
            }

            if (! $license->machine_id) {
                $license->device_name = $deviceName;
                $license->machine_id = $machineId;
                $license->activated_at = now();
            } elseif (! $license->device_name) {
                $license->device_name = $deviceName;
            }

            $license->last_seen_at = now();
            $license->last_seen_ip = $request->ip();
            $license->app_version = $request->string('app_version')->toString() ?: $license->app_version;
            $license->save();

            return $this->successResponse($license->fresh(), 'License activated successfully.');
        });
    }

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $deviceId = $request->string('machine_id')->toString();
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

        if (! $this->licenseMatchesCurrentDevice($license, $deviceId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        if ($license->isExpired()) {
            return $this->errorResponse($license, 'Please buy license to activate some feature.', 422, 'expired');
        }

        $license->last_seen_at = now();
        $license->last_seen_ip = $request->ip();
        $license->app_version = $request->string('app_version')->toString() ?: $license->app_version;
        $license->save();

        return $this->successResponse($license->fresh(), 'Heartbeat received.');
    }

    public function status(StatusLicenseRequest $request): JsonResponse
    {
        $deviceId = $request->string('machine_id')->toString();
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

        if (! $this->licenseMatchesCurrentDevice($license, $deviceId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        if ($license->isExpired()) {
            return $this->errorResponse($license, 'Please buy license to activate some feature.', 422, 'expired');
        }

        $license->last_seen_at = now();
        $license->last_seen_ip = $request->ip();
        $license->app_version = $request->string('app_version')->toString() ?: $license->app_version;
        $license->save();

        return $this->successResponse($license->fresh(), 'License is valid.');
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
            ->where('machine_id', $deviceId)
            ->first();
    }

    private function resolveLicenseForUpdate(string $licenseKey): License
    {
        return License::query()
            ->where('code', $licenseKey)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function licenseAssignedToAnotherDevice(License $license, string $machineId): bool
    {
        if (! $license->machine_id) {
            return (bool) $license->device_name;
        }

        return ! hash_equals($license->machine_id, $machineId);
    }

    private function licenseMatchesCurrentDevice(License $license, string $machineId): bool
    {
        return (bool) $license->machine_id && hash_equals($license->machine_id, $machineId);
    }

    private function successResponse(?License $license, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => $license?->toApiArray()['status'] ?? 'active',
            'message' => $message,
            'license' => $license?->toApiArray(),
        ]);
    }

    private function errorResponse(?License $license, string $message, int $statusCode, string $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => $status,
            'message' => $message,
            'license' => $license?->toApiArray(),
        ], $statusCode);
    }
}
