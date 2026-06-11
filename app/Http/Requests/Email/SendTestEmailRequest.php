<?php

namespace App\Http\Requests\Email;

use App\Models\SmtpSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SendTestEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('test', SmtpSetting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'test_email' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'test_email' => 'email di test',
        ];
    }
}
