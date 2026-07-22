<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DtimerMachine extends Model
{
    protected $fillable = [
        'client_account_id',
        'license_id',
        'device_name',
        'device_id',
        'machine_id',
        'mac_address_hash',
        'mac_address_display',
        'app_version',
        'firmware_version',
        'wifi_status',
        'timer_status',
        'connected_users',
        'active_sessions',
        'last_seen_at',
        'last_seen_ip',
        'unlinked_at',
    ];

    protected $casts = [
        'connected_users' => 'integer',
        'active_sessions' => 'integer',
        'last_seen_at' => 'datetime',
        'unlinked_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function coinSaleEvents(): HasMany
    {
        return $this->hasMany(CoinSaleEvent::class);
    }

    public function licenseRevocations(): HasMany
    {
        return $this->hasMany(LicenseRevocation::class);
    }

    public function isOnline(): bool
    {
        return $this->license_id !== null
            && $this->last_seen_at?->gte(now()->subMinutes(config('timer.active_window_minutes')));
    }

    public function statusLabel(): string
    {
        if ($this->license_id === null) {
            return 'Unlinked';
        }

        return $this->isOnline() ? 'Online' : 'Offline';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'deviceName' => $this->device_name,
            'device_id' => $this->device_id,
            'deviceId' => $this->device_id,
            'machine_id' => $this->machine_id,
            'machineId' => $this->machine_id,
            'mac_address' => $this->mac_address_display,
            'macAddress' => $this->mac_address_display,
            'status' => strtolower($this->statusLabel()),
            'statusLabel' => $this->statusLabel(),
            'wifi_status' => $this->wifi_status,
            'wifiStatus' => $this->wifi_status,
            'timer_status' => $this->timer_status,
            'timerStatus' => $this->timer_status,
            'connected_users' => $this->connected_users,
            'connectedUsers' => $this->connected_users,
            'active_sessions' => $this->active_sessions,
            'activeSessions' => $this->active_sessions,
            'app_version' => $this->app_version,
            'appVersion' => $this->app_version,
            'firmware_version' => $this->firmware_version,
            'firmwareVersion' => $this->firmware_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
