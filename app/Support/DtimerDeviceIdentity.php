<?php

namespace App\Support;

use InvalidArgumentException;

class DtimerDeviceIdentity
{
    public static function normalizeMacAddress(string $macAddress): string
    {
        $normalized = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $macAddress) ?? '');

        if (strlen($normalized) !== 12) {
            throw new InvalidArgumentException('MAC address must contain 12 hexadecimal characters.');
        }

        return implode(':', str_split($normalized, 2));
    }

    public static function hashMacAddress(string $macAddress): string
    {
        $normalized = self::normalizeMacAddress($macAddress);
        $key = (string) (config('app.key') ?: 'timeradmin-dtimer-device-identity');

        return hash_hmac('sha256', $normalized, $key);
    }

    public static function maskMacAddress(string $macAddress): string
    {
        $normalized = self::normalizeMacAddress($macAddress);
        $parts = explode(':', $normalized);

        return strtoupper($parts[0].':'.$parts[1].':**:**:'.$parts[4].':'.$parts[5]);
    }
}
