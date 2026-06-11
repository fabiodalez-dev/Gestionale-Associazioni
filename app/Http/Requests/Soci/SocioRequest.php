<?php

namespace App\Http\Requests\Soci;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Socio;
use App\Services\Soci\RequiredFieldsConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * Regole comuni di creazione/modifica socio (spec soci §1.3/§1.4/§8):
 * - whitelist stato;
 * - CF normalizzato UPPERCASE (vuoto → NULL), checksum reale (miglioramento 9)
 *   e unicità per associazione;
 * - telefono validato con laravel-phone (miglioramento 10);
 * - FK tipo/categoria/sede vincolate al tenant corrente;
 * - campi obbligatori configurabili (saltati per gli stati pending);
 * - campi personalizzati validati server-side per tipo (fix lacuna legacy);
 * - tag_ids validati come appartenenti al tenant (fix lacuna legacy).
 */
abstract class SocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via SocioPolicy nel controller
    }

    protected function prepareForValidation(): void
    {
        $cf = strtoupper(trim((string) $this->input('codice_fiscale', '')));

        $this->merge([
            'codice_fiscale' => $cf === '' ? null : $cf,
            'stato' => filled($this->input('stato')) ? $this->input('stato') : Socio::STATO_ATTIVO,
            'provincia' => strtoupper(trim((string) $this->input('provincia', ''))) ?: null,
            'privacy_consenso' => $this->boolean('privacy_consenso'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $associazioneId = $this->associazioneId();
        $socioId = $this->socioInModifica()?->id;

        $rules = [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('soci', 'email')
                    ->where('associazione_id', $associazioneId)
                    ->ignore($socioId),
            ],
            'data_nascita' => ['nullable', 'date', 'before_or_equal:today'],
            'data_iscrizione' => ['required', 'date'],
            'stato' => ['required', Rule::in(Socio::STATI)],
            'codice_fiscale' => [
                'nullable', 'string', 'max:16', 'codice_fiscale',
                Rule::unique('soci', 'codice_fiscale')
                    ->where('associazione_id', $associazioneId)
                    ->ignore($socioId),
            ],
            'telefono' => ['nullable', 'string', 'max:20', (new Phone)->country(['IT'])->lenient()],
            'indirizzo' => ['nullable', 'string', 'max:255'],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2'],
            'cap' => ['nullable', 'string', 'max:5'],
            'note' => ['nullable', 'string'],
            'privacy_consenso' => ['boolean'],
            'tipo_socio_id' => [
                'nullable',
                Rule::exists('tipi_socio', 'id')->where('associazione_id', $associazioneId),
            ],
            'categoria_socio_id' => [
                'nullable',
                Rule::exists('categorie_socio', 'id')->where('associazione_id', $associazioneId),
            ],
            'sede_id' => [
                'nullable',
                Rule::exists('sedi', 'id')->where('associazione_id', $associazioneId),
            ],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                Rule::exists('tags', 'id')->where('associazione_id', $associazioneId),
            ],
            'genera_tessera' => ['nullable', 'boolean'],
        ];

        if (! $this->isStatoPending()) {
            $rules = $this->applicaCampiObbligatoriConfigurabili($rules);
        }

        return array_merge($rules, $this->regoleCampiPersonalizzati());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(RequiredFieldsConfig::LABELS, [
            'nome' => 'Nome',
            'cognome' => 'Cognome',
            'email' => 'Email',
            'data_iscrizione' => 'Data di Iscrizione',
            'numero_socio' => 'Numero Socio',
            'stato' => 'Stato',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codice_fiscale.unique' => 'Esiste già un socio con lo stesso Codice Fiscale in questa associazione.',
            'codice_fiscale.codice_fiscale' => 'Il Codice Fiscale non è valido.',
        ];
    }

    /** Dati anagrafici pronti per il persist (escluse relazioni). */
    public function datiSocio(): array
    {
        return $this->safe()->except(['tag_ids', 'custom_field', 'genera_tessera', 'numero_socio'])
            + (filled($this->validated('numero_socio') ?? null) ? ['numero_socio' => $this->validated('numero_socio')] : []);
    }

    /** @return list<string> */
    public function tagIds(): array
    {
        return array_values((array) ($this->validated('tag_ids') ?? []));
    }

    /** @return array<string, mixed> campo_id => valore */
    public function campiPersonalizzati(): array
    {
        return (array) $this->input('custom_field', []);
    }

    public function isStatoPending(): bool
    {
        return in_array($this->input('stato'), [
            Socio::STATO_IN_ATTESA,
            Socio::STATO_IN_ATTESA_DI_PAGAMENTO,
        ], true);
    }

    protected function socioInModifica(): ?Socio
    {
        $socio = $this->route('socio');

        return $socio instanceof Socio ? $socio : null;
    }

    protected function associazioneId(): ?string
    {
        return $this->socioInModifica()->associazione_id ?? Socio::currentAssociazioneId();
    }

    /**
     * Aggiunge 'required' ai campi marcati obbligatori nella config del tenant (spec §8).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function applicaCampiObbligatoriConfigurabili(array $rules): array
    {
        $associazione = Associazione::find($this->associazioneId());
        $config = app(RequiredFieldsConfig::class)->per($associazione, RequiredFieldsConfig::CONTESTO_BACKEND);

        foreach ($config as $campo => $obbligatorio) {
            if (! $obbligatorio || ! isset($rules[$campo])) {
                continue;
            }

            if ($campo === 'privacy_consenso') {
                $rules[$campo] = ['accepted'];

                continue;
            }

            $rules[$campo] = array_values(array_merge(
                ['required'],
                array_filter($rules[$campo], fn ($r) => $r !== 'nullable')
            ));
        }

        return $rules;
    }

    /**
     * Validazione server-side dei campi personalizzati per tipo_campo (spec §5).
     *
     * @return array<string, mixed>
     */
    private function regoleCampiPersonalizzati(): array
    {
        $rules = ['custom_field' => ['nullable', 'array']];
        $pending = $this->isStatoPending();

        $campi = CampoPersonalizzato::allTenants()
            ->where('associazione_id', $this->associazioneId())
            ->get();

        foreach ($campi as $campo) {
            $key = "custom_field.{$campo->id}";

            $tipo = match ($campo->tipo_campo) {
                'email' => ['email'],
                'number' => ['numeric'],
                'date' => ['date'],
                'url' => ['url'],
                'select' => [Rule::in(array_filter(array_map('trim', explode("\n", (string) $campo->opzioni))))],
                'checkbox' => ['in:0,1,on,true'],
                default => ['string', 'max:1000'],
            };

            $rules[$key] = $campo->obbligatorio && ! $pending
                ? array_merge(['required'], $tipo)
                : array_merge(['nullable'], $tipo);
        }

        return $rules;
    }
}
