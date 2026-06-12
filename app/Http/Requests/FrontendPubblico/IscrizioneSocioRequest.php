<?php

namespace App\Http\Requests\FrontendPubblico;

use App\Models\Associazione;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Iscrizione socio dal form pubblico /a/{slug}/iscriviti (guard 'account').
 *
 * Le regole sono DINAMICHE: arrivano da FormIscrizioneService::rules()
 * in base allo schema configurato dal tenant (campi standard visibili/
 * obbligatori + campi personalizzati con i loro tipi reali).
 * Honeypot (spatie) e throttle 5/m sono middleware di rotta.
 */
class IscrizioneSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Aperto anche agli ospiti: l'anti-abuso è su honeypot + throttle
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(FormIscrizioneService $form): array
    {
        $associazione = $this->associazioneCorrente();

        $dataNascita = $this->input('data_nascita');

        return $associazione !== null
            ? $form->rules(
                $associazione,
                ospite: $this->user('account') === null,
                // f.22: tutore obbligatorio se l'età dichiarata è < 18
                dataNascita: is_string($dataNascita) ? $dataNascita : null,
            )
            : [];
    }

    /**
     * Nomi leggibili nei messaggi di errore: label italiane per i campi
     * standard, nome configurato per i campi personalizzati.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributi = [];

        foreach (FormIscrizioneService::LABELS as $campo => $label) {
            $attributi[$campo] = mb_strtolower($label);
        }

        $associazione = $this->associazioneCorrente();

        if ($associazione !== null) {
            $schema = app(FormIscrizioneService::class)->schema($associazione);

            foreach ($schema['campi_personalizzati'] as $campo) {
                $attributi['custom_field.'.$campo->id] = $campo->nome_campo;
            }
        }

        return $attributi;
    }

    /**
     * Tenant corrente dal parametro di rotta {associazione:slug}.
     */
    public function associazioneCorrente(): ?Associazione
    {
        $associazione = $this->route('associazione');

        return $associazione instanceof Associazione ? $associazione : null;
    }
}
