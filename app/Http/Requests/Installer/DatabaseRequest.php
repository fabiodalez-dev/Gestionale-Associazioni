<?php

namespace App\Http\Requests\Installer;

use App\Rules\HostnameOIp;
use Illuminate\Foundation\Http\FormRequest;

class DatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // I valori finiscono nel .env: niente a capo né NUL (una riga
        // iniettata diventerebbe un'altra variabile d'ambiente)
        $unaRiga = 'not_regex:/[\r\n\0]/';
        // EnvEditor non sa quotare un valore che contiene sia l'apice singolo
        // sia ${ (interpolazione dotenv): meglio rifiutarlo qui che un 500
        $quotabile = function (string $attr, mixed $v, \Closure $fail): void {
            if (is_string($v) && str_contains($v, "'") && str_contains($v, '${')) {
                $fail('Il valore non può contenere insieme l\'apice singolo e la sequenza ${.');
            }
        };

        return [
            'host' => ['required', 'string', 'max:253', $unaRiga, new HostnameOIp],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'username' => ['required', 'string', 'max:64', $unaRiga, $quotabile],
            'password' => ['nullable', 'string', 'max:255', $unaRiga, $quotabile],
            'crea_database' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'database.regex' => 'Il nome del database può contenere solo lettere, numeri e underscore.',
            'host.not_regex' => 'Il campo host non può contenere a capo.',
            'username.not_regex' => 'Il nome utente non può contenere a capo.',
            'password.not_regex' => 'La password non può contenere a capo.',
        ];
    }
}
