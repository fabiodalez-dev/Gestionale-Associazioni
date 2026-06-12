<?php

namespace App\Http\Requests\Comunicazione;

use App\Models\Annuncio;
use App\Models\Evento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/aggiornamento annuncio in bacheca (f.7): titolo, corpo HTML
 * (purificato nel controller via EmailHtmlSanitizer), finestra di
 * pubblicazione opzionale, evidenza e visibilità solo-soci.
 * L'autorizzazione (email.create/update) è nella AnnuncioPolicy.
 *
 * Addendum convocazioni: tipo 'convocazione' richiede data_assemblea;
 * luogo/OdG opzionali; evento_id deve esistere tra gli eventi del tenant
 * corrente (la query Evento è tenant-scoped dal global scope).
 */
class AnnuncioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $tipo = (string) $this->input('tipo', Annuncio::TIPO_COMUNICAZIONE);

        $this->merge([
            'tipo' => $tipo,
            'in_evidenza' => $this->boolean('in_evidenza'),
            'solo_soci' => $this->boolean('solo_soci'),
        ]);

        // I dettagli assemblea hanno senso solo per le convocazioni:
        // azzerarli evita residui se l'annuncio viene riportato a comunicazione.
        if ($tipo !== Annuncio::TIPO_CONVOCAZIONE) {
            $this->merge([
                'data_assemblea' => null,
                'luogo_assemblea' => null,
                'ordine_del_giorno' => null,
                'evento_id' => null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(Annuncio::TIPI)],
            'corpo_html' => ['required', 'string', 'max:65000'],
            'data_assemblea' => ['nullable', 'required_if:tipo,'.Annuncio::TIPO_CONVOCAZIONE, 'date'],
            'luogo_assemblea' => ['nullable', 'string', 'max:255'],
            'ordine_del_giorno' => ['nullable', 'string', 'max:65000'],
            'evento_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // Tenant-scoped dal global scope di Evento (utente admin autenticato)
                    if (! Evento::query()->whereKey($value)->exists()) {
                        $fail('L\'evento selezionato non esiste.');
                    }
                },
            ],
            'pubblicato_dal' => ['nullable', 'date'],
            'pubblicato_al' => ['nullable', 'date', 'after_or_equal:pubblicato_dal'],
            'in_evidenza' => ['required', 'boolean'],
            'solo_soci' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'titolo' => 'titolo',
            'tipo' => 'tipo',
            'corpo_html' => 'contenuto',
            'data_assemblea' => 'data assemblea',
            'luogo_assemblea' => 'luogo assemblea',
            'ordine_del_giorno' => 'ordine del giorno',
            'evento_id' => 'evento collegato',
            'pubblicato_dal' => 'pubblicato dal',
            'pubblicato_al' => 'pubblicato fino al',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'data_assemblea.required_if' => 'La data dell\'assemblea è obbligatoria per le convocazioni.',
        ];
    }
}
