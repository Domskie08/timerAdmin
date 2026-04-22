<?php

namespace App\Http\Requests\Admin;

use App\Models\License;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'duration' => ['required', 'string', Rule::in(array_column(License::durationOptions(), 'value'))],
        ];
    }

    public function messages(): array
    {
        return [
            'duration.required' => 'Choose a license term.',
            'duration.in' => 'Choose one of the available license terms.',
        ];
    }
}

