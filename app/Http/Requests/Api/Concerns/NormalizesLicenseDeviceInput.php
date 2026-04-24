<?php

namespace App\Http\Requests\Api\Concerns;

trait NormalizesLicenseDeviceInput
{
    protected function normalizeLicenseDeviceInput(): array
    {
        $licenseKey = $this->firstFilledInput(['license_key', 'licenseKey']);
        $deviceId = $this->firstFilledInput(['device_id', 'deviceId']);
        $deviceName = $this->firstFilledInput(['device_name', 'deviceName']);
        $machineId = $this->firstFilledInput(['machine_id', 'machineId'], $deviceId);
        $pcName = $this->firstFilledInput(['pc_name', 'machineName'], $deviceName);

        if (! $pcName && $machineId) {
            $pcName = 'Device '.$machineId;
        }

        return [
            'license_key' => $licenseKey,
            'pc_name' => $pcName,
            'app_version' => $this->firstFilledInput(['app_version', 'appVersion']),
            'machine_id' => $machineId,
        ];
    }

    private function firstFilledInput(array $keys, mixed $fallback = null): mixed
    {
        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value) ? trim($value) !== '' : filled($value)) {
                return $value;
            }
        }

        if (is_string($fallback) && trim($fallback) === '') {
            return null;
        }

        return $fallback;
    }
}
