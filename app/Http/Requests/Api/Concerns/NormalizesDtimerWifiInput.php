<?php

namespace App\Http\Requests\Api\Concerns;

trait NormalizesDtimerWifiInput
{
    protected function normalizeDtimerWifiInput(): array
    {
        return [
            'license_key' => $this->firstFilledInput(['license_key', 'licenseKey']),
            'device_id' => $this->firstFilledInput(['device_id', 'deviceId']),
            'device_name' => $this->firstFilledInput(['device_name', 'deviceName', 'machineName']),
            'machine_id' => $this->firstFilledInput(['machine_id', 'machineId']),
            'mac_address' => $this->firstFilledInput(['mac_address', 'macAddress', 'mac']),
            'device_secret' => $this->firstFilledInput(['device_secret', 'deviceSecret', 'secret_key', 'secretKey']),
            'app_version' => $this->firstFilledInput(['app_version', 'appVersion']),
            'firmware_version' => $this->firstFilledInput(['firmware_version', 'firmwareVersion']),
            'wifi_status' => $this->firstFilledInput(['wifi_status', 'wifiStatus']),
            'timer_status' => $this->firstFilledInput(['timer_status', 'timerStatus']),
            'connected_users' => $this->firstFilledInput(['connected_users', 'connectedUsers']),
            'active_sessions' => $this->firstFilledInput(['active_sessions', 'activeSessions']),
        ];
    }

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

    private function firstFilledInput(array $keys, mixed $fallback = null): mixed
    {
        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (! is_string($value) && filled($value)) {
                return $value;
            }
        }

        return $fallback;
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
