<?php

namespace App\Http\Requests\Email;

use App\Models\SmtpSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveSmtpSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', SmtpSetting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // La password è obbligatoria solo al primo salvataggio (campo vuoto = mantieni la precedente)
        $hasSettings = SmtpSetting::query()->exists();

        return [
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_user' => ['nullable', 'string', 'max:255'],
            'smtp_pass' => [$hasSettings ? 'nullable' : 'required', 'string', 'max:255'],
            'smtp_encryption' => ['required', Rule::in(SmtpSetting::ENCRYPTIONS)],
            'from_email' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to_email' => ['nullable', 'email:rfc', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:255'],
            'max_per_hour' => ['required', 'integer', 'min:1', 'max:100000'],
            'auto_benvenuto' => ['sometimes', 'boolean'],
            'auto_scadenza_tessera' => ['sometimes', 'boolean'],
            'auto_scadenza_quota' => ['sometimes', 'boolean'],
            'auto_rinnovo_tessera' => ['sometimes', 'boolean'],
            'auto_pagamento_quota' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'smtp_host' => 'host SMTP',
            'smtp_port' => 'porta SMTP',
            'smtp_user' => 'utente SMTP',
            'smtp_pass' => 'password SMTP',
            'smtp_encryption' => 'crittografia',
            'from_email' => 'email mittente',
            'from_name' => 'nome mittente',
            'reply_to_email' => 'email di risposta',
            'reply_to_name' => 'nome di risposta',
            'max_per_hour' => 'limite invii orario',
        ];
    }
}
