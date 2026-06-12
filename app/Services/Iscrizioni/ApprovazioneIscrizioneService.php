<?php

namespace App\Services\Iscrizioni;

use App\Models\Account;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Notifications\Iscrizioni\IscrizioneRifiutataNotification;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Notifications\Notification as NotificationMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestione admin delle richieste di iscrizione socio (PIANO-ISCRIZIONI
 * §Admin, pagina "Richieste iscrizione"):
 *
 * - approva(): socio preiscritto → 'Attivo', account collegato
 *   (account.socio_id), tessera opzionale via
 *   PaymentService::createTesseraForSocio, richiesta → approvata
 *   con gestita_da/gestita_at;
 * - rifiuta(): motivo obbligatorio, il socio preiscritto creato dal form
 *   viene ELIMINATO (forceDelete: valori campi personalizzati e storico
 *   seguono via FK cascade), richiesta → rifiutata; richiesta_socio_at
 *   dell'account azzerato così può ripresentare la domanda.
 *
 * Tutto in transazione con lockForUpdate sulla riga della richiesta
 * (doppio click / due admin concorrenti → il secondo riceve
 * IscrizioneException::richiestaGiaGestita). Le notifiche all'account
 * partono SEMPRE dopo il commit, best-effort.
 */
class ApprovazioneIscrizioneService
{
    public function __construct(private readonly PaymentService $pagamenti) {}

    /**
     * Approva la richiesta. Ritorna la richiesta aggiornata; la tessera
     * eventualmente generata è esposta in ->tesseraGenerata (null se non
     * richiesta o già esistente per l'anno).
     *
     * La delibera di ammissione (f.14 del piano vita sociale) è opzionale:
     * se indicata viene salvata sulla richiesta (e quindi mostrata nel
     * libro soci) e riportata nello storico attività del socio.
     *
     * @throws IscrizioneException
     */
    public function approva(
        RichiestaIscrizione $richiesta,
        Utente $utente,
        bool $generaTessera = false,
        ?string $deliberaNumero = null,
        ?string $deliberaData = null,
    ): RichiestaIscrizione {
        /** @var Tessera|null $tessera */
        $tessera = null;

        $risultato = DB::transaction(function () use ($richiesta, $utente, $generaTessera, $deliberaNumero, $deliberaData, &$tessera): RichiestaIscrizione {
            $richiesta = $this->lockRichiesta($richiesta);

            if (! $richiesta->isInAttesa()) {
                throw IscrizioneException::richiestaGiaGestita();
            }

            $socio = $this->socioPreiscritto($richiesta);
            $account = $this->account($richiesta);

            if ($account !== null && $account->socio_id !== null && $account->socio_id !== $socio->id) {
                throw IscrizioneException::accountGiaCollegato();
            }

            // Socio "In Attesa" → "Attivo"
            $socio->forceFill(['stato' => Socio::STATO_ATTIVO])->save();

            StoricoAttivitaSocio::create([
                'associazione_id' => $richiesta->associazione_id,
                'socio_id' => $socio->id,
                'tipo_attivita' => 'Approvazione iscrizione',
                'descrizione' => 'Richiesta di iscrizione dal sito approvata da '
                    .trim(($utente->nome ?? '').' '.($utente->cognome ?? ''))
                    .(filled($deliberaNumero)
                        ? sprintf(' (delibera di ammissione n. %s%s)', $deliberaNumero, filled($deliberaData) ? ' del '.Carbon::parse($deliberaData)->format('d/m/Y') : '')
                        : '')
                    .'.',
                'dettagli_json' => filled($deliberaNumero) ? [
                    'delibera_numero' => $deliberaNumero,
                    'delibera_data' => $deliberaData,
                ] : null,
                'data_attivita' => now(),
            ]);

            // Collega l'account del frontend pubblico al socio
            $account?->forceFill(['socio_id' => $socio->id])->save();

            if ($generaTessera) {
                // Idempotente per (associazione, socio, anno): null se già emessa
                $tessera = $this->pagamenti->createTesseraForSocio($socio);
            }

            $richiesta->forceFill([
                'stato' => RichiestaIscrizione::STATO_APPROVATA,
                'gestita_da' => $utente->id,
                'gestita_at' => now(),
                // Delibera di ammissione opzionale (f.14): mostrata nel libro soci
                'delibera_numero' => filled($deliberaNumero) ? $deliberaNumero : null,
                'delibera_data' => filled($deliberaData) ? $deliberaData : null,
            ])->save();

            return $richiesta;
        });

        $this->notificaAccount($risultato, new IscrizioneApprovataNotification(
            $risultato,
            $tessera?->numero_tessera,
            $tessera?->id
        ));

        return $risultato;
    }

    /**
     * Rifiuta la richiesta con motivo obbligatorio ed elimina il socio
     * preiscritto creato dal form (l'account resta e può riprovare).
     *
     * @throws IscrizioneException
     */
    public function rifiuta(RichiestaIscrizione $richiesta, Utente $utente, string $motivo): RichiestaIscrizione
    {
        $risultato = DB::transaction(function () use ($richiesta, $utente, $motivo): RichiestaIscrizione {
            $richiesta = $this->lockRichiesta($richiesta);

            if (! $richiesta->isInAttesa()) {
                throw IscrizioneException::richiestaGiaGestita();
            }

            $socioId = $richiesta->socio_id;

            // La richiesta va aggiornata PRIMA del forceDelete del socio
            // (la FK nullOnDelete azzererebbe socio_id solo sulla riga DB)
            $richiesta->forceFill([
                'stato' => RichiestaIscrizione::STATO_RIFIUTATA,
                'motivo_rifiuto' => $motivo,
                'socio_id' => null,
                'gestita_da' => $utente->id,
                'gestita_at' => now(),
            ])->save();

            // Elimina il socio preiscritto creato dal form (definitivo:
            // niente cestino; valori campi personalizzati e storico
            // attività seguono via FK cascade)
            if ($socioId !== null) {
                Socio::allTenants()->withTrashed()->find($socioId)?->forceDelete();
            }

            // L'account può presentare una nuova richiesta (e sparisce dal
            // contatore "richieste socio pendenti" della lista account)
            $this->account($richiesta)?->forceFill(['richiesta_socio_at' => null])->save();

            return $richiesta;
        });

        $this->notificaAccount($risultato, new IscrizioneRifiutataNotification($risultato));

        return $risultato;
    }

    // ------------------------------------------------------------------
    // Helper interni
    // ------------------------------------------------------------------

    /**
     * Rilettura della richiesta sotto lock (stato fresco, niente doppia
     * gestione concorrente).
     */
    protected function lockRichiesta(RichiestaIscrizione $richiesta): RichiestaIscrizione
    {
        return RichiestaIscrizione::query()
            ->withoutGlobalScope('associazione')
            ->whereKey($richiesta->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @throws IscrizioneException
     */
    protected function socioPreiscritto(RichiestaIscrizione $richiesta): Socio
    {
        $socio = $richiesta->socio_id !== null
            ? Socio::allTenants()->find($richiesta->socio_id)
            : null;

        if ($socio === null) {
            throw IscrizioneException::socioPreiscrittoMancante();
        }

        return $socio;
    }

    protected function account(RichiestaIscrizione $richiesta): ?Account
    {
        return $richiesta->account()
            ->withoutGlobalScope('associazione')
            ->first();
    }

    /**
     * Invio best-effort all'account della richiesta, SEMPRE dopo il commit:
     * un errore SMTP non fa mai rollback dell'approvazione/rifiuto.
     */
    protected function notificaAccount(RichiestaIscrizione $richiesta, NotificationMessage $notifica): void
    {
        try {
            $account = $richiesta->account()
                ->withoutGlobalScope('associazione')
                ->first();

            if ($account === null || ! $account->attivo) {
                return;
            }

            $account->notify($notifica);
        } catch (\Throwable $e) {
            Log::error('ApprovazioneIscrizioneService: invio notifica fallito', [
                'richiesta_id' => $richiesta->id,
                'notifica' => $notifica::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
