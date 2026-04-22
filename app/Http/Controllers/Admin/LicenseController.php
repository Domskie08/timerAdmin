<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLicenseRequest;
use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LicenseController extends Controller
{
    public function store(StoreLicenseRequest $request): RedirectResponse
    {
        $createdAt = now();
        $duration = $request->string('duration')->toString();

        $license = new License([
            'code' => $this->generateUniqueCode(),
            'expires_at' => License::expiryDateForDuration($duration, $createdAt),
            'created_by' => $request->user()?->id,
        ]);
        $license->created_at = $createdAt;
        $license->save();

        return redirect()
            ->route('admin.dashboard')
            ->with('success', "License {$license->code} created successfully for ".License::durationLabel($duration).'.');
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
                'PC name',
                'Status',
            ]);

            foreach ($licenses as $license) {
                fputcsv($handle, [
                    $license->code,
                    $license->created_at?->format('Y-m-d H:i:s'),
                    $license->expires_at?->format('Y-m-d'),
                    $license->pc_name ?: 'Available',
                    $license->status()->value,
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
                .str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (License::query()->where('code', $code)->exists());

        return $code;
    }
}
