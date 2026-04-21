<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StatusLicenseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'license_key' => $this->input('license_key', $this->input('licenseKey')),
            'pc_name' => $this->input('pc_name', $this->input('machineName')),
            'app_version' => $this->input('app_version', $this->input('appVersion')),
            'machine_id' => $this->input('machine_id', $this->input('machineId')),
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
            'pc_name' => ['required', 'string', 'max:255'],
            'machine_id' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ];
    }
}
