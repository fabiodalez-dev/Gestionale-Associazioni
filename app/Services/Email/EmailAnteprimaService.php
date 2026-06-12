<?php

namespace App\Services\Email;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\RichiestaIscrizione;
use App\Models\Scadenza;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Accounts\BenvenutoAccount;
use App\Notifications\Accounts\ResetPasswordAccount;
use App\Notifications\Accounts\RichiestaSocioRicevuta;
use App\Notifications\Accounts\VerificaEmailAccount;
use App\Notifications\Iscrizioni\ConfermaRichiestaInviataNotification;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Notifications\Iscrizioni\IscrizioneRifiutataNotification;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
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
use App\Notifications\PromemoriaScadenzaNotification;
use App\Notifications\Quote\BenvenutoSocioNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Notifications\Quote\RicevutaQuotaNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Centro anteprime email (f.20 PIANO-VITA-SOCIALE): catalogo delle
 * notifiche di sistema con anteprima HTML renderizzata su DATI DI ESEMPIO
 * (modelli in memoria, mai persistiti) e payload per l'invio di prova.
 *
 * Il rendering passa da MailMessage::render(): le notifiche brandizzate
 * (TenantNotificationMailer::brandizza) mostrano il layout emails.layout
 * con logo/nome/colore del tenant corrente.
 */
class EmailAnteprimaService
{
    /**
     * Catalogo delle notifiche disponibili in anteprima.
     *
     * @return array<string, array{nome: string, categoria: string, descrizione: string}>
     */
    public function catalogo(): array
    {
        return [
            // --- Iscrizioni & conferme -----------------------------------
            'conferma_richiesta_iscrizione' => [
                'nome' => 'Conferma richiesta ricevuta',
                'categoria' => 'Iscrizioni',
                'descrizione' => 'Al richiedente subito dopo l\'invio del form pubblico, con il riepilogo dei dati.',
            ],
            'iscrizione_approvata' => [
                'nome' => 'Benvenuto socio (approvazione)',
                'categoria' => 'Iscrizioni',
                'descrizione' => 'All\'account quando la richiesta è approvata, con tessera in allegato se generata.',
            ],
            'iscrizione_rifiutata' => [
                'nome' => 'Richiesta non approvata',
                'categoria' => 'Iscrizioni',
                'descrizione' => 'All\'account quando la richiesta viene rifiutata, con il motivo.',
            ],
            'richiesta_iscrizione_admin' => [
                'nome' => 'Nuova richiesta (agli admin)',
                'categoria' => 'Iscrizioni',
                'descrizione' => 'Agli amministratori quando arriva una richiesta dal form pubblico.',
            ],
            'richiesta_socio_admin' => [
                'nome' => 'Richiesta "diventa socio" (agli admin)',
                'categoria' => 'Iscrizioni',
                'descrizione' => 'Agli amministratori quando un account chiede di diventare socio.',
            ],

            // --- Quote & ricevute ----------------------------------------
            'pagamento_quota' => [
                'nome' => 'Conferma pagamento quota',
                'categoria' => 'Quote e ricevute',
                'descrizione' => 'Al socio quando il pagamento della quota viene registrato.',
            ],
            'ricevuta_quota' => [
                'nome' => 'Ricevuta quota (PDF)',
                'categoria' => 'Quote e ricevute',
                'descrizione' => 'Al socio con la ricevuta numerata per anno in allegato.',
            ],
            'benvenuto_socio' => [
                'nome' => 'Benvenuto socio (pagamento online)',
                'categoria' => 'Quote e ricevute',
                'descrizione' => 'Al socio attivato automaticamente dopo il pagamento online.',
            ],

            // --- Prenotazioni & eventi ------------------------------------
            'prenotazione_confermata' => [
                'nome' => 'Prenotazione confermata',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account con il biglietto in allegato.',
            ],
            'prenotazione_in_approvazione' => [
                'nome' => 'Prenotazione in approvazione',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account quando l\'evento richiede approvazione manuale.',
            ],
            'prenotazione_in_attesa_pagamento' => [
                'nome' => 'Prenotazione in attesa di pagamento',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account con importo e scadenza del pagamento.',
            ],
            'prenotazione_in_lista_attesa' => [
                'nome' => 'Prenotazione in lista d\'attesa',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account quando l\'evento è al completo.',
            ],
            'prenotazione_promossa' => [
                'nome' => 'Promozione dalla lista d\'attesa',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account quando si liberano posti.',
            ],
            'prenotazione_promemoria' => [
                'nome' => 'Promemoria evento (domani)',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account il giorno prima dell\'evento.',
            ],
            'prenotazione_annullata' => [
                'nome' => 'Prenotazione annullata',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account quando la prenotazione viene annullata.',
            ],
            'prenotazione_rifiutata' => [
                'nome' => 'Prenotazione rifiutata',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account quando l\'admin rifiuta la prenotazione.',
            ],
            'prenotazione_pagamento_scaduto' => [
                'nome' => 'Pagamento prenotazione scaduto',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'All\'account quando il termine di pagamento è superato.',
            ],
            'prenotazione_da_approvare_admin' => [
                'nome' => 'Prenotazione da approvare (agli admin)',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'Agli amministratori per ogni prenotazione da approvare.',
            ],
            'evento_esaurito_admin' => [
                'nome' => 'Evento al completo (agli admin)',
                'categoria' => 'Prenotazioni',
                'descrizione' => 'Agli amministratori quando l\'evento raggiunge la capienza.',
            ],

            // --- Account frontend pubblico ---------------------------------
            'benvenuto_account' => [
                'nome' => 'Benvenuto account',
                'categoria' => 'Account',
                'descrizione' => 'All\'account dopo la verifica dell\'email.',
            ],
            'verifica_email_account' => [
                'nome' => 'Verifica email account',
                'categoria' => 'Account',
                'descrizione' => 'Link firmato di verifica dopo la registrazione.',
            ],
            'reset_password_account' => [
                'nome' => 'Reset password account',
                'categoria' => 'Account',
                'descrizione' => 'Link di reimpostazione password.',
            ],

            // --- Promemoria interni ----------------------------------------
            'promemoria_scadenza' => [
                'nome' => 'Promemoria scadenza interna',
                'categoria' => 'Promemoria',
                'descrizione' => 'All\'assegnatario (o agli admin) per le scadenze in finestra promemoria.',
            ],
        ];
    }

    /**
     * Anteprima della notifica su dati di esempio.
     *
     * @return array{subject: string, html: string}
     *
     * @throws InvalidArgumentException codice non in catalogo
     */
    public function anteprima(string $codice, Associazione $associazione): array
    {
        $mail = $this->mailMessage($codice, $associazione);

        return [
            'subject' => $mail->subject !== null && $mail->subject !== '' ? $mail->subject : 'Notifica',
            'html' => (string) $mail->render(),
        ];
    }

    /**
     * MailMessage della notifica costruita su dati di esempio.
     *
     * @throws InvalidArgumentException codice non in catalogo
     */
    public function mailMessage(string $codice, Associazione $associazione): MailMessage
    {
        [$notifica, $notifiable] = $this->campione($codice, $associazione);

        if (! method_exists($notifica, 'toMail')) {
            throw new InvalidArgumentException("La notifica {$codice} non supporta il canale mail.");
        }

        $mail = $notifica->toMail($notifiable);

        if (! $mail instanceof MailMessage) {
            throw new InvalidArgumentException("La notifica {$codice} non produce una MailMessage.");
        }

        return $mail;
    }

    // ------------------------------------------------------------------
    // Campioni (modelli in memoria, MAI persistiti)
    // ------------------------------------------------------------------

    /**
     * @return array{0: Notification, 1: object}
     *
     * @throws InvalidArgumentException codice non in catalogo
     */
    protected function campione(string $codice, Associazione $associazione): array
    {
        $socio = $this->socioCampione($associazione);
        $account = $this->accountCampione($associazione);
        $admin = $this->adminCampione($associazione);

        return match ($codice) {
            'conferma_richiesta_iscrizione' => [
                new ConfermaRichiestaInviataNotification($this->richiestaCampione($associazione, $socio)),
                $account,
            ],
            'iscrizione_approvata' => [
                new IscrizioneApprovataNotification($this->richiestaCampione($associazione, $socio, RichiestaIscrizione::STATO_APPROVATA), '0042'),
                $account,
            ],
            'iscrizione_rifiutata' => [
                new IscrizioneRifiutataNotification($this->richiestaCampione($associazione, $socio, RichiestaIscrizione::STATO_RIFIUTATA)),
                $account,
            ],
            'richiesta_iscrizione_admin' => [
                new RichiestaIscrizioneRicevutaNotification($this->richiestaCampione($associazione, $socio)),
                $admin,
            ],
            'richiesta_socio_admin' => [
                new RichiestaSocioRicevuta($account, 'Vorrei partecipare alle attività dell\'associazione.'),
                $admin,
            ],
            'pagamento_quota' => [
                new PagamentoQuotaNotification($this->quotaCampione($associazione, $socio)),
                $socio,
            ],
            'ricevuta_quota' => [
                new RicevutaQuotaNotification($this->ricevutaCampione($associazione, $socio)),
                $socio,
            ],
            'benvenuto_socio' => [
                new BenvenutoSocioNotification($socio),
                $socio,
            ],
            'prenotazione_confermata' => [
                new ConfermataNotification($this->prenotazioneCampione($associazione)),
                $account,
            ],
            'prenotazione_in_approvazione' => [
                new InApprovazioneNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_IN_APPROVAZIONE)),
                $account,
            ],
            'prenotazione_in_attesa_pagamento' => [
                new InAttesaPagamentoNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_IN_ATTESA_PAGAMENTO)),
                $account,
            ],
            'prenotazione_in_lista_attesa' => [
                new InListaAttesaNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_LISTA_ATTESA)),
                $account,
            ],
            'prenotazione_promossa' => [
                new PromozioneListaAttesaNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_IN_ATTESA_PAGAMENTO)),
                $account,
            ],
            'prenotazione_promemoria' => [
                new PromemoriaEventoNotification($this->prenotazioneCampione($associazione)),
                $account,
            ],
            'prenotazione_annullata' => [
                new AnnullataNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_ANNULLATA, [
                    'motivo_annullamento' => 'Evento rinviato a data da destinarsi.',
                ])),
                $account,
            ],
            'prenotazione_rifiutata' => [
                new RifiutataNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_ANNULLATA, [
                    'motivo_rifiuto' => 'Posti riservati ai soci.',
                ])),
                $account,
            ],
            'prenotazione_pagamento_scaduto' => [
                new PagamentoScadutoNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_SCADUTA)),
                $account,
            ],
            'prenotazione_da_approvare_admin' => [
                new DaApprovareNotification($this->prenotazioneCampione($associazione, Prenotazione::STATO_IN_APPROVAZIONE)),
                $admin,
            ],
            'evento_esaurito_admin' => [
                new EventoEsauritoNotification($this->eventoCampione($associazione)),
                $admin,
            ],
            'benvenuto_account' => [
                new BenvenutoAccount,
                $account,
            ],
            'verifica_email_account' => [
                new VerificaEmailAccount,
                $account,
            ],
            'reset_password_account' => [
                new ResetPasswordAccount(Str::random(64)),
                $account,
            ],
            'promemoria_scadenza' => [
                new PromemoriaScadenzaNotification($this->scadenzaCampione($associazione)),
                $admin,
            ],
            default => throw new InvalidArgumentException("Notifica sconosciuta: {$codice}"),
        };
    }

    protected function socioCampione(Associazione $associazione): Socio
    {
        $socio = (new Socio)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'numero_socio' => '0042',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'stato' => Socio::STATO_ATTIVO,
            'email_opt_out' => false,
        ]);
        $socio->setRelation('associazione', $associazione);

        return $socio;
    }

    protected function accountCampione(Associazione $associazione): Account
    {
        $account = (new Account)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'attivo' => true,
        ]);
        $account->setRelation('associazione', $associazione);

        return $account;
    }

    protected function adminCampione(Associazione $associazione): Utente
    {
        $admin = (new Utente)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'nome' => 'Anna',
            'cognome' => 'Bianchi',
            'email' => 'admin@example.com',
        ]);
        $admin->setRelation('associazione', $associazione);

        return $admin;
    }

    protected function richiestaCampione(Associazione $associazione, Socio $socio, string $stato = RichiestaIscrizione::STATO_IN_ATTESA): RichiestaIscrizione
    {
        $richiesta = (new RichiestaIscrizione)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'socio_id' => $socio->id,
            'stato' => $stato,
            'dati_form' => [
                'telefono' => '333 1234567',
                'codice_fiscale' => 'RSSMRA85T10A562S',
                'data_nascita' => '1985-12-10',
                'citta' => 'Milano',
            ],
            'motivo_rifiuto' => $stato === RichiestaIscrizione::STATO_RIFIUTATA
                ? 'Dati anagrafici incompleti.'
                : null,
        ]);
        $richiesta->setRelation('associazione', $associazione);
        $richiesta->setRelation('socio', $socio);

        return $richiesta;
    }

    protected function quotaCampione(Associazione $associazione, Socio $socio): Quota
    {
        $quota = (new Quota)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'socio_id' => $socio->id,
            'anno' => (int) now()->year,
            'tipo' => 'Quota Associativa',
            'importo' => 50,
            'stato' => Quota::STATO_PAGATA,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => 'stripe',
        ]);
        $quota->setRelation('associazione', $associazione);
        $quota->setRelation('socio', $socio);

        return $quota;
    }

    protected function ricevutaCampione(Associazione $associazione, Socio $socio): Ricevuta
    {
        $ricevuta = (new Ricevuta)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'numero' => 12,
            'anno' => (int) now()->year,
            'importo' => 50,
            'emessa_at' => now(),
        ]);

        $quota = $this->quotaCampione($associazione, $socio);
        $ricevuta->forceFill(['quota_id' => $quota->id]);
        $ricevuta->setRelation('associazione', $associazione);
        $ricevuta->setRelation('quota', $quota);

        return $ricevuta;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function prenotazioneCampione(Associazione $associazione, string $stato = Prenotazione::STATO_CONFERMATA, array $extra = []): Prenotazione
    {
        $prenotazione = (new Prenotazione)->forceFill(array_merge([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'evento_id' => (string) Str::uuid(),
            'codice' => 'PRN-2026-0042',
            'stato' => $stato,
            'posti' => 2,
            'importo_totale' => 20,
            'scadenza_pagamento_at' => now()->addHours(48),
            'created_at' => now(),
        ], $extra));
        $prenotazione->setRelation('associazione', $associazione);

        return $prenotazione;
    }

    protected function eventoCampione(Associazione $associazione): Evento
    {
        $evento = (new Evento)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'titolo' => 'Assemblea dei soci',
            'data_evento' => now()->addDays(7)->setTime(18, 30),
            'luogo' => 'Sede sociale',
            'capienza' => 100,
            'lista_attesa_attiva' => true,
        ]);
        $evento->setRelation('associazione', $associazione);

        return $evento;
    }

    protected function scadenzaCampione(Associazione $associazione): Scadenza
    {
        $scadenza = (new Scadenza)->forceFill([
            'id' => (string) Str::uuid(),
            'associazione_id' => $associazione->id,
            'titolo' => 'Rinnovo polizza assicurativa',
            'descrizione' => 'Verificare il preventivo prima del rinnovo.',
            'tipo_scadenza' => 'Adempimento',
            'priorita' => 'Alta',
            'data_scadenza' => now()->addDays(5),
            'promemoria_giorni' => 7,
        ]);
        $scadenza->setRelation('associazione', $associazione);

        return $scadenza;
    }
}
