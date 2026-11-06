<?php

namespace App\Http\Requests\ApiV1;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/soci/{id} — validazione con parità legacy.
 *
 * Codici errore: empty_body (body assente), invalid_json (JSON malformato),
 * invalid_stato (stato fuori whitelist), invalid_field (valore fuori schema:
 * tipo, lunghezza o campo obbligatorio nullo). L'autorizzazione (permesso soci:write)
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
        // Lunghezze e obbligatorietà allineate allo schema della tabella soci:
        // un valore fuori schema è un errore del client (400), non un 500 dal
        // database (MySQL strict: "Data too long" / NOT NULL) né un dato
        // troncato o non valido salvato in silenzio.
        return [
            'stato' => ['sometimes', 'string', Rule::in(self::STATI)],
            'nome' => ['sometimes', 'required', 'string', 'max:100'],
            'cognome' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:20'],
            'indirizzo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'citta' => ['sometimes', 'nullable', 'string', 'max:100'],
            'provincia' => ['sometimes', 'nullable', 'string', 'max:2'],
            'cap' => ['sometimes', 'nullable', 'string', 'max:5'],
            // TEXT: 65535 BYTE, non caratteri (max: conta i caratteri multibyte uno)
            'note' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('Il campo note supera la dimensione massima di 65535 byte.');
                }
            }],
        ];
    }

    /**
     * Errori di validazione nel formato dell'API v1 (codice invalid_stato o
     * invalid_field) invece del redirect web.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errori = $validator->errors();

        if ($errori->has('stato')) {
            throw new HttpResponseException(
                $this->errore('invalid_stato', 'Valore di stato non valido. Ammessi: '.implode(', ', self::STATI).'.')
            );
        }

        $campo = (string) array_key_first($errori->messages());

        throw new HttpResponseException(
            $this->errore('invalid_field', "Valore non valido per il campo '{$campo}': ".$errori->first($campo))
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
