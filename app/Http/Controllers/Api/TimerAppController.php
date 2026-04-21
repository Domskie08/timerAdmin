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

        $pcName = $request->string('pc_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if ($this->licenseAssignedToAnotherMachine($license, $pcName, $machineId)) {
            return response()->json([
                'success' => false,
                'status' => 'in_use',
                'message' => 'License is already assigned to another PC.',
                'license' => $license->fresh()?->toApiArray(),
            ], 409);
        }

        if (! $license->pc_name) {
            $license->pc_name = $pcName;
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

        $pcName = $request->string('pc_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if (! $this->licenseMatchesCurrentMachine($license, $pcName, $machineId)) {
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
        $pcName = $request->string('pc_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if (! $this->licenseMatchesCurrentMachine($license, $pcName, $machineId)) {
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
        $pcName = $request->string('pc_name')->toString();
        $machineId = $request->string('machine_id')->toString();

        if (! $this->licenseMatchesCurrentMachine($license, $pcName, $machineId)) {
            return $this->errorResponse($license, 'This device is not linked to the supplied license.', 409, 'inactive');
        }

        $license->pc_name = null;
        $license->machine_id = null;
        $license->activated_at = null;
        $license->last_seen_at = null;
        $license->last_seen_ip = null;
        $license->app_version = null;
        $license->save();

        return response()->json([
            'success' => true,
            'status' => 'available',
            'message' => 'License revoked on this PC.',
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
        abort_unless($appUpdate->is_active && $appUpdate->isPublished(), 404);

        return Storage::disk('public')->download($appUpdate->file_path, $appUpdate->file_name);
    }

    private function resolveLicense(string $licenseKey): License
    {
        return License::query()
            ->where('code', $licenseKey)
            ->firstOrFail();
    }

    private function licenseAssignedToAnotherMachine(License $license, string $pcName, string $machineId): bool
    {
        if (! $license->pc_name) {
            return false;
        }

        if ($machineId !== '' && $license->machine_id && hash_equals($license->machine_id, $machineId)) {
            return false;
        }

        return strcasecmp($license->pc_name, $pcName) !== 0;
    }

    private function licenseMatchesCurrentMachine(License $license, string $pcName, string $machineId): bool
    {
        if (! $license->pc_name) {
            return false;
        }

        if ($machineId !== '' && $license->machine_id) {
            return hash_equals($license->machine_id, $machineId);
        }

        return strcasecmp($license->pc_name, $pcName) === 0;
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
