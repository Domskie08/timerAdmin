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
}
