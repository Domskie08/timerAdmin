<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\NormalizesCoinSaleEvents;
use App\Http\Requests\Api\Concerns\NormalizesLicenseDeviceInput;
use Illuminate\Foundation\Http\FormRequest;

class StorePcTimerCoinSalesBatchRequest extends FormRequest
{
    use NormalizesCoinSaleEvents;
    use NormalizesLicenseDeviceInput;

    protected function prepareForValidation(): void
    {
        $this->merge([
            ...$this->normalizeLicenseDeviceInput(),
            'device_secret' => $this->firstFilledInput(['device_secret', 'deviceSecret', 'secret_key', 'secretKey']),
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
            'device_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
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
}
