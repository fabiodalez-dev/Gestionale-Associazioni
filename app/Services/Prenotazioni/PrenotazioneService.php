<?php

namespace App\Services\Prenotazioni;

use App\Models\Account;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Notifications\Prenotazioni\AnnullataNotification;
use App\Notifications\Prenotazioni\ConfermataNotification;
use App\Notifications\Prenotazioni\DaApprovareNotification;
use App\Notifications\Prenotazioni\EventoEsauritoNotification;
use App\Notifications\Prenotazioni\InApprovazioneNotification;
use App\Notifications\Prenotazioni\InAttesaPagamentoNotification;
use App\Notifications\Prenotazioni\InListaAttesaNotification;
use App\Notifications\Prenotazioni\PagamentoScadutoNotification;
use App\Notifications\Prenotazioni\PromemoriaEventoNotification;
use App\Notifications\Prenotazioni\PromozioneListaAttesaNotification;
use App\Notifications\Prenotazioni\RifiutataNotification;
use App\Services\EventiVita\VitaSocialeService;
use App\Services\Prenotazioni\Contracts\GestiscePagamentoPrenotazione;
use Illuminate\Notifications\Notification as NotificationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Macchina a stati delle prenotazioni eventi (docs/PIANO-PRENOTAZIONI.md).
 *
 * Stati: in_approvazione · in_attesa_pagamento · confermata · lista_attesa
 * · annullata · scaduta.
 *
 * Eventi con richiede_approvazione (docs/PIANO-ISCRIZIONI.md): la creazione
 * (e la promozione dalla lista d'attesa) produce 'in_approvazione' — posti
 * riservati, NESSUNA scadenza — e il flusso di pagamento parte solo dopo
 * approva(); rifiuta() annulla con motivo e promuove la lista d'attesa.
 *
 * Concorrenza (regola unica del piano): ogni operazione che consuma o
 * rilascia posti gira in DB::transaction acquisendo PRIMA lockForUpdate()
 * sulla riga `eventi` (mutex per-evento); il conteggio dei posti occupati
 * è sempre SUM(posti) WHERE stato IN (confermata, in_attesa_pagamento)
 * rifatto DENTRO il lock. Nessun contatore denormalizzato.
 *
 * Le notifiche NON vengono mai inviate dentro la transazione: i metodi
 * interni le accodano e i metodi pubblici le smaltiscono dopo il commit
 * (hook protetti notifica*(), collegati alle Notification nella fase
 * Rifiniture).
 */
class PrenotazioneService implements GestiscePagamentoPrenotazione
{
    /** Finestra di pagamento alla creazione di una prenotazione a pagamento. */
    public const MINUTI_FINESTRA_PAGAMENTO = 30;

    /** Finestra di pagamento dedicata ai promossi dalla lista d'attesa. */
    public const ORE_FINESTRA_PROMOZIONE = 24;

    /** Promemoria evento: prenotazioni confermate con evento tra 24h e 48h. */
    public const ORE_PROMEMORIA_MIN = 24;

    public const ORE_PROMEMORIA_MAX = 48;

    /**
     * Notifiche accodate dentro la transazione, inviate dopo il commit.
     *
     * @var list<array{0: string, 1: Prenotazione}>
     */
    protected array $notifichePendenti = [];

    // ------------------------------------------------------------------
    // Creazione
    // ------------------------------------------------------------------

    /**
     * Crea una prenotazione per l'account sull'evento.
     *
     * Esiti (decisi DENTRO il mutex per-evento):
     * - posti disponibili + evento gratuito  → confermata;
     * - posti disponibili + evento a pagamento → in_attesa_pagamento
     *   (posti riservati, scadenza now+30');
     * - evento pieno + lista d'attesa attiva → lista_attesa;
     * - evento pieno + lista d'attesa disattivata → PrenotazioneException.
     *
     * @throws PrenotazioneException
     */
    public function crea(Account $account, Evento $evento, int $posti = 1): Prenotazione
    {
        if ($account->associazione_id !== $evento->associazione_id) {
            throw PrenotazioneException::tenantNonCoerente();
        }

        $prenotazione = DB::transaction(function () use ($account, $evento, $posti): Prenotazione {
            // Mutex per-evento: tutte le decisioni sui posti avvengono qui dentro
            $evento = $this->lockEvento($evento->id);

            if (! $evento->isPrenotabile()) {
                throw PrenotazioneException::eventoNonPrenotabile();
            }

            // Evento riservato ai soci (f.10 piano Vita sociale): prenotabile
            // solo da account collegati a un socio Attivo del tenant
            if ($evento->isRiservatoAiSoci()
                && ! VitaSocialeService::accountAbilitatoSoloSoci($account)) {
                throw PrenotazioneException::riservatoAiSoci();
            }

            $maxPosti = max(1, (int) $evento->max_posti_per_prenotazione);

            if ($posti < 1 || $posti > $maxPosti) {
                throw PrenotazioneException::postiNonValidi($maxPosti);
            }

            // Doppia prenotazione attiva (confermata/in attesa/lista d'attesa)
            // sullo stesso account+evento: bloccata, check dentro la transazione
            $giaPrenotato = Prenotazione::query()
                ->withoutGlobalScope('associazione')
                ->where('evento_id', $evento->id)
                ->where('account_id', $account->id)
                ->whereIn('stato', [
                    Prenotazione::STATO_CONFERMATA,
                    Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
                    Prenotazione::STATO_IN_APPROVAZIONE,
                    Prenotazione::STATO_LISTA_ATTESA,
                ])
                ->exists();

            if ($giaPrenotato) {
                throw PrenotazioneException::doppiaPrenotazione();
            }

            $aPagamento = $evento->isAPagamento();
            $disponibili = $this->postiDisponibiliLocked($evento);

            if ($disponibili === null || $posti <= $disponibili) {
                if ($evento->richiede_approvazione) {
                    // Posti riservati, NESSUNA scadenza: l'eventuale pagamento
                    // parte solo dopo l'approvazione dell'admin
                    $stato = Prenotazione::STATO_IN_APPROVAZIONE;
                    $scadenzaPagamento = null;
                } else {
                    $stato = $aPagamento
                        ? Prenotazione::STATO_IN_ATTESA_PAGAMENTO
                        : Prenotazione::STATO_CONFERMATA;
                    $scadenzaPagamento = $aPagamento
                        ? now()->addMinutes(self::MINUTI_FINESTRA_PAGAMENTO)
                        : null;
                }
            } elseif ($evento->lista_attesa_attiva) {
                $stato = Prenotazione::STATO_LISTA_ATTESA;
                $scadenzaPagamento = null;
            } else {
                throw PrenotazioneException::postiEsauriti();
            }

            $prezzoUnitario = round((float) $evento->prezzo, 2);

            $prenotazione = Prenotazione::create([
                'associazione_id' => $evento->associazione_id,
                'evento_id' => $evento->id,
                'account_id' => $account->id,
                'codice' => $this->generaCodiceUnivoco($evento->associazione_id),
                'qr_token' => Prenotazione::generaQrToken(),
                'stato' => $stato,
                'posti' => $posti,
                // Snapshot del prezzo al momento della prenotazione
                'prezzo_unitario' => $prezzoUnitario,
                'importo_totale' => round($prezzoUnitario * $posti, 2),
                'scadenza_pagamento_at' => $scadenzaPagamento,
            ]);

            $this->accodaNotifica(match ($stato) {
                Prenotazione::STATO_CONFERMATA => 'notificaConfermata',
                Prenotazione::STATO_IN_ATTESA_PAGAMENTO => 'notificaInAttesaPagamento',
                Prenotazione::STATO_IN_APPROVAZIONE => 'notificaInApprovazione',
                default => 'notificaInListaAttesa',
            }, $prenotazione);

            // Richiesta da approvare: avviso anche agli admin del tenant
            if ($stato === Prenotazione::STATO_IN_APPROVAZIONE) {
                $this->accodaNotifica('notificaDaApprovare', $prenotazione);
            }

            // L'evento ha appena raggiunto la capienza (questa prenotazione
            // ha consumato esattamente gli ultimi posti): avviso agli admin
            if ($disponibili !== null && $disponibili > 0 && $posti === $disponibili) {
                $this->accodaNotifica('notificaEventoEsaurito', $prenotazione);
            }

            return $prenotazione;
        });

        $this->inviaNotifichePendenti();

        return $prenotazione;
    }

    // ------------------------------------------------------------------
    // Approvazione admin (eventi con richiede_approvazione)
    // ------------------------------------------------------------------

    /**
     * Approva una prenotazione 'in_approvazione' (azione admin).
     *
     * Esiti (decisi DENTRO il mutex per-evento):
     * - evento gratuito → confermata;
     * - evento a pagamento → in_attesa_pagamento con la finestra standard
     *   di 30 minuti (il flusso di pagamento parte ORA, non alla creazione).
     *
     * I posti erano già riservati dall'in_approvazione: nessun nuovo
     * consumo, nessuna promozione.
     *
     * @throws PrenotazioneException
     */
    public function approva(Prenotazione $prenotazione, Utente $utente): Prenotazione
    {
        $this->verificaTenantAttore($prenotazione, $utente);

        $risultato = DB::transaction(function () use ($prenotazione, $utente): Prenotazione {
            // Ordine di lock unico: evento, poi prenotazione
            $this->lockEvento($prenotazione->evento_id);

            $prenotazione = Prenotazione::query()
                ->withoutGlobalScope('associazione')
                ->whereKey($prenotazione->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($prenotazione->stato !== Prenotazione::STATO_IN_APPROVAZIONE) {
                throw PrenotazioneException::nonInApprovazione();
            }

            $aPagamento = (float) $prenotazione->importo_totale > 0;

            $prenotazione->forceFill([
                'stato' => $aPagamento
                    ? Prenotazione::STATO_IN_ATTESA_PAGAMENTO
                    : Prenotazione::STATO_CONFERMATA,
                'scadenza_pagamento_at' => $aPagamento
                    ? now()->addMinutes(self::MINUTI_FINESTRA_PAGAMENTO)
                    : null,
                'approvata_at' => now(),
                'approvata_da' => $utente->id,
            ])->save();

            $this->accodaNotifica(
                $aPagamento ? 'notificaInAttesaPagamento' : 'notificaConfermata',
                $prenotazione
            );

            return $prenotazione;
        });

        $this->inviaNotifichePendenti();

        return $risultato;
    }

    /**
     * Rifiuta una prenotazione 'in_approvazione' (azione admin, motivo
     * obbligatorio): passa ad annullata e i posti rilasciati promuovono
     * la lista d'attesa (FIFO stretto) nella stessa transazione/lock.
     *
     * @throws PrenotazioneException
     */
    public function rifiuta(Prenotazione $prenotazione, Utente $utente, string $motivo): Prenotazione
    {
        $this->verificaTenantAttore($prenotazione, $utente);

        $risultato = DB::transaction(function () use ($prenotazione, $motivo): Prenotazione {
            // Lock SEMPRE prima sull'evento (ordine unico anti-deadlock)...
            $evento = $this->lockEvento($prenotazione->evento_id);

            // ...poi rilettura della prenotazione sotto lock (stato fresco)
            $prenotazione = Prenotazione::query()
                ->withoutGlobalScope('associazione')
                ->whereKey($prenotazione->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($prenotazione->stato !== Prenotazione::STATO_IN_APPROVAZIONE) {
                throw PrenotazioneException::nonInApprovazione();
            }

            $prenotazione->forceFill([
                'stato' => Prenotazione::STATO_ANNULLATA,
                'motivo_rifiuto' => $motivo,
                'annullata_at' => now(),
                'motivo_annullamento' => 'Richiesta non approvata: '.$motivo,
            ])->save();

            $this->accodaNotifica('notificaRifiutata', $prenotazione);

            // L'in_approvazione consumava posti: il rilascio promuove la coda
            $this->promuovi($evento);

            return $prenotazione;
        });

        $this->inviaNotifichePendenti();

        return $risultato;
    }

    /**
     * L'admin può operare solo sulle prenotazioni del proprio tenant
     * (il super_admin, senza associazione, su tutte).
     *
     * @throws PrenotazioneException
     */
    protected function verificaTenantAttore(Prenotazione $prenotazione, Utente $utente): void
    {
        if ($utente->associazione_id !== null
            && $utente->associazione_id !== $prenotazione->associazione_id) {
            throw PrenotazioneException::tenantNonCoerente();
        }
    }

    // ------------------------------------------------------------------
    // Annullamento
    // ------------------------------------------------------------------

    /**
     * Annulla una prenotazione.
     *
     * Regole (piano):
     * - account (proprietario): in_attesa_pagamento e lista_attesa sempre;
     *   confermata solo entro data_evento − annullamento_entro_ore e senza
     *   check-in;
     * - admin (Utente): sempre, anche oltre il termine o dopo il check-in;
     * - annullata/scaduta: mai (transizione non ammessa).
     *
     * Se la prenotazione consumava posti, il rilascio promuove la lista
     * d'attesa (FIFO stretto) nella stessa transazione/lock.
     *
     * @throws PrenotazioneException
     */
    public function annulla(Prenotazione $prenotazione, Account|Utente $attore, ?string $motivo = null): Prenotazione
    {
        $risultato = DB::transaction(function () use ($prenotazione, $attore, $motivo): Prenotazione {
            // Lock SEMPRE prima sull'evento (ordine unico anti-deadlock)...
            $evento = $this->lockEvento($prenotazione->evento_id);

            // ...poi rilettura della prenotazione sotto lock (stato fresco)
            $prenotazione = Prenotazione::query()
                ->withoutGlobalScope('associazione')
                ->whereKey($prenotazione->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->verificaAnnullabile($prenotazione, $evento, $attore);

            $consumavaPosti = $prenotazione->isAttiva();

            $prenotazione->forceFill([
                'stato' => Prenotazione::STATO_ANNULLATA,
                'annullata_at' => now(),
                'motivo_annullamento' => $motivo ?? ($attore instanceof Utente
                    ? "Annullata dall'amministrazione"
                    : "Annullata dall'utente"),
            ])->save();

            $this->accodaNotifica('notificaAnnullata', $prenotazione);

            // Rilascio posti → promozione lista d'attesa (stesso lock)
            if ($consumavaPosti) {
                $this->promuovi($evento);
            }

            return $prenotazione;
        });

        $this->inviaNotifichePendenti();

        return $risultato;
    }

    /**
     * @throws PrenotazioneException
     */
    protected function verificaAnnullabile(Prenotazione $prenotazione, Evento $evento, Account|Utente $attore): void
    {
        if (in_array($prenotazione->stato, [Prenotazione::STATO_ANNULLATA, Prenotazione::STATO_SCADUTA], true)) {
            throw PrenotazioneException::nonAnnullabile('È già annullata o scaduta.');
        }

        // Admin: sempre (rimborsi manuali in v1)
        if ($attore instanceof Utente) {
            return;
        }

        if ($attore->id !== $prenotazione->account_id) {
            throw PrenotazioneException::nonAutorizzato();
        }

        if ($prenotazione->checkin_at !== null) {
            throw PrenotazioneException::nonAnnullabile('Il check-in è già stato effettuato.');
        }

        if ($prenotazione->stato === Prenotazione::STATO_CONFERMATA) {
            $ore = (int) $evento->annullamento_entro_ore;
            $termine = $evento->data_evento->copy()->subHours($ore);

            if (now()->greaterThan($termine)) {
                throw PrenotazioneException::annullamentoOltreTermine($ore);
            }
        }
    }

    // ------------------------------------------------------------------
    // Scadenza pagamenti (scheduler, ogni minuto)
    // ------------------------------------------------------------------

    /**
     * Marca 'scaduta' ogni in_attesa_pagamento con finestra superata e
     * promuove la lista d'attesa sui posti rilasciati. Una transazione
     * (con mutex) per evento. Ritorna il numero di prenotazioni scadute.
     */
    public function scadiPagamenti(): int
    {
        $eventoIds = Prenotazione::allTenants()
            ->pagamentoScaduto()
            ->distinct()
            ->pluck('evento_id');

        $totale = 0;

        foreach ($eventoIds as $eventoId) {
            $totale += DB::transaction(function () use ($eventoId): int {
                $evento = $this->lockEvento($eventoId);

                $scadute = Prenotazione::query()
                    ->withoutGlobalScope('associazione')
                    ->where('evento_id', $evento->id)
                    ->pagamentoScaduto()
                    ->lockForUpdate()
                    ->get();

                foreach ($scadute as $prenotazione) {
                    $prenotazione->forceFill(['stato' => Prenotazione::STATO_SCADUTA])->save();
                    $this->accodaNotifica('notificaPagamentoScaduto', $prenotazione);
                }

                if ($scadute->isNotEmpty()) {
                    $this->promuovi($evento);
                }

                return $scadute->count();
            });

            $this->inviaNotifichePendenti();
        }

        return $totale;
    }

    // ------------------------------------------------------------------
    // Promozione lista d'attesa
    // ------------------------------------------------------------------

    /**
     * Promuove la lista d'attesa di un evento (entry point standalone,
     * es. azione admin "promuovi"): apre transazione + mutex e delega
     * alla promozione interna.
     *
     * @return list<Prenotazione> le prenotazioni promosse
     */
    public function promuoviListaAttesa(string $eventoId): array
    {
        $promosse = DB::transaction(function () use ($eventoId): array {
            return $this->promuovi($this->lockEvento($eventoId));
        });

        $this->inviaNotifichePendenti();

        return $promosse;
    }

    /**
     * Promozione FIFO STRETTO: scorre la coda in ordine di arrivo e si
     * ferma al PRIMO che non entra nei posti rimasti (anche se uno
     * successivo più piccolo entrerebbe). Gratuito → confermata;
     * a pagamento → in_attesa_pagamento con finestra dedicata di 24h.
     * Eventi con richiede_approvazione: i promossi vanno in_approvazione
     * (posti riservati, nessuna scadenza, esito dopo la revisione admin).
     *
     * DEVE girare dentro una transazione che detiene già il lock
     * sulla riga eventi.
     *
     * @return list<Prenotazione>
     */
    protected function promuovi(Evento $evento): array
    {
        $coda = Prenotazione::query()
            ->withoutGlobalScope('associazione')
            ->where('evento_id', $evento->id)
            ->where('stato', Prenotazione::STATO_LISTA_ATTESA)
            ->orderBy('created_at')
            ->orderBy('id') // tie-break deterministico a parità di istante
            ->lockForUpdate()
            ->get();

        if ($coda->isEmpty()) {
            return [];
        }

        $occupati = $this->postiOccupatiLocked($evento);
        $promosse = [];

        foreach ($coda as $candidata) {
            if ($evento->capienza !== null && $occupati + $candidata->posti > $evento->capienza) {
                break; // FIFO stretto: il primo che non entra blocca la coda
            }

            $aPagamento = (float) $candidata->importo_totale > 0;

            if ($evento->richiede_approvazione) {
                // L'uscita dalla coda non salta la revisione: posti riservati,
                // nessuna scadenza, pagamento solo dopo l'approvazione
                $candidata->forceFill([
                    'stato' => Prenotazione::STATO_IN_APPROVAZIONE,
                    'promossa_at' => now(),
                    'scadenza_pagamento_at' => null,
                ])->save();
            } else {
                $candidata->forceFill([
                    'stato' => $aPagamento
                        ? Prenotazione::STATO_IN_ATTESA_PAGAMENTO
                        : Prenotazione::STATO_CONFERMATA,
                    'promossa_at' => now(),
                    'scadenza_pagamento_at' => $aPagamento
                        ? now()->addHours(self::ORE_FINESTRA_PROMOZIONE)
                        : null,
                ])->save();
            }

            $occupati += $candidata->posti;
            $promosse[] = $candidata;

            if ($evento->richiede_approvazione) {
                $this->accodaNotifica('notificaInApprovazione', $candidata);
                $this->accodaNotifica('notificaDaApprovare', $candidata);
            } else {
                $this->accodaNotifica('notificaPromozioneListaAttesa', $candidata);
            }
        }

        return $promosse;
    }

    // ------------------------------------------------------------------
    // Pagamento completato (webhook / return URL)
    // ------------------------------------------------------------------

    /**
     * Conferma una prenotazione a pagamento avvenuto. Idempotente: se la
     * prenotazione è già pagata il webhook viene ack-ato senza effetti
     * (replay sicuro). Pagamento arrivato su una prenotazione già SCADUTA
     * → riconferma comunque, anche in overbooking (il denaro è stato
     * incassato; l'eccedenza è visibile in admin).
     *
     * Ritorna null solo se la prenotazione non esiste per quel tenant
     * (il chiamante ack-a comunque il webhook).
     */
    public function onPagamentoCompletato(
        string $prenotazioneId,
        string $associazioneId,
        string $gateway,
        string $transactionId,
    ): ?Prenotazione {
        $risultato = DB::transaction(function () use ($prenotazioneId, $associazioneId, $gateway, $transactionId): ?Prenotazione {
            // Prima lettura senza lock: serve solo l'evento_id per il mutex
            $prenotazione = Prenotazione::allTenants()
                ->where('associazione_id', $associazioneId)
                ->whereKey($prenotazioneId)
                ->first();

            if ($prenotazione === null) {
                Log::warning('PrenotazioneService: pagamento per prenotazione inesistente', [
                    'prenotazione_id' => $prenotazioneId,
                    'associazione_id' => $associazioneId,
                    'gateway' => $gateway,
                ]);

                return null;
            }

            // Ordine di lock unico: evento, poi prenotazione
            $this->lockEvento($prenotazione->evento_id);

            $prenotazione = Prenotazione::query()
                ->withoutGlobalScope('associazione')
                ->whereKey($prenotazione->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotenza (webhook replay): già pagata → ack senza effetti
            if ($prenotazione->pagata_at !== null) {
                return $prenotazione;
            }

            $dati = [
                'gateway' => $gateway,
                'gateway_transaction_id' => $transactionId,
                'pagata_at' => now(),
            ];

            if (in_array($prenotazione->stato, [
                Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
                Prenotazione::STATO_SCADUTA, // riconferma post-scadenza: overbooking accettato
            ], true)) {
                $dati['stato'] = Prenotazione::STATO_CONFERMATA;
                $this->accodaNotifica('notificaConfermata', $prenotazione);
            } else {
                // Stato inatteso (es. annullata): registra l'incasso senza
                // cambiare stato, il follow-up (rimborso manuale) è in admin
                Log::warning('PrenotazioneService: pagamento su prenotazione non confermabile', [
                    'prenotazione_id' => $prenotazione->id,
                    'stato' => $prenotazione->stato,
                    'gateway' => $gateway,
                    'transaction_id' => $transactionId,
                ]);
            }

            $prenotazione->forceFill($dati)->save();

            return $prenotazione;
        });

        $this->inviaNotifichePendenti();

        return $risultato;
    }

    // ------------------------------------------------------------------
    // Promemoria eventi (scheduler, giornaliero)
    // ------------------------------------------------------------------

    /**
     * Invia il promemoria alle prenotazioni CONFERMATE di eventi che
     * iniziano tra 24h e 48h, se l'account ha la preferenza attiva
     * (default: attiva). La finestra di 24h con esecuzione giornaliera
     * garantisce un solo promemoria per prenotazione.
     *
     * Ritorna il numero di promemoria inviati.
     */
    public function inviaPromemoriaEventi(): int
    {
        $eventoIds = Evento::allTenants()
            ->whereBetween('data_evento', [
                now()->addHours(self::ORE_PROMEMORIA_MIN),
                now()->addHours(self::ORE_PROMEMORIA_MAX),
            ])
            ->pluck('id');

        if ($eventoIds->isEmpty()) {
            return 0;
        }

        $inviati = 0;

        Prenotazione::allTenants()
            ->whereIn('evento_id', $eventoIds)
            ->where('stato', Prenotazione::STATO_CONFERMATA)
            ->with(['account' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->orderBy('created_at')
            ->get()
            ->each(function (Prenotazione $prenotazione) use (&$inviati): void {
                $account = $prenotazione->account;

                if ($account === null || ! $account->attivo) {
                    return;
                }

                // Preferenza promemoria: attiva se non esplicitamente disattivata
                if (($account->preferenze['promemoria_eventi'] ?? true) === false) {
                    return;
                }

                $this->notificaPromemoriaEvento($prenotazione);
                $inviati++;
            });

        return $inviati;
    }

    // ------------------------------------------------------------------
    // Helper interni (lock, conteggi, codici)
    // ------------------------------------------------------------------

    /**
     * Mutex per-evento: SELECT ... FOR UPDATE sulla riga eventi.
     * Va chiamato come PRIMA acquisizione dentro la transazione.
     */
    protected function lockEvento(string $eventoId): Evento
    {
        return Evento::query()
            ->withoutGlobalScopes()
            ->whereKey($eventoId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Posti occupati DENTRO il lock: SUM(posti) sulle prenotazioni attive.
     */
    protected function postiOccupatiLocked(Evento $evento): int
    {
        return Prenotazione::postiOccupati($evento->id);
    }

    /**
     * Posti disponibili DENTRO il lock (null = capienza illimitata).
     */
    protected function postiDisponibiliLocked(Evento $evento): ?int
    {
        if ($evento->capienza === null) {
            return null;
        }

        return max(0, (int) $evento->capienza - $this->postiOccupatiLocked($evento));
    }

    /**
     * Codice prenotazione PRN-XXXXXX univoco per tenant (collisioni
     * rarissime su base32^6, ma il retry costa nulla).
     */
    protected function generaCodiceUnivoco(string $associazioneId): string
    {
        do {
            $codice = Prenotazione::generaCodice();

            $esiste = Prenotazione::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('codice', $codice)
                ->exists();
        } while ($esiste);

        return $codice;
    }

    // ------------------------------------------------------------------
    // Notifiche: accodate in transazione, inviate dopo il commit
    // ------------------------------------------------------------------

    protected function accodaNotifica(string $metodo, Prenotazione $prenotazione): void
    {
        $this->notifichePendenti[] = [$metodo, $prenotazione];
    }

    /**
     * Smaltisce le notifiche accodate. Chiamato dai metodi pubblici DOPO
     * il commit della transazione: un fallimento di invio non fa mai
     * rollback dei posti.
     */
    protected function inviaNotifichePendenti(): void
    {
        $pendenti = $this->notifichePendenti;
        $this->notifichePendenti = [];

        foreach ($pendenti as [$metodo, $prenotazione]) {
            try {
                $this->{$metodo}($prenotazione);
            } catch (\Throwable $e) {
                Log::error("PrenotazioneService: invio notifica {$metodo} fallito", [
                    'prenotazione_id' => $prenotazione->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /*
    |----------------------------------------------------------------------
    | Hook notifiche (App\Notifications\Prenotazioni\*, pattern
    | PagamentoQuotaNotification: ShouldQueue, database+mail per tenant).
    | Invocati SEMPRE fuori dalla transazione di dominio, best-effort:
    | un fallimento viene loggato senza propagarsi (inviaNotifichePendenti
    | e notificaAccount fanno entrambi da rete di protezione).
    |----------------------------------------------------------------------
    */

    protected function notificaConfermata(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new ConfermataNotification($prenotazione));
    }

    protected function notificaInAttesaPagamento(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new InAttesaPagamentoNotification($prenotazione));
    }

    protected function notificaInListaAttesa(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new InListaAttesaNotification($prenotazione));
    }

    protected function notificaPromozioneListaAttesa(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new PromozioneListaAttesaNotification($prenotazione));
    }

    protected function notificaPagamentoScaduto(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new PagamentoScadutoNotification($prenotazione));
    }

    protected function notificaAnnullata(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new AnnullataNotification($prenotazione));
    }

    protected function notificaInApprovazione(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new InApprovazioneNotification($prenotazione));
    }

    protected function notificaRifiutata(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new RifiutataNotification($prenotazione));
    }

    /**
     * Richiesta in approvazione: avviso agli amministratori attivi del
     * tenant (stesso destinatario di notificaEventoEsaurito). Best-effort,
     * fuori dalla transazione.
     */
    protected function notificaDaApprovare(Prenotazione $prenotazione): void
    {
        try {
            $admins = Utente::query()
                ->where('associazione_id', $prenotazione->associazione_id)
                ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                ->where('attivo', true)
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new DaApprovareNotification($prenotazione));
            }
        } catch (\Throwable $e) {
            Log::error('PrenotazioneService: notifica prenotazione da approvare fallita', [
                'prenotazione_id' => $prenotazione->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function notificaPromemoriaEvento(Prenotazione $prenotazione): void
    {
        $this->notificaAccount($prenotazione, new PromemoriaEventoNotification($prenotazione));
    }

    /**
     * Evento al completo: avviso agli amministratori attivi del tenant
     * (ruolo admin_associazione). Best-effort, fuori dalla transazione.
     */
    protected function notificaEventoEsaurito(Prenotazione $prenotazione): void
    {
        try {
            $evento = Evento::query()
                ->withoutGlobalScopes()
                ->find($prenotazione->evento_id);

            if ($evento === null) {
                return;
            }

            $admins = Utente::query()
                ->where('associazione_id', $evento->associazione_id)
                ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                ->where('attivo', true)
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new EventoEsauritoNotification($evento));
            }
        } catch (\Throwable $e) {
            Log::error('PrenotazioneService: notifica evento esaurito fallita', [
                'evento_id' => $prenotazione->evento_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invio best-effort all'account della prenotazione (caricato senza
     * global scope tenant: gli scheduler girano fuori dal contesto del
     * frontend). Gli account disattivati/anonimizzati non ricevono nulla.
     */
    protected function notificaAccount(Prenotazione $prenotazione, NotificationMessage $notifica): void
    {
        try {
            $account = $prenotazione->account()
                ->withoutGlobalScope('associazione')
                ->first();

            if ($account === null || ! $account->attivo) {
                return;
            }

            $account->notify($notifica);
        } catch (\Throwable $e) {
            Log::error('PrenotazioneService: invio notifica account fallito', [
                'prenotazione_id' => $prenotazione->id,
                'notifica' => $notifica::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
