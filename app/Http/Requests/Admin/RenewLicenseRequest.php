<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenewLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'renew_license_code' => ['required', 'digits:12', 'exists:licenses,code'],
            'target_license_id' => ['required', 'integer', Rule::in([(int) $this->route('license')?->getKey()])],
        ];
    }

    public function messages(): array
    {
        return [
            'renew_license_code.required' => 'Enter a renewal license code.',
            'renew_license_code.digits' => 'Renew license code must be exactly 12 digits.',
            'renew_license_code.exists' => 'Renew license code was not found in the registry.',
        ];
    }
}
