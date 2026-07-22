<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\NormalizesDtimerWifiInput;
use App\Support\DtimerDeviceIdentity;
use Illuminate\Foundation\Http\FormRequest;

class LinkDtimerMachineRequest extends FormRequest
{
    use NormalizesDtimerWifiInput;

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeDtimerWifiInput());
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'digits:12', 'exists:licenses,code'],
            'device_id' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
            'mac_address' => ['required', 'string', 'max:32', $this->macAddressRule()],
            'machine_id' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'wifi_status' => ['nullable', 'string', 'max:40'],
            'timer_status' => ['nullable', 'string', 'max:40'],
            'connected_users' => ['nullable', 'integer', 'min:0'],
            'active_sessions' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function macAddressRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            try {
                DtimerDeviceIdentity::normalizeMacAddress((string) $value);
            } catch (\InvalidArgumentException) {
                $fail('Enter a valid MAC address.');
            }
        };
    }
}
