<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\NormalizesDtimerWifiInput;
use App\Support\DtimerDeviceIdentity;
use Illuminate\Foundation\Http\FormRequest;

class StoreCoinSalesBatchRequest extends FormRequest
{
    use NormalizesDtimerWifiInput;

    protected function prepareForValidation(): void
    {
        $this->merge([
            ...$this->normalizeDtimerWifiInput(),
            ...$this->normalizeCoinSaleEvents(),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'digits:12', 'exists:licenses,code'],
            'device_secret' => ['required', 'string', 'size:64'],
            'device_id' => ['required', 'string', 'max:255'],
            'mac_address' => ['required', 'string', 'max:32', $this->macAddressRule()],
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.local_event_id' => ['required', 'string', 'max:100'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.amount_minor' => ['required', 'integer', 'min:0'],
            'events.*.currency' => ['nullable', 'string', 'size:3'],
            'events.*.pulse_count' => ['nullable', 'integer', 'min:0'],
            'events.*.session_id' => ['nullable', 'string', 'max:255'],
            'events.*.user_slot' => ['nullable', 'string', 'max:255'],
            'events.*.metadata' => ['nullable', 'array'],
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
