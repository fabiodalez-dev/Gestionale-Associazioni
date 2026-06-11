<?php

namespace App\Http\Requests\ApiV1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/soci/{id} — validazione con parità legacy.
 *
 * Codici errore: empty_body (body assente), invalid_json (JSON malformato),
 * invalid_stato (stato fuori whitelist). L'autorizzazione (permesso soci:write)
 * è gestita dal middleware AuthenticateApiV1.
 */
class UpdateSocioApiRequest extends FormRequest
{
    /** Stati ammessi dall'API legacy. */
    public const STATI = ['Attivo', 'Sospeso', 'Radiato', 'Deceduto', 'Trasferito'];

    /** Campi aggiornabili via API (whitelist legacy). */
    public const CAMPI_AGGIORNABILI = [
        'nome', 'cognome', 'email', 'telefono', 'indirizzo',
        'citta', 'provincia', 'cap', 'stato', 'note',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Parsing esplicito del body raw per distinguere empty_body da invalid_json.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $raw = trim((string) $this->getContent());

        if ($raw === '') {
            throw new HttpResponseException($this->errore('empty_body', 'Body JSON mancante.'));
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new HttpResponseException($this->errore('invalid_json', 'Body JSON non valido.'));
        }

        if ($decoded === []) {
            throw new HttpResponseException($this->errore('empty_body', 'Body JSON vuoto.'));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stato' => ['sometimes', 'string', Rule::in(self::STATI)],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            $this->errore('invalid_stato', 'Valore di stato non valido. Ammessi: '.implode(', ', self::STATI).'.')
        );
    }

    private function errore(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], 400);
    }
}
