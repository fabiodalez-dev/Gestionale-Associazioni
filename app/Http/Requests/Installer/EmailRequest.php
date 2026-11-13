<?php

namespace App\Http\Requests\Installer;

use App\Rules\HostnameOIp;
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
        // I valori finiscono nel .env: niente a capo né NUL
        $unaRiga = 'not_regex:/[\r\n\0]/';
        // EnvEditor non sa quotare un valore che contiene sia l'apice singolo
        // sia ${ (interpolazione dotenv): meglio rifiutarlo qui che un 500
        $quotabile = function (string $attr, mixed $v, \Closure $fail): void {
            if (is_string($v) && str_contains($v, "'") && str_contains($v, '${')) {
                $fail('Il valore non può contenere insieme l\'apice singolo e la sequenza ${.');
            }
        };

        return [
            'driver' => ['required', 'in:log,smtp'],
            'from_address' => ['nullable', 'required_if:driver,smtp', 'email', 'max:255', $unaRiga],
            'from_name' => ['nullable', 'string', 'max:255', $unaRiga, $quotabile],
            'smtp_host' => ['nullable', 'required_if:driver,smtp', 'string', 'max:253', $unaRiga, new HostnameOIp],
            'smtp_port' => ['nullable', 'required_if:driver,smtp', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255', $unaRiga, $quotabile],
            'smtp_password' => ['nullable', 'string', 'max:255', $unaRiga, $quotabile],
            'smtp_encryption' => ['nullable', 'in:smtps,tls'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from_address.not_regex' => 'L\'indirizzo mittente non può contenere a capo.',
            'from_name.not_regex' => 'Il nome mittente non può contenere a capo.',
            'smtp_host.not_regex' => 'L\'host SMTP non può contenere a capo.',
            'smtp_username.not_regex' => 'Il nome utente SMTP non può contenere a capo.',
            'smtp_password.not_regex' => 'La password SMTP non può contenere a capo.',
        ];
    }
}
