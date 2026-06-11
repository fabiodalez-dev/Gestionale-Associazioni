<?php

namespace App\Http\Requests\Prenotazioni;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Check-in di un biglietto (prenotazione) dallo scanner admin:
 * identificazione via qr_token (dal QR) o codice PRN (inserimento manuale).
 * evento_id è opzionale: se fornito, il controller verifica che il
 * biglietto appartenga all'evento selezionato sullo scanner.
 */
class CheckinBigliettoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || $user->can('eventi.update')
            || $user->can('tessere.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_token' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i', 'required_without:codice'],
            'codice' => ['nullable', 'string', 'max:20', 'required_without:qr_token'],
            'evento_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'qr_token.required_without' => 'Fornisci il QR del biglietto o il codice prenotazione.',
            'codice.required_without' => 'Fornisci il QR del biglietto o il codice prenotazione.',
            'qr_token.regex' => 'Token biglietto non valido.',
        ];
    }

    /**
     * Il client è fetch/JSON (scanner): errori di validazione in JSON 422,
     * non redirect (pattern CheckinTesseraRequest).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
            'code' => 'validation_error',
        ], 422));
    }
}
