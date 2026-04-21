<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class License extends Model
{
    protected $fillable = [
        'code',
        'expires_at',
        'pc_name',
        'machine_id',
        'activated_at',
        'last_seen_at',
        'last_seen_ip',
        'app_version',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->endOfDay()->isPast() ?? false;
    }

    public function isCurrentlyActive(): bool
    {
        return (bool) $this->pc_name
            && ! $this->isExpired()
            && $this->last_seen_at?->gte(now()->subMinutes(config('timer.active_window_minutes')));
    }

    public function status(): LicenseStatus
    {
        if ($this->isExpired()) {
            return LicenseStatus::Expired;
        }

        if (! $this->pc_name) {
            return LicenseStatus::Available;
        }

        if ($this->isCurrentlyActive()) {
            return LicenseStatus::Active;
        }

        return LicenseStatus::Inactive;
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'licenseKey' => $this->code,
            'creationDate' => $this->created_at?->toIso8601String(),
            'expiryDate' => $this->expires_at?->toDateString(),
            'pcName' => $this->pc_name ?: 'Available',
            'machineId' => $this->machine_id,
            'status' => $this->status()->value,
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'activatedAt' => $this->activated_at?->toIso8601String(),
            'appVersion' => $this->app_version,
        ];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'license_key' => $this->code,
            'licenseKey' => $this->code,
            'pc_name' => $this->pc_name,
            'pcName' => $this->pc_name,
            'machine_id' => $this->machine_id,
            'machineId' => $this->machine_id,
            'expires_at' => $this->expires_at?->toDateString(),
            'expiresAt' => $this->expires_at?->toDateString(),
            'status' => strtolower($this->status()->value),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'app_version' => $this->app_version,
            'appVersion' => $this->app_version,
            'entitlements' => [],
            'metadata' => [],
        ];
    }
}
