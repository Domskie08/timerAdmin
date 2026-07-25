<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RenewLicenseRequest;
use App\Http\Requests\Admin\StoreLicenseRequest;
use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LicenseController extends Controller
{
    private const MAX_CODE_GENERATION_ATTEMPTS = 10;

    public function store(StoreLicenseRequest $request): RedirectResponse
    {
        return $this->storeTyped($request, License::TYPE_PC_TIMER);
    }

    public function storePc(StoreLicenseRequest $request): RedirectResponse
    {
        return $this->storeTyped($request, License::TYPE_PC_TIMER);
    }

    public function storePisoWifi(Request $request): RedirectResponse
    {
        return $this->storeTyped($request, License::TYPE_PISO_WIFI);
    }

    private function storeTyped(Request $request, string $productType): RedirectResponse
    {
        $createdAt = now();
        $duration = $productType === License::TYPE_PC_TIMER
            ? $request->string('duration')->toString()
            : null;
        $productLabel = License::productTypeLabelFor($productType);

        for ($attempt = 0; $attempt < self::MAX_CODE_GENERATION_ATTEMPTS; $attempt++) {
            $license = new License([
                'code' => $this->generateUniqueCode(),
                'product_type' => $productType,
                'duration' => $duration,
                'expires_at' => $duration ? License::expiryDateForDuration($duration, $createdAt) : null,
                'created_by' => $request->user()?->id,
            ]);
            $license->created_at = $createdAt;

            try {
                $license->save();

                $message = $duration
                    ? "{$productLabel} license {$license->code} created successfully for ".License::durationLabel($duration).'. Expiry will start when the device activates.'
                    : "{$productLabel} lifetime license {$license->code} created successfully.";

                return redirect()
                    ->route('admin.dashboard')
                    ->with('success', $message);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateCodeException($exception)) {
                    throw $exception;
                }
            }
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('error', 'Could not generate a unique license key right now. Please try again.');
    }

    public function export(): StreamedResponse
    {
        return $this->exportTyped(null, 'licenses');
    }

    public function exportPc(): StreamedResponse
    {
        return $this->exportTyped(License::TYPE_PC_TIMER, 'pc-timer-licenses');
    }

    public function exportDtimerWifi(): StreamedResponse
    {
        return $this->exportTyped(License::TYPE_PISO_WIFI, 'dtimer-wifi-licenses');
    }

    private function exportTyped(?string $productType, string $baseFileName): StreamedResponse
    {
        $fileName = $baseFileName.'-'.now()->format('Ymd-His').'.csv';
        $query = $this->licenseExportQuery();

        if ($productType) {
            $query->where('product_type', $productType);
        }

        $licenses = $query->latest()->get();

        return response()->streamDownload(function () use ($licenses, $productType): void {
            $handle = fopen('php://output', 'wb');
            $includeMachineId = $productType !== License::TYPE_PC_TIMER;

            $headers = [
                'License key',
                'License type',
                'Device secret',
                'Creation date',
                'License term',
                'Expiry date',
                'Device ID',
                'Device Name',
                'Client Account',
                'Provision Status',
                'License Status',
                'Renewal State',
                'Consumed For License',
                'Renewed By Codes',
            ];

            if ($includeMachineId) {
                array_splice($headers, 7, 0, ['Machine ID']);
            }

            fputcsv($handle, $headers);

            foreach ($licenses as $license) {
                $deviceName = $license->device_name ?: 'Not linked yet';
                $expiryDate = $license->effectiveExpiryDate()?->format('Y-m-d') ?? 'Starts after activation';

                $row = [
                    $license->code,
                    $license->productTypeLabel(),
                    $license->device_secret,
                    $license->created_at?->format('Y-m-d H:i:s'),
                    $license->resolvedDurationLabel(),
                    $license->isLifetimeLicense() ? 'Lifetime' : $expiryDate,
                    $license->resolvedDeviceId() ?: 'Not linked yet',
                    $deviceName,
                    $license->clientAccount?->name ?: 'Unclaimed',
                    $license->provisionStatus(),
                    $license->status()->value,
                    $license->isConsumedForRenewal() ? 'Consumed for renewal' : ($license->canReceiveRenewal() ? 'Accepting renewal codes' : 'Not applicable'),
                    $license->consumedFor?->code ?: 'Not consumed',
                    $license->renewalCodes->pluck('code')->join(', ') ?: 'None',
                ];

                if ($includeMachineId) {
                    array_splice($row, 7, 0, [$license->machine_id ?: 'Not set']);
                }

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function renew(RenewLicenseRequest $request, License $license): RedirectResponse
    {
        return DB::transaction(function () use ($request, $license): RedirectResponse {
            $targetLicense = License::query()
                ->whereKey($license->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $targetLicense->canReceiveRenewal()) {
                return $this->renewalErrorRedirect(
                    'Only a bound PC Timer license with a real device binding can be renewed.',
                    $request
                );
            }

            $renewLicenseCode = $request->string('renew_license_code')->toString();
            $renewalLicense = License::query()
                ->where('code', $renewLicenseCode)
                ->lockForUpdate()
                ->first();

            if (! $renewalLicense) {
                return $this->renewalErrorRedirect('Renew license code was not found in the registry.', $request);
            }

            if ($renewalLicense->is($targetLicense)) {
                return $this->renewalErrorRedirect('A license cannot renew itself.', $request);
            }

            if ($renewalLicense->isConsumedForRenewal()) {
                return $this->renewalErrorRedirect('This renew license code has already been consumed.', $request);
            }

            if ($renewalLicense->resolvedDeviceId() || $renewalLicense->isActivated()) {
                return $this->renewalErrorRedirect('Only a new unused license code can be consumed for renewal.', $request);
            }

            if ($renewalLicense->resolvedProductType() !== $targetLicense->resolvedProductType()) {
                return $this->renewalErrorRedirect('Renewal license type must match the target license type.', $request);
            }

            $renewalDuration = $renewalLicense->resolvedDuration();
            if (! $renewalDuration) {
                return $this->renewalErrorRedirect('Renewal license must be a PC Timer license with a license term.', $request);
            }

            $targetLicense->expires_at = License::expiryDateForDuration(
                $renewalDuration,
                $this->renewalBaseDate($targetLicense)
            );
            $targetLicense->save();

            $renewalLicense->consumed_by_license_id = $targetLicense->id;
            $renewalLicense->consumed_at = now();
            $renewalLicense->save();

            return redirect()
                ->route('admin.dashboard')
                ->with('success', "License {$targetLicense->code} renewed successfully using {$renewalLicense->code} for ".License::durationLabel($renewalDuration).'.');
        });
    }

    public function destroy(License $license): RedirectResponse
    {
        if ($license->isConsumedForRenewal() || $license->renewalCodes()->exists()) {
            return redirect()
                ->route('admin.dashboard')
                ->with('error', "License {$license->code} is part of renewal history and cannot be deleted.");
        }

        $code = $license->code;
        $license->delete();

        return redirect()
            ->route('admin.dashboard')
            ->with('success', "License {$code} deleted successfully.");
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
                .str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (License::query()->where('code', $code)->exists());

        return $code;
    }

    private function isDuplicateCodeException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, [19, 1062], true)
            || (str_contains($message, 'duplicate') && str_contains($message, 'code'));
    }

    private function renewalBaseDate(License $license)
    {
        if ($license->expires_at && $license->expires_at->endOfDay()->isFuture()) {
            return $license->expires_at;
        }

        return now();
    }

    private function renewalErrorRedirect(string $message, RenewLicenseRequest $request): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard')
            ->withErrors(['renew_license_code' => $message])
            ->withInput($request->only(['renew_license_code', 'target_license_id']));
    }

    private function licenseExportQuery()
    {
        return License::query()->with([
            'consumedFor:id,code',
            'renewalCodes:id,code,consumed_by_license_id',
            'clientAccount:id,name',
        ]);
    }
}
