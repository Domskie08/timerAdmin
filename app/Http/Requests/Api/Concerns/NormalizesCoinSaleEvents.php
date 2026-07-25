<?php

namespace App\Http\Requests\Api\Concerns;

trait NormalizesCoinSaleEvents
{
    protected function normalizeCoinSaleEvents(): array
    {
        $events = $this->input('events', []);

        if (! is_array($events)) {
            return ['events' => $events];
        }

        return [
            'events' => array_map(function (mixed $event): mixed {
                if (! is_array($event)) {
                    return $event;
                }

                return [
                    'local_event_id' => $this->firstFilledFromArray($event, ['local_event_id', 'localEventId', 'id']),
                    'occurred_at' => $this->firstFilledFromArray($event, ['occurred_at', 'occurredAt', 'timestamp']),
                    'amount_minor' => $this->firstFilledFromArray($event, ['amount_minor', 'amountMinor']),
                    'currency' => strtoupper((string) ($this->firstFilledFromArray($event, ['currency'], 'PHP') ?: 'PHP')),
                    'pulse_count' => $this->firstFilledFromArray($event, ['pulse_count', 'pulseCount', 'pulses'], 0),
                    'session_id' => $this->firstFilledFromArray($event, ['session_id', 'sessionId']),
                    'user_slot' => $this->firstFilledFromArray($event, ['user_slot', 'userSlot']),
                    'metadata' => $event['metadata'] ?? null,
                ];
            }, $events),
        ];
    }

    private function firstFilledFromArray(array $payload, array $keys, mixed $fallback = null): mixed
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (! is_string($value) && filled($value)) {
                return $value;
            }
        }

        return $fallback;
    }
}
