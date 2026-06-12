<?php

namespace App\Services\Piattaforma;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\SmtpSetting;
use App\Models\Socio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Checklist di onboarding del tenant (f.23 del piano "Gestione 2"):
 * ogni passo è calcolato dallo STATO REALE dell'associazione (niente flag
 * salvati per i passi), con link diretto alla pagina dove completarlo.
 *
 * Il dismiss della card è persistente per tenant (Cache::forever, quindi
 * sopravvive a sessione e logout); al 100% la card sparisce da sola.
 */
class OnboardingChecklist
{
    /**
     * Stato della checklist per il tenant.
     *
     * @return array{passi: list<array{chiave: string, etichetta: string, fatto: bool, url: string}>, completati: int, totale: int, percentuale: int, completa: bool}
     */
    public function per(Associazione $associazione): array
    {
        $passi = [
            [
                'chiave' => 'logo',
                'etichetta' => 'Carica il logo dell\'associazione',
                'fatto' => filled($associazione->logo_url),
                'url' => $this->url('configurazioni.index'),
            ],
            [
                'chiave' => 'slug',
                'etichetta' => 'Imposta lo slug pubblico',
                'fatto' => filled($associazione->slug),
                'url' => $this->url('configurazioni.index'),
            ],
            [
                'chiave' => 'smtp',
                'etichetta' => 'Configura l\'SMTP per le email',
                'fatto' => SmtpSetting::query()
                    ->withoutGlobalScope('associazione')
                    ->where('associazione_id', $associazione->id)
                    ->whereNotNull('smtp_host')
                    ->exists(),
                'url' => $this->url('email-impostazioni.index'),
            ],
            [
                'chiave' => 'costo_tessera',
                'etichetta' => 'Definisci il costo della tessera',
                'fatto' => (float) $associazione->costo_tessera > 0,
                'url' => $this->url('configurazioni.index'),
            ],
            [
                'chiave' => 'form_iscrizione',
                'etichetta' => 'Configura il form di iscrizione',
                'fatto' => filled($associazione->iscrizione_form_config),
                'url' => $this->url('configurazioni.modulo-iscrizione'),
            ],
            [
                'chiave' => 'primo_evento',
                'etichetta' => 'Pubblica il primo evento',
                'fatto' => Evento::query()
                    ->withoutGlobalScope('associazione')
                    ->where('associazione_id', $associazione->id)
                    ->pubblicati()
                    ->exists(),
                'url' => $this->url('eventi.index'),
            ],
            [
                'chiave' => 'primo_socio',
                'etichetta' => 'Registra il primo socio',
                'fatto' => Socio::query()
                    ->withoutGlobalScope('associazione')
                    ->where('associazione_id', $associazione->id)
                    ->exists(),
                'url' => $this->url('soci.index'),
            ],
        ];

        $completati = count(array_filter($passi, fn (array $passo): bool => $passo['fatto']));
        $totale = count($passi);

        return [
            'passi' => $passi,
            'completati' => $completati,
            'totale' => $totale,
            'percentuale' => (int) round($completati * 100 / max(1, $totale)),
            'completa' => $completati === $totale,
        ];
    }

    /**
     * La card va mostrata? Solo se non dismessa e non al 100%.
     */
    public function visibile(Associazione $associazione): bool
    {
        if ($this->dismessa($associazione)) {
            return false;
        }

        return ! $this->per($associazione)['completa'];
    }

    public function dismessa(Associazione $associazione): bool
    {
        return (bool) Cache::get($this->chiaveDismiss($associazione));
    }

    public function dismetti(Associazione $associazione): void
    {
        Cache::forever($this->chiaveDismiss($associazione), true);
    }

    protected function chiaveDismiss(Associazione $associazione): string
    {
        return "onboarding.dismessa.{$associazione->id}";
    }

    /**
     * URL del passo, tollerante verso moduli non ancora registrati
     * (stesso pattern $nav della sidebar).
     */
    protected function url(string $routeName): string
    {
        return Route::has($routeName) ? route($routeName) : '#';
    }
}
