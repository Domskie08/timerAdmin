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
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimerAppController extends Controller
{
    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        $license = $this->resolveLicense($request->string('license_key')->toString());

        if ($license->isExpired()) {
            return $this->errorResponse($license, 'License has expired.', 422, 'expired');
        }

        $deviceName = $request->string('device_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if ($this->licenseAssignedToAnotherDevice($license, $deviceName, $machineId)) {
            return response()->json([
                'success' => false,
                'status' => 'in_use',
                'message' => 'License is already assigned to another device.',
                'license' => $license->fresh()?->toApiArray(),
            ], 409);
        }

        if (! $license->device_name) {
            $license->device_name = $deviceName;
            $license->machine_id = $machineId ?: $license->machine_id;
            $license->activated_at = now();
        }

        $license->last_seen_at = now();
        $license->last_seen_ip = $request->ip();
        $license->app_version = $request->string('app_version')->toString() ?: $license->app_version;
        $license->machine_id = $machineId ?: $license->machine_id;
        $license->save();

        return $this->successResponse($license->fresh(), 'License activated successfully.');
    }

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $license = $this->resolveLicense($request->string('license_key')->toString());

        $deviceName = $request->string('device_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if (! $this->licenseMatchesCurrentDevice($license, $deviceName, $machineId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        if ($license->isExpired()) {
            return $this->errorResponse($license, 'License has expired.', 422, 'expired');
        }

        $license->last_seen_at = now();
        $license->last_seen_ip = $request->ip();
        $license->app_version = $request->string('app_version')->toString() ?: $license->app_version;
        $license->machine_id = $machineId ?: $license->machine_id;
        $license->save();

        return $this->successResponse($license->fresh(), 'Heartbeat received.');
    }

    public function status(StatusLicenseRequest $request): JsonResponse
    {
        $license = $this->resolveLicense($request->string('license_key')->toString());
        $deviceName = $request->string('device_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if (! $this->licenseMatchesCurrentDevice($license, $deviceName, $machineId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        if ($license->isExpired()) {
            return $this->errorResponse($license, 'License has expired.', 422, 'expired');
        }

        $license->last_seen_at = now();
        $license->last_seen_ip = $request->ip();
        $license->app_version = $request->string('app_version')->toString() ?: $license->app_version;
        $license->machine_id = $machineId ?: $license->machine_id;
        $license->save();

        return $this->successResponse($license->fresh(), 'License is valid.');
    }

    public function revoke(StatusLicenseRequest $request): JsonResponse
    {
        $license = $this->resolveLicense($request->string('license_key')->toString());
        $deviceName = $request->string('device_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if (! $this->licenseMatchesCurrentDevice($license, $deviceName, $machineId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        $license->device_name = null;
        $license->machine_id = null;
        $license->activated_at = null;
        $license->last_seen_at = null;
        $license->last_seen_ip = null;
        $license->app_version = null;
        $license->save();

        return response()->json([
            'success' => true,
            'status' => 'available',
            'message' => 'License revoked on this device.',
            'license' => $license->fresh()?->toApiArray(),
        ]);
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

    private function licenseAssignedToAnotherDevice(License $license, string $deviceName, string $machineId): bool
    {
        if (! $license->device_name) {
            return false;
        }

        if ($machineId !== '' && $license->machine_id && hash_equals($license->machine_id, $machineId)) {
            return false;
        }

        return strcasecmp($license->device_name, $deviceName) !== 0;
    }

    private function licenseMatchesCurrentDevice(License $license, string $deviceName, string $machineId): bool
    {
        if (! $license->device_name) {
            return false;
        }

        if ($machineId !== '' && $license->machine_id) {
            return hash_equals($license->machine_id, $machineId);
        }

        return strcasecmp($license->device_name, $deviceName) === 0;
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
