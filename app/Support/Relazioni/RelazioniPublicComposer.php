<?php

namespace App\Support\Relazioni;

use App\Models\Evento;
use App\Models\Socio;
use App\Models\Sponsor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\View;

/**
 * View composer del modulo relazioni: inietta in viste di ALTRI moduli senza
 * modificarne i file (estensione, non modifica). Registrato al boot dal file
 * di rotte routes/modules/relazioni.php.
 *
 * - pubblico.home          → sponsor visibili nel periodo ("I nostri sostenitori", f.22);
 * - pubblico.eventi.show   → galleria foto pubblica dell'evento, se attiva (f.24);
 * - area-soci.dashboard    → link referral del socio loggato (guard socio, f.23);
 * - pubblico.account.dashboard → link referral dell'account socio (guard account, f.23).
 *
 * Le viste di destinazione includono i partial con @includeIf: se il modulo
 * non è caricato non succede nulla; quando lo è, ricevono le variabili qui sotto.
 */
class RelazioniPublicComposer
{
    public static function register(): void
    {
        // f.22 — "I nostri sostenitori" sulla home pubblica del mini-sito
        ViewFacade::composer('pubblico.home', function (View $view) {
            $view->with('sostenitori', Sponsor::query()
                ->visibiliSulSito()
                ->orderByRaw(self::ordineLivelloSql())
                ->orderBy('nome')
                ->get());
        });

        // f.24 — griglia foto pubblica sulla pagina evento (solo se attiva)
        ViewFacade::composer('pubblico.eventi.show', function (View $view) {
            /** @var ?Evento $evento */
            $evento = $view->getData()['evento'] ?? null;

            $foto = ($evento instanceof Evento && $evento->galleria_pubblica)
                ? $evento->getMedia(Evento::COLLEZIONE_GALLERIA)
                : collect();

            $view->with('galleriaPubblica', $foto);
        });

        // f.23 — link referral "porta un amico" nelle aree riservate
        ViewFacade::composer(
            ['area-soci.dashboard', 'pubblico.account.dashboard'],
            function (View $view) {
                $view->with('socioReferral', self::referralDelSocioLoggato());
            }
        );
    }

    /**
     * Socio attualmente loggato in area riservata (guard socio o account),
     * o null. Usato per mostrare il link di invito personale.
     */
    private static function referralDelSocioLoggato(): ?Socio
    {
        // Guard 'socio' (area-soci): l'utente È un Socio
        $socio = auth('socio')->user();
        if ($socio instanceof Socio) {
            return $socio;
        }

        // Guard 'account' (frontend pubblico): l'account può essere collegato a un socio
        $account = auth('account')->user();
        if ($account instanceof Authenticatable && $account->socio_id !== null) {
            return Socio::query()->find($account->socio_id);
        }

        return null;
    }

    /**
     * Ordinamento per importanza del livello (oro → partner) portabile
     * fra SQLite e MySQL.
     */
    private static function ordineLivelloSql(): string
    {
        return 'CASE livello '
            ."WHEN '".Sponsor::LIVELLO_ORO."' THEN 1 "
            ."WHEN '".Sponsor::LIVELLO_ARGENTO."' THEN 2 "
            ."WHEN '".Sponsor::LIVELLO_BRONZO."' THEN 3 "
            .'ELSE 4 END';
    }
}
