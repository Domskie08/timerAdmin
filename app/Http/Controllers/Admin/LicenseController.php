<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RenewLicenseRequest;
use App\Http\Requests\Admin\StoreLicenseRequest;
use App\Models\License;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LicenseController extends Controller
{
    private const MAX_CODE_GENERATION_ATTEMPTS = 10;

    public function store(StoreLicenseRequest $request): RedirectResponse
    {
        $createdAt = now();
        $duration = $request->string('duration')->toString();

        for ($attempt = 0; $attempt < self::MAX_CODE_GENERATION_ATTEMPTS; $attempt++) {
            $license = new License([
                'code' => $this->generateUniqueCode(),
                'duration' => $duration,
                'expires_at' => License::expiryDateForDuration($duration, $createdAt),
                'created_by' => $request->user()?->id,
            ]);
            $license->created_at = $createdAt;

            try {
                $license->save();

                return redirect()
                    ->route('admin.dashboard')
                    ->with('success', "License {$license->code} created successfully for ".License::durationLabel($duration).'. Expiry will start when an admin activates the device.');
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
        $fileName = 'licenses-'.now()->format('Ymd-His').'.csv';
        $licenses = $this->licenseExportQuery()->latest()->get();

        return response()->streamDownload(function () use ($licenses): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'License key',
                'Device secret',
                'Creation date',
                'License term',
                'Expiry date',
                'Device ID',
                'Machine ID',
                'Device Name',
                'Provision Status',
                'License Status',
                'Renewal State',
                'Consumed For License',
                'Renewed By Codes',
            ]);

            foreach ($licenses as $license) {
                $deviceName = $license->device_name ?: 'Not linked yet';
                $expiryDate = $license->effectiveExpiryDate()?->format('Y-m-d') ?? 'Starts after activation';

                fputcsv($handle, [
                    $license->code,
                    $license->device_secret,
                    $license->created_at?->format('Y-m-d H:i:s'),
                    License::durationLabel($license->resolvedDuration()),
                    $expiryDate,
                    $license->resolvedDeviceId() ?: 'Not linked yet',
                    $license->machine_id ?: 'Not set',
                    $deviceName,
                    $license->provisionStatus(),
                    $license->status()->value,
                    $license->isConsumedForRenewal() ? 'Consumed for renewal' : ($license->canReceiveRenewal() ? 'Accepting renewal codes' : 'Not applicable'),
                    $license->consumedFor?->code ?: 'Not consumed',
                    $license->renewalCodes->pluck('code')->join(', ') ?: 'None',
                ]);
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
                    'Only a bound DTimer device license with a real device binding can be renewed.',
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

            $renewalDuration = $renewalLicense->resolvedDuration();
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
        ]);
    }
}
