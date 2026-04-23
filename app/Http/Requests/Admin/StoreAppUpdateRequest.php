<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        $maxUploadKb = (int) config('timer.update_upload_max_kb');

        return [
            'version' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:4000'],
            'package' => ['required', 'file', 'mimes:zip,exe,msi', 'max:'.$maxUploadKb],
        ];
    }

    public function messages(): array
    {
        $maxUploadMb = (int) ceil(config('timer.update_upload_max_kb') / 1024);

        return [
            'package.max' => "The update package may not be greater than {$maxUploadMb} MB.",
        ];
    }
}
