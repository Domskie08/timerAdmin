<?php

namespace App\Http\Requests\Admin;

use App\Models\AppUpdate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'product_type' => ['required', Rule::in(array_keys(AppUpdate::PRODUCT_LABELS))],
            'version' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:4000'],
            'external_download_url' => ['required', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'external_download_url.required' => 'The Google Drive download URL is required.',
        ];
    }
}
