<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLicenseRequest;
use App\Models\License;
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
                'expires_at' => License::expiryDateForDuration($duration, $createdAt),
                'created_by' => $request->user()?->id,
            ]);
            $license->created_at = $createdAt;

            try {
                $license->save();

                return redirect()
                    ->route('admin.dashboard')
                    ->with('success', "License {$license->code} created successfully for ".License::durationLabel($duration).'.');
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
        $licenses = License::query()->latest()->get();

        return response()->streamDownload(function () use ($licenses): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'License key',
                'Creation date',
                'Expiry date',
                'Device Name',
                'Status',
            ]);

            foreach ($licenses as $license) {
                $deviceName = $license->device_name ?: 'Available';

                fputcsv($handle, [
                    $license->code,
                    $license->created_at?->format('Y-m-d H:i:s'),
                    $license->expires_at?->format('Y-m-d'),
                    $deviceName,
                    $license->status()->value,
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function destroy(License $license): RedirectResponse
    {
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
}
