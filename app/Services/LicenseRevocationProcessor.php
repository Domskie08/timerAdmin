<?php

namespace App\Services;

use App\Models\DtimerMachine;
use App\Models\License;
use App\Models\LicenseRevocation;
use Illuminate\Support\Facades\DB;

class LicenseRevocationProcessor
{
    public function processDue(?License $license = null): int
    {
        $query = LicenseRevocation::query()
            ->where('status', LicenseRevocation::STATUS_PENDING)
            ->where('effective_at', '<=', now());

        if ($license) {
            $query->where('license_id', $license->id);
        }

        $processed = 0;

        foreach ($query->pluck('id') as $revocationId) {
            if ($this->completeById((int) $revocationId)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function completeById(int $revocationId): bool
    {
        return DB::transaction(function () use ($revocationId): bool {
            $revocation = LicenseRevocation::query()
                ->whereKey($revocationId)
                ->lockForUpdate()
                ->first();

            if (! $revocation?->isPending() || $revocation->effective_at?->isFuture()) {
                return false;
            }

            $license = License::query()
                ->whereKey($revocation->license_id)
                ->lockForUpdate()
                ->first();

            if (! $license) {
                return false;
            }

            $machine = $revocation->dtimer_machine_id
                ? DtimerMachine::query()->whereKey($revocation->dtimer_machine_id)->lockForUpdate()->first()
                : DtimerMachine::query()->where('license_id', $license->id)->lockForUpdate()->first();

            if ($machine) {
                $machine->forceFill([
                    'license_id' => null,
                    'unlinked_at' => now(),
                ])->save();
            }

            $license->forceFill([
                'device_name' => null,
                'device_id' => null,
                'machine_id' => null,
                'last_seen_at' => null,
                'last_seen_ip' => null,
                'app_version' => null,
            ])->save();

            $revocation->forceFill([
                'status' => LicenseRevocation::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            return true;
        });
    }
}
