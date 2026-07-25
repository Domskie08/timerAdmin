<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoinSaleEvent extends Model
{
    protected $fillable = [
        'client_account_id',
        'dtimer_machine_id',
        'license_id',
        'product_type',
        'local_event_id',
        'occurred_at',
        'received_at',
        'amount_minor',
        'currency',
        'pulse_count',
        'session_id',
        'user_slot',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'amount_minor' => 'integer',
        'pulse_count' => 'integer',
        'metadata' => 'array',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function dtimerMachine(): BelongsTo
    {
        return $this->belongsTo(DtimerMachine::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'machineId' => $this->dtimer_machine_id,
            'licenseId' => $this->license_id,
            'productType' => $this->product_type,
            'localEventId' => $this->local_event_id,
            'occurredAt' => $this->occurred_at?->toIso8601String(),
            'receivedAt' => $this->received_at?->toIso8601String(),
            'amountMinor' => $this->amount_minor,
            'currency' => $this->currency,
            'pulseCount' => $this->pulse_count,
            'sessionId' => $this->session_id,
            'userSlot' => $this->user_slot,
        ];
    }
}
