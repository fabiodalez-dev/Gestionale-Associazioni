<?php

namespace App\Http\Requests\Struttura;

use App\Models\Associazione;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Validation\Rule;

/**
 * Salvataggio della configurazione "Modulo di iscrizione"
 * (PIANO-ISCRIZIONI §Rotte/Admin): valida la STRUTTURA dello schema
 * iscrizione_form_config. Autorizzazione con la policy dedicata
 * updateModuloIscrizione (struttura.update — operatore escluso).
 */
class UpdateModuloIscrizioneRequest extends ConfigurazioniRequest
{
    public function authorize(): bool
    {
        $associazione = $this->associazioneCorrente();

        return $associazione !== null
            && ($this->user()?->can('updateModuloIscrizione', $associazione) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'iscrizioni_socio_aperte' => ['nullable', 'boolean'],
            'modalita_iscrizione' => ['required', Rule::in(Associazione::MODALITA_ISCRIZIONE)],
            'intro_html' => ['nullable', 'string', 'max:10000'],

            // Builder campi standard: SOLO le chiavi della whitelist anagrafica
            'campi_standard' => ['required', 'array:'.implode(',', FormIscrizioneService::CAMPI_STANDARD)],
            'campi_standard.*' => ['array:visibile,obbligatorio,ordine'],
            'campi_standard.*.visibile' => ['nullable', 'boolean'],
            'campi_standard.*.obbligatorio' => ['nullable', 'boolean'],
            'campi_standard.*.ordine' => ['required', 'integer', 'min:1', 'max:99'],

            // Multiselect campi personalizzati: solo quelli del tenant corrente
            'campi_personalizzati' => ['nullable', 'array', 'max:100'],
            'campi_personalizzati.*' => [
                'uuid',
                'distinct',
                Rule::exists('campi_personalizzati', 'id')
                    ->where('associazione_id', $this->associazioneCorrente()?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'campi_standard.array' => 'Il modulo contiene campi standard non riconosciuti.',
            'campi_standard.*.ordine.required' => "Indicare l'ordine di ogni campo standard.",
            'campi_personalizzati.*.exists' => 'Uno dei campi personalizzati selezionati non è valido per questa associazione.',
        ];
    }
}
