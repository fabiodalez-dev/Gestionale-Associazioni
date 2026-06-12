<?php

namespace App\Services\Rinnovi;

use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Email\EmailService;
use Illuminate\Support\Collection;

/**
 * Campagna rinnovi {anno} (f.2 del piano Vita sociale).
 *
 * Destinatari: soci Attivi del tenant senza tessera per l'anno della
 * campagna. Per ogni socio selezionato l'invio massivo crea (se mancante)
 * la quota dell'anno con link di pagamento personale e accoda l'email
 * nella coda per-tenant esistente (EmailService::queueEmail) con
 * template_codice "campagna_rinnovi_{anno}" — usato anche per la colonna
 * "inviata" e i contatori.
 */
class CampagnaRinnoviService
{
    public function __construct(
        private readonly RinnovoService $rinnovi,
        private readonly EmailService $email,
    ) {}

    public function templateCodice(int $anno): string
    {
        return 'campagna_rinnovi_'.$anno;
    }

    /**
     * Soci attivi del tenant senza tessera dell'anno, con stato campagna:
     * inviata (email accodata/inviata), pagata (quota anno pagata),
     * rinnovata (tessera anno emessa — di norma esce dalla lista).
     *
     * @return Collection<int, Socio> ogni socio ha gli attributi extra
     *                                campagna_inviata, campagna_pagata, quota_anno
     */
    public function sociDaRinnovare(Associazione $associazione, int $anno): Collection
    {
        $soci = Socio::query()
            ->forAssociazione($associazione->id)
            ->where('stato', Socio::STATO_ATTIVO)
            ->whereNotExists(function ($q) use ($associazione, $anno) {
                $q->selectRaw('1')
                    ->from('tessere')
                    ->whereColumn('tessere.socio_id', 'soci.id')
                    ->where('tessere.associazione_id', $associazione->id)
                    ->where('tessere.anno_validita', $anno);
            })
            ->with('tipoSocio')
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get();

        $inviate = $this->sociConEmailInviata($associazione, $anno);
        $quote = Quota::allTenants()
            ->where('associazione_id', $associazione->id)
            ->where('anno', $anno)
            ->whereIn('socio_id', $soci->pluck('id'))
            ->get()
            ->groupBy('socio_id');

        return $soci->each(function (Socio $socio) use ($inviate, $quote) {
            /** @var Collection<int, Quota> $quoteSocio */
            $quoteSocio = $quote->get($socio->id, collect());

            $socio->setAttribute('campagna_inviata', $inviate->contains($socio->id));
            $socio->setAttribute('campagna_pagata', $quoteSocio->contains(fn (Quota $q) => $q->isPagata()));
            $socio->setAttribute('quota_anno', $quoteSocio->sortBy('data_scadenza')->first());
        });
    }

    /**
     * Contatori della campagna: da rinnovare, inviate, pagate, rinnovate.
     *
     * @param  Collection<int, Socio>  $soci  risultato di sociDaRinnovare()
     * @return array{da_rinnovare: int, inviate: int, pagate: int, rinnovate: int}
     */
    public function contatori(Associazione $associazione, int $anno, Collection $soci): array
    {
        return [
            'da_rinnovare' => $soci->count(),
            'inviate' => $soci->filter(fn (Socio $s) => (bool) $s->getAttribute('campagna_inviata'))->count(),
            'pagate' => $soci->filter(fn (Socio $s) => (bool) $s->getAttribute('campagna_pagata'))->count(),
            'rinnovate' => Tessera::allTenants()
                ->where('associazione_id', $associazione->id)
                ->where('anno_validita', $anno)
                ->count(),
        ];
    }

    /**
     * Invio massivo: per ogni socio selezionato (attivo, con email,
     * senza opt-out, senza tessera dell'anno) crea/riusa la quota dell'anno
     * con payment_token valido e accoda l'email personale.
     *
     * @param  list<string>  $socioIds
     * @return array{accodate: int, saltati: int}
     */
    public function inviaMassivo(Associazione $associazione, int $anno, array $socioIds): array
    {
        $accodate = 0;
        $saltati = 0;

        $soci = Socio::query()
            ->forAssociazione($associazione->id)
            ->whereIn('id', $socioIds)
            ->where('stato', Socio::STATO_ATTIVO)
            ->with('tipoSocio')
            ->get();

        $saltati += count($socioIds) - $soci->count();

        foreach ($soci as $socio) {
            if (blank($socio->email) || $socio->email_opt_out || $this->rinnovi->haTesseraAnno($socio, $anno)) {
                $saltati++;

                continue;
            }

            $quota = $this->rinnovi->quotaPerRinnovo($socio);

            if ($quota === null) {
                $saltati++;

                continue;
            }

            $link = route('pagamento.show', ['token' => $quota->payment_token]);

            $this->email->queueEmail(
                $associazione->id,
                (string) $socio->email,
                $socio->nome_completo,
                "Rinnovo tesseramento {$anno} — {$associazione->nome}",
                $this->bodyEmail($associazione, $socio, $quota, $anno, $link),
                $socio->id,
                null,
                $this->templateCodice($anno)
            );

            $accodate++;
        }

        return ['accodate' => $accodate, 'saltati' => $saltati];
    }

    /**
     * ID dei soci con email campagna già accodata/inviata per l'anno
     * (qualunque stato tranne 'cancelled').
     *
     * @return Collection<int, string>
     */
    private function sociConEmailInviata(Associazione $associazione, int $anno): Collection
    {
        return EmailQueue::allTenants()
            ->where('associazione_id', $associazione->id)
            ->where('template_codice', $this->templateCodice($anno))
            ->where('status', '!=', EmailQueue::STATUS_CANCELLED)
            ->whereNotNull('socio_id')
            ->pluck('socio_id')
            ->unique()
            ->values();
    }

    private function bodyEmail(Associazione $associazione, Socio $socio, Quota $quota, int $anno, string $link): string
    {
        $importo = number_format((float) $quota->importo, 2, ',', '.');
        $scadenza = $quota->data_scadenza->format('d/m/Y');

        return '<p>Ciao '.e($socio->nome).',</p>'
            .'<p>è tempo di rinnovare il tuo tesseramento a <strong>'.e($associazione->nome).'</strong> per l\'anno <strong>'.$anno.'</strong>.</p>'
            .'<p>Quota di rinnovo: <strong>&euro; '.$importo.'</strong> — da pagare entro il <strong>'.$scadenza.'</strong>.</p>'
            .'<p><a href="'.e($link).'" style="display:inline-block;padding:10px 18px;background:#FF7B11;color:#fff;border-radius:6px;text-decoration:none;">Rinnova ora online</a></p>'
            .'<p>Oppure copia questo link nel browser:<br>'.e($link).'</p>'
            .'<p>Al pagamento la tua tessera '.$anno.' sarà emessa automaticamente.</p>'
            .'<p>Grazie,<br>'.e($associazione->nome).'</p>';
    }
}
