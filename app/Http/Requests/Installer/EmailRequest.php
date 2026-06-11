<?php

namespace App\Http\Requests\Installer;

use Illuminate\Foundation\Http\FormRequest;

class EmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'driver' => ['required', 'in:log,smtp'],
            'from_address' => ['nullable', 'required_if:driver,smtp', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['nullable', 'required_if:driver,smtp', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'required_if:driver,smtp', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'in:smtps,tls'],
        ];
    }
}
