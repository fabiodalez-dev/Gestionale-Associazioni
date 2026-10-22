<?php

namespace App\Http\Requests\Struttura;

use App\Settings\ModuliSettings;
use Illuminate\Validation\Rule;

/**
 * Configurazioni → Moduli pubblici (admin dell'associazione).
 */
class ModuliRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colore_primario' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'mostra_logo' => ['nullable', 'boolean'],
            'titolo_preiscrizione' => ['nullable', 'string', 'max:150'],
            'intro_preiscrizione_html' => ['nullable', 'string', 'max:5000'],
            'messaggio_conferma' => ['nullable', 'string', 'max:500'],
            'campi_visibili' => ['nullable', 'array'],
            'campi_visibili.*' => [Rule::in(ModuliSettings::CAMPI_NASCONDIBILI)],
            'campi_personalizzati' => ['nullable', 'array'],
            'campi_personalizzati.*' => ['uuid', Rule::exists('campi_personalizzati', 'id')->where('associazione_id', $this->associazioneCorrente()?->id)],
            'consensi' => ['nullable', 'array', 'max:20'],
            'consensi.*.chiave' => ['nullable', 'string', 'max:100'],
            'consensi.*.testo' => ['nullable', 'string', 'max:500'],
            'consensi.*.link_url' => ['nullable', 'url:http,https', 'max:500'],
            'consensi.*.obbligatorio' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colore_primario.regex' => 'Scegli un colore valido.',
            'consensi.*.link_url.url' => 'Il link di un consenso deve iniziare con http:// o https://.',
        ];
    }
}
