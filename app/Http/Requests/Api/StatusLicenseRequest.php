<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\NormalizesLicenseDeviceInput;
use Illuminate\Foundation\Http\FormRequest;

class StatusLicenseRequest extends FormRequest
{
    use NormalizesLicenseDeviceInput;

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeLicenseDeviceInput());
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'digits:12', 'exists:licenses,code'],
            'device_name' => ['required', 'string', 'max:255'],
            'machine_id' => ['required', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ];
    }
}
