<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseRevocation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'client_account_id',
        'license_id',
        'dtimer_machine_id',
        'requested_by',
        'status',
        'reason',
        'requested_at',
        'effective_at',
        'completed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'effective_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function dtimerMachine(): BelongsTo
    {
        return $this->belongsTo(DtimerMachine::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'requestedAt' => $this->requested_at?->toIso8601String(),
            'effectiveAt' => $this->effective_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
        ];
    }
}
