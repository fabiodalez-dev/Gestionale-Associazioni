<?php

namespace App\Http\Requests\Tessere;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Check-in di un socio a un evento tramite tessera (QR), numero tessera
 * o numero socio (porta dell'endpoint legacy POST /api/v1/eventi/{id}/checkin).
 */
class CheckinTesseraRequest extends FormRequest
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
        $associazioneId = (string) session('associazione_id');

        return [
            'evento_id' => [
                'required',
                'uuid',
                Rule::exists('eventi', 'id')
                    ->where('associazione_id', $associazioneId)
                    ->whereNull('deleted_at'),
            ],
            'tessera_id' => ['nullable', 'uuid', 'required_without_all:numero_tessera,numero_socio'],
            'numero_tessera' => ['nullable', 'string', 'max:50', 'required_without_all:tessera_id,numero_socio'],
            'numero_socio' => ['nullable', 'string', 'max:50', 'required_without_all:tessera_id,numero_tessera'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evento_id.exists' => 'Evento non valido per questa associazione.',
            'tessera_id.required_without_all' => 'Fornisci tessera, numero tessera o numero socio.',
        ];
    }

    /**
     * Il client del check-in è fetch/JSON: gli errori di validazione devono
     * essere JSON 422, non un redirect (il default web del progetto).
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
