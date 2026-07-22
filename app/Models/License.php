<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class License extends Model
{
    public const DURATION_OPTIONS = [
        ['value' => '1_month', 'label' => '1 month', 'months' => 1],
        ['value' => '3_months', 'label' => '3 months', 'months' => 3],
        ['value' => '6_months', 'label' => '6 months', 'months' => 6],
        ['value' => '1_year', 'label' => '1 year', 'months' => 12],
    ];

    protected $fillable = [
        'code',
        'duration',
        'expires_at',
        'device_name',
        'device_id',
        'machine_id',
        'device_secret',
        'consumed_by_license_id',
        'consumed_at',
        'activated_at',
        'last_seen_at',
        'last_seen_ip',
        'app_version',
        'created_by',
        'client_account_id',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'consumed_at' => 'datetime',
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (License $license): void {
            if (! is_string($license->device_secret) || trim($license->device_secret) === '') {
                $license->device_secret = self::generateDeviceSecret();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function consumedFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'consumed_by_license_id');
    }

    public function renewalCodes(): HasMany
    {
        return $this->hasMany(self::class, 'consumed_by_license_id');
    }

    public function dtimerMachine(): HasOne
    {
        return $this->hasOne(DtimerMachine::class);
    }

    public function revocations(): HasMany
    {
        return $this->hasMany(LicenseRevocation::class);
    }

    public function pendingRevocations(): HasMany
    {
        return $this->revocations()
            ->where('status', LicenseRevocation::STATUS_PENDING)
            ->latest('requested_at');
    }

    public static function durationOptions(): array
    {
        return self::DURATION_OPTIONS;
    }

    public static function defaultDuration(): string
    {
        return self::DURATION_OPTIONS[0]['value'];
    }

    public static function durationLabel(string $duration): string
    {
        foreach (self::DURATION_OPTIONS as $option) {
            if ($option['value'] === $duration) {
                return $option['label'];
            }
        }

        throw new \InvalidArgumentException('Unsupported license duration.');
    }

    public static function inferDurationFromDates(?CarbonInterface $createdAt, ?CarbonInterface $expiresAt): ?string
    {
        if (! $createdAt || ! $expiresAt) {
            return null;
        }

        foreach (self::DURATION_OPTIONS as $option) {
            $expectedExpiryDate = self::expiryDateForDuration($option['value'], $createdAt)->toDateString();

            if ($expectedExpiryDate === $expiresAt->toDateString()) {
                return $option['value'];
            }
        }

        return null;
    }

    public static function expiryDateForDuration(string $duration, CarbonInterface $createdAt)
    {
        foreach (self::DURATION_OPTIONS as $option) {
            if ($option['value'] === $duration) {
                return $createdAt->copy()->addMonthsNoOverflow($option['months'])->startOfDay();
            }
        }

        throw new \InvalidArgumentException('Unsupported license duration.');
    }

    public static function generateDeviceSecret(): string
    {
        do {
            $secret = bin2hex(random_bytes(32));
        } while (self::query()->where('device_secret', $secret)->exists());

        return $secret;
    }

    public function resolvedDuration(): string
    {
        if (is_string($this->duration) && $this->duration !== '') {
            return $this->duration;
        }

        return self::inferDurationFromDates($this->created_at, $this->expires_at) ?? self::defaultDuration();
    }

    public function isActivated(): bool
    {
        return (bool) $this->activated_at;
    }

    public function isFrozen(): bool
    {
        return ! $this->isActivated();
    }

    public function resolvedDeviceId(): ?string
    {
        return $this->device_id;
    }

    public function hasBoundDevice(): bool
    {
        return (bool) $this->resolvedDeviceId();
    }

    public function isProvisioned(): bool
    {
        return $this->hasBoundDevice();
    }

    public function provisionStatus(): string
    {
        return $this->isProvisioned() ? 'Provisioned' : 'Not Provisioned';
    }

    public function effectiveExpiryDate(): ?CarbonInterface
    {
        if (! $this->isActivated()) {
            return null;
        }

        return $this->expires_at;
    }

    public function isExpired(): bool
    {
        if (! $this->isActivated()) {
            return false;
        }

        return $this->expires_at?->endOfDay()->isPast() ?? false;
    }

    public function isCurrentlyActive(): bool
    {
        return $this->isActivated()
            && (bool) $this->device_name
            && ! $this->isExpired()
            && $this->last_seen_at?->gte(now()->subMinutes(config('timer.active_window_minutes')));
    }

    public function isConsumedForRenewal(): bool
    {
        return (bool) ($this->consumed_by_license_id && $this->consumed_at);
    }

    public function canReceiveRenewal(): bool
    {
        return $this->hasBoundDevice() && ! $this->isConsumedForRenewal();
    }

    public function status(): LicenseStatus
    {
        if ($this->isExpired()) {
            return LicenseStatus::Expired;
        }

        if ($this->isFrozen()) {
            return LicenseStatus::Frozen;
        }

        return LicenseStatus::Active;
    }

    public function toAdminArray(): array
    {
        $deviceName = $this->device_name ?: 'Not linked yet';
        $licenseStatus = $this->status()->value;
        $expiryDate = $this->effectiveExpiryDate();

        return [
            'id' => $this->id,
            'licenseKey' => $this->code,
            'deviceSecret' => $this->device_secret,
            'duration' => $this->resolvedDuration(),
            'durationLabel' => self::durationLabel($this->resolvedDuration()),
            'creationDate' => $this->created_at?->toIso8601String(),
            'expiryDate' => $expiryDate?->toDateString(),
            'deviceId' => $this->resolvedDeviceId(),
            'machineId' => $this->machine_id,
            'deviceName' => $deviceName,
            'provisionStatus' => $this->provisionStatus(),
            'status' => $licenseStatus,
            'licenseStatus' => $licenseStatus,
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'activatedAt' => $this->activated_at?->toIso8601String(),
            'appVersion' => $this->app_version,
            'clientAccountId' => $this->client_account_id,
            'clientAccountName' => $this->relationLoaded('clientAccount') ? $this->clientAccount?->name : null,
            'canRenew' => $this->canReceiveRenewal(),
            'isConsumedForRenewal' => $this->isConsumedForRenewal(),
            'consumedAt' => $this->consumed_at?->toIso8601String(),
            'consumedForLicenseKey' => $this->consumedFor?->code,
            'renewalHistory' => $this->renewalCodes
                ->map(fn (License $renewal): array => [
                    'licenseKey' => $renewal->code,
                    'durationLabel' => self::durationLabel($renewal->resolvedDuration()),
                    'consumedAt' => $renewal->consumed_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function toApiArray(): array
    {
        $licenseStatus = strtolower($this->status()->value);
        $expiryDate = $this->effectiveExpiryDate();

        return [
            'id' => $this->id,
            'license_key' => $this->code,
            'licenseKey' => $this->code,
            'device_id' => $this->resolvedDeviceId(),
            'deviceId' => $this->resolvedDeviceId(),
            'machine_id' => $this->machine_id,
            'machineId' => $this->machine_id,
            'device_secret' => $this->device_secret,
            'deviceSecret' => $this->device_secret,
            'secret_key' => $this->device_secret,
            'secretKey' => $this->device_secret,
            'device_name' => $this->device_name,
            'deviceName' => $this->device_name,
            'expires_at' => $expiryDate?->toDateString(),
            'expiresAt' => $expiryDate?->toDateString(),
            'duration' => $this->resolvedDuration(),
            'provision_status' => $this->isProvisioned() ? 'provisioned' : 'not_provisioned',
            'provisionStatus' => $this->provisionStatus(),
            'license_status' => $licenseStatus,
            'licenseStatus' => $this->status()->value,
            'status' => $licenseStatus,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'app_version' => $this->app_version,
            'appVersion' => $this->app_version,
            'entitlements' => [],
            'metadata' => [
                'device_secret' => $this->device_secret,
                'deviceSecret' => $this->device_secret,
                'client_account_id' => $this->client_account_id,
            ],
        ];
    }
}
