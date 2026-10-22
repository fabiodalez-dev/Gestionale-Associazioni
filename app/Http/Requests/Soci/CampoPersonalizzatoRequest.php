<?php

namespace App\Http\Requests\Soci;

use App\Models\CampoPersonalizzato;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Definizione campi personalizzati (spec soci §5): nome unico per associazione,
 * tipo in whitelist, opzioni richieste solo per tipo 'select'.
 */
class CampoPersonalizzatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via CampoPersonalizzatoPolicy nel controller
    }

    protected function prepareForValidation(): void
    {
        $merge = ['obbligatorio' => $this->boolean('obbligatorio')];

        // Il flag "obbligatorio in preiscrizione" si imposta dalla pagina
        // Configurazioni → Campi obbligatori: il form di /config-campi non lo
        // invia, quindi se manca resta com'è invece di essere azzerato a ogni
        // modifica di nome/descrizione/tipo.
        if ($this->has('obbligatorio_preiscrizione')) {
            $merge['obbligatorio_preiscrizione'] = $this->boolean('obbligatorio_preiscrizione');
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome_campo' => [
                'required', 'string', 'max:100',
                Rule::unique('campi_personalizzati', 'nome_campo')
                    ->where('associazione_id', Socio::currentAssociazioneId())
                    ->ignore($this->route('campo')?->id),
            ],
            'tipo_campo' => ['required', Rule::in(CampoPersonalizzato::TIPI_CAMPO)],
            'descrizione' => ['nullable', 'string'],
            'opzioni' => ['nullable', 'string', 'required_if:tipo_campo,select'],
            'obbligatorio' => ['boolean'],
            'obbligatorio_preiscrizione' => ['boolean'],
            'ordine' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
