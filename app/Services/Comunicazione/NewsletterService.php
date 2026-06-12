<?php

namespace App\Services\Comunicazione;

use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\NewsletterInviata;
use App\Models\Utente;
use App\Services\Email\EmailLayout;
use App\Services\Email\EmailService;
use App\Services\Email\RecipientSelector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Newsletter composta da annunci pubblicati (f.8 piano Gestione 2).
 *
 * Destinatari: soci attivi con email e senza opt-out (stessa regola delle
 * comunicazioni massive — RecipientSelector 'all'). L'invio passa dalla
 * coda email per tenant (email_queue) con layout brandizzato e link di
 * disiscrizione per socio; lo storico finisce in newsletter_inviate.
 */
class NewsletterService
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly RecipientSelector $recipients,
        private readonly EmailLayout $layout,
    ) {}

    /**
     * Gli annunci PUBBLICATI del tenant tra gli id richiesti,
     * nell'ordine di selezione.
     *
     * @param  list<string>  $ids
     * @return Collection<int, Annuncio>
     */
    public function annunciPubblicati(string $associazioneId, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            /** @var Collection<int, Annuncio> */
            return new Collection;
        }

        return Annuncio::forAssociazione($associazioneId)
            ->pubblicati()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Annuncio $annuncio) => array_search($annuncio->id, $ids, true))
            ->values();
    }

    /**
     * Corpo HTML della newsletter (senza layout): titoli + corpo degli
     * annunci, già purificati al salvataggio.
     *
     * @param  Collection<int, Annuncio>  $annunci
     */
    public function componiHtml(Collection $annunci, string $titolo): string
    {
        return view('comunicazione.emails.newsletter', [
            'titolo' => $titolo,
            'annunci' => $annunci,
        ])->render();
    }

    /**
     * Accoda la newsletter ai soci attivi con email senza opt-out e
     * registra lo storico. Ritorna il record di storico (inviata_a = 0
     * se nessun destinatario eleggibile: nessuna riga accodata).
     *
     * @param  Collection<int, Annuncio>  $annunci
     */
    public function invia(Associazione $associazione, Collection $annunci, string $titolo, ?Utente $inviataDa = null): NewsletterInviata
    {
        $body = $this->componiHtml($annunci, $titolo);
        $destinatari = $this->recipients->recipients($associazione->id, 'all');
        $batchId = (string) Str::uuid();

        return DB::transaction(function () use ($associazione, $annunci, $titolo, $body, $destinatari, $batchId, $inviataDa): NewsletterInviata {
            foreach ($destinatari as $socio) {
                $this->emailService->queueEmail(
                    $associazione->id,
                    $socio->email,
                    trim($socio->nome.' '.$socio->cognome),
                    $titolo,
                    $this->layout->wrap($body, $associazione, $socio),
                    $socio->id,
                    $batchId,
                    'newsletter'
                );
            }

            return NewsletterInviata::create([
                'associazione_id' => $associazione->id,
                'titolo' => $titolo,
                'annunci_ids' => $annunci->pluck('id')->all(),
                'inviata_a' => $destinatari->count(),
                'inviata_at' => now(),
                'inviata_da' => $inviataDa?->id,
            ]);
        });
    }
}
