<?php

namespace Tests\Feature\Percorsi;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\FeedbackEvento;
use App\Models\Movimento;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\SpesaEvento;
use App\Models\Utente;
use App\Notifications\EventiVita\FeedbackEventoNotification;
use App\Notifications\Prenotazioni\AnnullataNotification;
use App\Notifications\Prenotazioni\BigliettoPrenotazioneNotification;
use App\Notifications\Prenotazioni\ConfermataNotification;
use App\Notifications\Prenotazioni\DaApprovareNotification;
use App\Notifications\Prenotazioni\InApprovazioneNotification;
use App\Notifications\Prenotazioni\InAttesaPagamentoNotification;
use App\Notifications\Prenotazioni\InListaAttesaNotification;
use App\Notifications\Prenotazioni\RifiutataNotification;
use App\Services\Prenotazioni\BigliettoService;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Percorsi reali — EVENTI E VITA SOCIALE.
 *
 * Ogni test segue passo per passo quello che succede davvero in
 * un'associazione: l'amministratore crea e gestisce gli eventi dal
 * pannello, i visitatori prenotano dal sito pubblico, l'addetto
 * all'ingresso fa il check-in dei biglietti, il giorno dopo arrivano i
 * feedback e, a chiusura, i conti dell'evento.
 *
 * Attori: admin (guard web), visitatore registrato (guard account),
 * ospite anonimo del sito pubblico. Il tempo è governato da
 * Carbon::setTestNow (lunedì 4 maggio 2026, ore 10:00).
 */
class Percorso04_EventiVitaSocialeTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-04 10:00:00');

        // Le notifiche vengono sempre intercettate: i percorsi le verificano
        // dove servono e non devono generare PDF/email reali altrove.
        Notification::fake();

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Culturale Il Melograno',
            'frontend_pubblico_attivo' => true,
        ]);

        // Ruoli e permessi come in produzione: lo scanner dei biglietti verifica
        // il permesso spatie eventi.update, assegnato all'admin tramite il ruolo.
        $this->seed(RolesSeeder::class);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Anna',
            'cognome' => 'Presidente',
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Attori
    // ------------------------------------------------------------------

    /**
     * Chiude la sessione corrente: nessun utente su nessuna guard, come un
     * nuovo browser. Serve per cambiare attore nello stesso percorso.
     */
    private function esci(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->flushSession();
        app()->forgetInstance('tenant.pubblico');
    }

    private function comeAdmin(): static
    {
        $this->esci();

        return $this->actingAs($this->admin, 'web');
    }

    private function comeAccount(Account $account): static
    {
        $this->esci();

        return $this->actingAs($account, 'account');
    }

    private function comeOspite(): static
    {
        $this->esci();

        return $this;
    }

    private function visitatore(string $nome, string $cognome): Account
    {
        return Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@esempio.test',
        ]);
    }

    private function socio(string $nome, string $cognome, string $numero): Socio
    {
        return Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'numero_socio' => $numero,
        ]);
    }

    // ------------------------------------------------------------------
    // Azioni ricorrenti
    // ------------------------------------------------------------------

    /**
     * Il form "nuovo evento" del pannello, già compilato con una serata
     * pubblica gratuita a 10 posti tra tre settimane.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiEvento(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Serata di tango in piazza',
            'descrizione' => 'Milonga aperta a tutti, con lezione introduttiva alle 21.',
            'data_evento' => '2026-05-23T21:00',
            'data_fine' => '2026-05-23T23:30',
            'luogo' => 'Piazza del Popolo',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 10,
            'prezzo' => '0',
            'max_posti_per_prenotazione' => 4,
            'lista_attesa_attiva' => '1',
            'iscrizioni_aperte' => '1',
            'richiede_approvazione' => '0',
            'annullamento_entro_ore' => 24,
            'solo_soci' => '0',
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function adminCreaEvento(array $override = []): Evento
    {
        $dati = $this->datiEvento($override);

        $this->comeAdmin()
            ->post(route('eventi.store'), $dati)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.index'))
            ->assertSessionHas('success', 'Evento creato con successo.');

        return Evento::allTenants()->where('titolo', $dati['titolo'])->firstOrFail();
    }

    /**
     * Il visitatore, dal sito pubblico, prenota i posti e viene portato
     * alla pagina di esito.
     */
    private function prenota(Account $account, Evento $evento, int $posti = 1): Prenotazione
    {
        $this->comeAccount($account)
            ->post($this->urlPubblica('eventi.prenota', ['evento' => $evento->id]), ['posti' => $posti])
            ->assertSessionHasNoErrors();

        $prenotazione = Prenotazione::allTenants()
            ->where('account_id', $account->id)
            ->where('evento_id', $evento->id)
            ->firstOrFail();

        $this->get($this->urlPubblica('prenotazioni.esito', ['prenotazione' => $prenotazione->id]))
            ->assertOk()
            ->assertSee($evento->titolo);

        return $prenotazione;
    }

    /**
     * @param  array<string, mixed>  $parametri
     */
    private function urlPubblica(string $nome, array $parametri = []): string
    {
        return route('pubblico.'.$nome, ['associazione' => $this->associazione->slug] + $parametri);
    }

    private function urlAdminPrenotazione(string $azione, Prenotazione $prenotazione): string
    {
        return route('eventi.prenotazioni.'.$azione, [
            'evento' => $prenotazione->evento_id,
            'prenotazione' => $prenotazione->id,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Dal pannello al sito pubblico e ai calendari
    // ------------------------------------------------------------------

    public function test_evento_pubblico_creato_dall_admin_compare_sul_sito_e_nei_calendari(): void
    {
        // L'admin prepara anche una bozza che NON deve finire sul sito
        $bozza = $this->adminCreaEvento([
            'titolo' => 'Assemblea riservata (bozza)',
            'stato_pubblicazione' => Evento::STATO_BOZZA,
        ]);

        $evento = $this->adminCreaEvento();

        $this->assertSame(10, $evento->capienza);
        $this->assertSame(Evento::STATO_PUBBLICATO, $evento->stato_pubblicazione);
        $this->assertTrue($evento->isPrenotabile());
        $this->assertSame('2026-05-23 21:00', $evento->data_evento->format('Y-m-d H:i'));

        // Nel pannello l'elenco mostra entrambi, con il link al tab prenotazioni
        $this->get(route('eventi.index'))
            ->assertOk()
            ->assertSee('Serata di tango in piazza')
            ->assertSee('Assemblea riservata (bozza)');

        // Un visitatore anonimo trova solo l'evento pubblicato
        $this->comeOspite()
            ->get($this->urlPubblica('eventi.index'))
            ->assertOk()
            ->assertSee('Serata di tango in piazza')
            ->assertDontSee('Assemblea riservata (bozza)');

        $this->get($this->urlPubblica('eventi.show', ['evento' => $evento->id]))
            ->assertOk()
            ->assertSee('Piazza del Popolo')
            ->assertSee('Gratuito')
            ->assertSee('10 posti disponibili')
            ->assertSee('Accedi per prenotare')
            ->assertSee('Aggiungi al calendario (.ics)');

        $this->get($this->urlPubblica('eventi.show', ['evento' => $bozza->id]))->assertNotFound();

        // Il file .ics del singolo evento è scaricabile dal sito...
        $ics = $this->get($this->urlPubblica('eventi.ics', ['evento' => $evento->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
        $this->assertStringContainsString('SUMMARY:Serata di tango in piazza', $ics->getContent());
        $this->assertStringContainsString('LOCATION:Piazza del Popolo', $ics->getContent());
        $this->assertStringContainsString('DTSTART:20260523T', $ics->getContent());

        // ...e dal feed dell'intero calendario (solo i pubblicati)
        $feed = $this->get($this->urlPubblica('calendario.ics'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($feed, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('X-WR-CALNAME:Eventi Circolo Culturale Il Melograno', $feed);
        $this->assertStringNotContainsString('Assemblea riservata', $feed);

        // L'admin, dal pannello, esporta anche la bozza nel proprio calendario
        $this->comeAdmin()
            ->get(route('eventi.ics', $bozza))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
    }

    // ------------------------------------------------------------------
    // 2. Prenotazione del visitatore, biglietto e annullamento
    // ------------------------------------------------------------------

    public function test_visitatore_registrato_prenota_riceve_il_biglietto_e_poi_annulla_liberando_i_posti(): void
    {
        $evento = $this->adminCreaEvento();
        $giulia = $this->visitatore('Giulia', 'Ferri');

        // Giulia prenota due posti per sé e un'amica
        $prenotazione = $this->prenota($giulia, $evento, 2);

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertSame(2, $prenotazione->posti);
        $this->assertStringStartsWith('PRN-', $prenotazione->codice);
        $this->assertSame(64, strlen($prenotazione->qr_token));

        // Riceve subito l'email di conferma con il biglietto PDF in allegato
        Notification::assertSentTo($giulia, ConfermataNotification::class, function (ConfermataNotification $n, array $canali) use ($giulia, $prenotazione): bool {
            $mail = $n->toMail($giulia);

            return $n->prenotazione->is($prenotazione)
                && in_array('mail', $canali, true)
                && str_contains(implode(' ', $mail->introLines), $prenotazione->codice)
                && collect($mail->rawAttachments)->contains(fn (array $a) => str_ends_with($a['name'], '.pdf')
                    && str_starts_with((string) $a['data'], '%PDF'));
        });

        // La prenotazione compare nella sua area riservata, con il bottone di annullo
        $this->get($this->urlPubblica('account.prenotazioni.index'))
            ->assertOk()
            ->assertSee($prenotazione->codice)
            ->assertSee('Confermata');

        $this->get($this->urlPubblica('account.prenotazioni.show', ['prenotazione' => $prenotazione->id]))
            ->assertOk()
            ->assertSee($prenotazione->codice)
            ->assertSee('Annulla prenotazione');

        // Sul sito i posti disponibili sono scesi da 10 a 8
        $this->comeOspite()
            ->get($this->urlPubblica('eventi.show', ['evento' => $evento->id]))
            ->assertOk()
            ->assertSee('8 posti disponibili');

        // L'admin la vede nel tab prenotazioni dell'evento
        $risposta = $this->comeAdmin()
            ->get(route('eventi.prenotazioni.index', $evento))
            ->assertOk()
            ->assertSee($prenotazione->codice)
            ->assertSee('Ferri');
        $this->assertSame(2, $risposta->viewData('conteggi')['posti_confermati']);

        // L'amica non può più venire: Giulia annulla dall'area riservata (siamo a 19 giorni dall'evento)
        $this->comeAccount($giulia)
            ->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $prenotazione->id]))
            ->assertRedirect($this->urlPubblica('account.prenotazioni.show', ['prenotazione' => $prenotazione->id]))
            ->assertSessionHas('success', 'Prenotazione annullata.');

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $prenotazione->stato);
        $this->assertNotNull($prenotazione->annullata_at);
        $this->assertSame("Annullata dall'utente", $prenotazione->motivo_annullamento);

        Notification::assertSentTo($giulia, AnnullataNotification::class);

        $this->get($this->urlPubblica('account.prenotazioni.show', ['prenotazione' => $prenotazione->id]))
            ->assertOk()
            ->assertSee('Annullata')
            ->assertDontSee('Annulla prenotazione');

        // Un secondo annullo non è ammesso
        $this->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $prenotazione->id]))
            ->assertSessionHasErrors('annulla');

        // I posti sono tornati disponibili per tutti
        $this->comeOspite()
            ->get($this->urlPubblica('eventi.show', ['evento' => $evento->id]))
            ->assertOk()
            ->assertSee('10 posti disponibili');
    }

    // ------------------------------------------------------------------
    // 3. Approvazione, rifiuto, lista d'attesa e promozione
    // ------------------------------------------------------------------

    public function test_evento_con_approvazione_lista_d_attesa_e_promozione_quando_si_liberano_i_posti(): void
    {
        $evento = $this->adminCreaEvento([
            'titolo' => 'Cena sociale di primavera',
            'capienza' => 3,
            'richiede_approvazione' => '1',
        ]);

        $marco = $this->visitatore('Marco', 'Rinaldi');
        $sara = $this->visitatore('Sara', 'Conti');
        $luca = $this->visitatore('Luca', 'Moretti');
        $elena = $this->visitatore('Elena', 'Fabbri');

        // Marco (2 posti) e Luca (1) chiedono di partecipare: la richiesta nasce "in attesa"
        $richiestaMarco = $this->prenota($marco, $evento, 2);
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $richiestaMarco->stato);
        $this->assertNull($richiestaMarco->scadenza_pagamento_at);
        Notification::assertSentTo($marco, InApprovazioneNotification::class);
        Notification::assertSentTo($this->admin, DaApprovareNotification::class);

        $this->travel(1)->minutes();
        $richiestaLuca = $this->prenota($luca, $evento, 1);
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $richiestaLuca->stato);

        // I 3 posti sono riservati: Sara finisce in lista d'attesa, prima in coda
        $this->travel(1)->minutes();
        $attesaSara = $this->prenota($sara, $evento, 1);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $attesaSara->stato);
        Notification::assertSentTo($sara, InListaAttesaNotification::class);

        $this->get($this->urlPubblica('account.prenotazioni.index'))
            ->assertOk()
            ->assertSee('Lista d\'attesa', false);

        // L'admin apre il tab: due richieste da approvare, una in coda
        $tab = $this->comeAdmin()->get(route('eventi.prenotazioni.index', $evento))->assertOk();
        $this->assertSame(2, $tab->viewData('conteggi')['numero_da_approvare']);
        $this->assertSame(3, $tab->viewData('conteggi')['posti_riservati']);
        $this->assertSame(1, $tab->viewData('conteggi')['numero_in_coda']);

        // Approva Marco: evento gratuito, quindi confermata subito
        $this->post($this->urlAdminPrenotazione('approva', $richiestaMarco))
            ->assertRedirect(route('eventi.prenotazioni.index', $evento))
            ->assertSessionHas('success', "Prenotazione {$richiestaMarco->codice} approvata e confermata.");

        $richiestaMarco->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $richiestaMarco->stato);
        $this->assertNotNull($richiestaMarco->approvata_at);
        $this->assertSame($this->admin->id, $richiestaMarco->approvata_da);
        Notification::assertSentTo($marco, ConfermataNotification::class);

        // Rifiuta Luca: il motivo è obbligatorio perché viene comunicato
        $this->post($this->urlAdminPrenotazione('rifiuta', $richiestaLuca), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $richiestaLuca->fresh()->stato);

        $this->post($this->urlAdminPrenotazione('rifiuta', $richiestaLuca), [
            'motivo' => 'La cena è riservata ai soci e ai loro accompagnatori.',
        ])->assertSessionHas('success');

        $richiestaLuca->refresh();
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $richiestaLuca->stato);
        $this->assertSame('La cena è riservata ai soci e ai loro accompagnatori.', $richiestaLuca->motivo_rifiuto);
        Notification::assertSentTo($luca, RifiutataNotification::class);

        // Il posto liberato promuove Sara, che però deve ancora passare dall'approvazione
        $attesaSara->refresh();
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $attesaSara->stato);
        $this->assertNotNull($attesaSara->promossa_at);
        Notification::assertSentTo($sara, InApprovazioneNotification::class);

        $this->post($this->urlAdminPrenotazione('approva', $attesaSara))->assertSessionHas('success');
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $attesaSara->fresh()->stato);

        // Evento di nuovo al completo (2 + 1): Elena si mette in coda
        $this->travel(1)->minutes();
        $attesaElena = $this->prenota($elena, $evento, 1);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $attesaElena->stato);

        // L'admin prova a promuovere a mano: non c'è posto
        $this->comeAdmin()
            ->post(route('eventi.prenotazioni.promuovi', $evento))
            ->assertSessionHas('warning');
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $attesaElena->fresh()->stato);

        // Trova un tavolo in più: alza la capienza a 4 e promuove la coda
        $this->put(route('eventi.update', $evento), $this->datiEvento([
            'titolo' => 'Cena sociale di primavera',
            'capienza' => 4,
            'richiede_approvazione' => '1',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('eventi.index'));

        $this->post(route('eventi.prenotazioni.promuovi', $evento))
            ->assertSessionHas('success', '1 prenotazione promossa dalla lista d\'attesa.');

        $attesaElena->refresh();
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $attesaElena->stato);
        Notification::assertSentTo($elena, InApprovazioneNotification::class);

        $this->comeAccount($elena)
            ->get($this->urlPubblica('account.prenotazioni.index'))
            ->assertOk()
            ->assertSee('In attesa di approvazione');

        $this->assertSame(4, Prenotazione::postiOccupati($evento->id));
    }

    // ------------------------------------------------------------------
    // 4. Evento a pagamento con incasso offline
    // ------------------------------------------------------------------

    public function test_evento_a_pagamento_conferma_del_pagamento_offline_biglietto_valido_e_reinvio(): void
    {
        $evento = $this->adminCreaEvento([
            'titolo' => 'Concerto di chiusura stagione',
            'prezzo' => '15,00',
        ]);
        $this->assertSame('15.00', (string) $evento->prezzo);

        $paolo = $this->visitatore('Paolo', 'Serra');

        // Paolo prenota due posti: i posti sono riservati ma il pagamento va completato
        $prenotazione = $this->prenota($paolo, $evento, 2);

        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $prenotazione->stato);
        $this->assertSame('30.00', (string) $prenotazione->importo_totale);
        $this->assertSame('2026-05-04 10:30', $prenotazione->scadenza_pagamento_at->format('Y-m-d H:i'));
        Notification::assertSentTo($paolo, InAttesaPagamentoNotification::class);

        $this->get($this->urlPubblica('prenotazioni.esito', ['prenotazione' => $prenotazione->id]))
            ->assertOk()
            ->assertSee('completa il pagamento')
            ->assertSee('10:30');

        // Senza pagamento niente biglietto: né PDF né QR valido
        $this->get($this->urlPubblica('account.prenotazioni.biglietto', ['prenotazione' => $prenotazione->id]))
            ->assertNotFound();

        $urlVerifica = app(BigliettoService::class)->signedUrl($prenotazione);
        $this->comeOspite()->get($urlVerifica)
            ->assertOk()
            ->assertSee('Biglietto non valido')
            ->assertSee('Pagamento non ancora completato');

        // Paolo passa in sede e paga in contanti: l'admin registra l'incasso
        $tab = $this->comeAdmin()->get(route('eventi.prenotazioni.index', $evento))->assertOk();
        $this->assertSame(2, $tab->viewData('conteggi')['posti_riservati']);

        $this->post($this->urlAdminPrenotazione('conferma-pagamento', $prenotazione))
            ->assertRedirect(route('eventi.prenotazioni.index', $evento))
            ->assertSessionHas('success', "Pagamento registrato: prenotazione {$prenotazione->codice} confermata.");

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertSame('offline', $prenotazione->gateway);
        $this->assertNotNull($prenotazione->pagata_at);
        Notification::assertSentTo($paolo, ConfermataNotification::class);

        // L'incasso finisce automaticamente in prima nota
        $movimento = Movimento::allTenants()
            ->where('origine_type', $prenotazione->getMorphClass())
            ->where('origine_id', $prenotazione->id)
            ->first();
        $this->assertNotNull($movimento, 'La prenotazione pagata deve generare il movimento di entrata');
        $this->assertSame(Movimento::TIPO_ENTRATA, $movimento->tipo);
        $this->assertEqualsWithDelta(30.0, (float) $movimento->importo, 0.001);
        $this->assertStringContainsString($prenotazione->codice, (string) $movimento->descrizione);

        // Una seconda registrazione viene rifiutata
        $this->post($this->urlAdminPrenotazione('conferma-pagamento', $prenotazione))
            ->assertSessionHas('error', 'La prenotazione non è in attesa di pagamento.');

        // Ora il biglietto è valido: PDF scaricabile e QR verificabile da chiunque
        $pdf = $this->comeAccount($paolo)
            ->get($this->urlPubblica('account.prenotazioni.biglietto', ['prenotazione' => $prenotazione->id]))
            ->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));

        $this->comeOspite()->get($urlVerifica)
            ->assertOk()
            ->assertSee('Biglietto valido')
            ->assertSee($prenotazione->codice)
            ->assertSee('Paolo S.')
            ->assertDontSee('Serra');

        // Paolo ha perso l'email: l'admin reinvia il biglietto
        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('reinvia', $prenotazione))
            ->assertSessionHas('success', "Biglietto {$prenotazione->codice} reinviato a {$paolo->email}.");

        Notification::assertSentTo($paolo, BigliettoPrenotazioneNotification::class, function (BigliettoPrenotazioneNotification $n) use ($paolo, $prenotazione): bool {
            $mail = $n->toMail($paolo);

            return $n->prenotazione->is($prenotazione)
                && count($mail->rawAttachments) === 1
                && str_ends_with($mail->rawAttachments[0]['name'], '.pdf');
        });
    }

    // ------------------------------------------------------------------
    // 5. Il giorno dell'evento: check-in via QR
    // ------------------------------------------------------------------

    public function test_il_giorno_dell_evento_il_checkin_accetta_il_biglietto_una_sola_volta_e_rifiuta_quelli_altrui(): void
    {
        $concerto = $this->adminCreaEvento(['titolo' => 'Concerto in piazza']);
        $laboratorio = $this->adminCreaEvento([
            'titolo' => 'Laboratorio di ceramica',
            'data_evento' => '2026-05-24T10:00',
            'data_fine' => '2026-05-24T12:00',
        ]);

        $chiara = $this->visitatore('Chiara', 'Bassi');
        $davide = $this->visitatore('Davide', 'Lombardi');

        $bigliettoChiara = $this->prenota($chiara, $concerto, 2);
        $bigliettoDavide = $this->prenota($davide, $laboratorio, 1);

        // È la sera del concerto, mezz'ora prima dell'inizio
        Carbon::setTestNow('2026-05-23 20:30:00');

        // L'addetto all'ingresso apre la pagina dello scanner
        $this->comeOspite()->get(route('eventi.checkin'))
            ->assertOk()
            ->assertSee('Check-in Evento')
            ->assertHeader('Permissions-Policy', 'camera=(self)');

        // Inquadra il QR di Chiara: la pagina pubblica dice che è valido
        $urlChiara = app(BigliettoService::class)->signedUrl($bigliettoChiara);
        $this->get($urlChiara)
            ->assertOk()
            ->assertSee('Biglietto valido')
            ->assertSee('Concerto in piazza')
            ->assertSee('Chiara B.');

        // Primo passaggio dallo scanner admin (evento selezionato): entra
        $this->comeAdmin()
            ->postJson(route('biglietti.checkin'), [
                'qr_token' => $bigliettoChiara->qr_token,
                'evento_id' => $concerto->id,
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'already_checked_in' => false,
                'message' => 'Check-in registrato.',
                'biglietto' => ['codice' => $bigliettoChiara->codice, 'posti' => 2],
            ]);

        $bigliettoChiara->refresh();
        $this->assertSame('2026-05-23 20:30', $bigliettoChiara->checkin_at->format('Y-m-d H:i'));
        $this->assertSame($this->admin->id, $bigliettoChiara->checkin_utente_id);

        // Secondo passaggio con lo stesso QR: "già entrato", orario del primo
        $this->travel(5)->minutes();
        $this->postJson(route('biglietti.checkin'), ['qr_token' => $bigliettoChiara->qr_token])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => true])
            ->assertJsonPath('message', 'Biglietto già utilizzato alle 20:30 del 23/05/2026.');

        $this->assertSame('2026-05-23 20:30', $bigliettoChiara->fresh()->checkin_at->format('Y-m-d H:i'));

        $this->comeOspite()->get($urlChiara)
            ->assertOk()
            ->assertSee('Biglietto già utilizzato')
            ->assertSee('20:30');

        // Davide si presenta al concerto con il biglietto del laboratorio di domani
        $this->comeAdmin()
            ->postJson(route('biglietti.checkin'), [
                'qr_token' => $bigliettoDavide->qr_token,
                'evento_id' => $concerto->id,
            ])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'evento_mismatch'])
            ->assertJsonPath('error', 'Il biglietto appartiene a un altro evento: Laboratorio di ceramica.');

        $this->assertNull($bigliettoDavide->fresh()->checkin_at);

        // Un QR inventato non trova nulla
        $this->postJson(route('biglietti.checkin'), ['qr_token' => bin2hex(random_bytes(32))])
            ->assertNotFound()
            ->assertJson(['code' => 'biglietto_not_found']);

        // Nella pagina presenze dell'evento Chiara risulta arrivata
        $this->get(route('eventi.presenze.index', $concerto))
            ->assertOk()
            ->assertSee($bigliettoChiara->codice)
            ->assertSee('23/05/2026 20:30');
    }

    // ------------------------------------------------------------------
    // 6. Partecipanti soci e foglio presenze
    // ------------------------------------------------------------------

    public function test_partecipanti_soci_registrazione_delle_presenze_e_foglio_firme(): void
    {
        $evento = $this->adminCreaEvento(['titolo' => 'Gita sociale al lago']);

        $rossi = $this->socio('Mario', 'Rossi', '001');
        $bianchi = $this->socio('Lucia', 'Bianchi', '002');
        $verdi = $this->socio('Piero', 'Verdi', '003');
        $sospeso = Socio::factory()->sospeso()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Gino',
            'cognome' => 'Sospesi',
        ]);

        // L'admin segna chi ha detto sì, chi forse e chi no
        $this->comeAdmin();
        foreach ([
            [$rossi, EventoPartecipante::STATO_CONFERMATO, 'Porta la torta'],
            [$bianchi, EventoPartecipante::STATO_FORSE, null],
            [$verdi, EventoPartecipante::STATO_NON_PARTECIPA, null],
        ] as [$socio, $stato, $note]) {
            $this->post(route('eventi.partecipanti.store', $evento), [
                'socio_id' => $socio->id,
                'stato_partecipazione' => $stato,
                'note' => $note,
            ])->assertRedirect(route('eventi.partecipanti.index', $evento))
                ->assertSessionHas('success', 'Socio aggiunto all\'evento!');
        }

        // Il socio sospeso non può essere iscritto
        $this->post(route('eventi.partecipanti.store', $evento), [
            'socio_id' => $sospeso->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ])->assertSessionHasErrors('socio_id');

        $pagina = $this->get(route('eventi.partecipanti.index', $evento))
            ->assertOk()
            ->assertSee('Rossi')
            ->assertSee('Porta la torta');
        $this->assertSame(['confermati' => 1, 'forse' => 1, 'non_partecipa' => 1, 'totale' => 3], $pagina->viewData('stats'));

        // Bianchi conferma a voce: aggiornata; Verdi chiede di essere tolto dall'elenco
        $partecipazioneBianchi = EventoPartecipante::allTenants()->where('socio_id', $bianchi->id)->firstOrFail();
        $this->put(route('eventi.partecipanti.update', ['evento' => $evento->id, 'partecipante' => $partecipazioneBianchi->id]), [
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            'note' => 'Viene con il marito',
        ])->assertSessionHas('success', 'Partecipazione aggiornata con successo!');

        $partecipazioneVerdi = EventoPartecipante::allTenants()->where('socio_id', $verdi->id)->firstOrFail();
        $this->delete(route('eventi.partecipanti.destroy', ['evento' => $evento->id, 'partecipante' => $partecipazioneVerdi->id]))
            ->assertSessionHas('success', 'Partecipante rimosso dall\'evento!');

        $this->assertSame(EventoPartecipante::STATO_CONFERMATO, $partecipazioneBianchi->fresh()->stato_partecipazione);
        $this->assertNull(EventoPartecipante::allTenants()->find($partecipazioneVerdi->id));

        $csv = $this->get(route('eventi.partecipanti.export', $evento))->assertOk()->streamedContent();
        $this->assertStringContainsString('Rossi;Mario;001', $csv);
        $this->assertStringContainsString('Bianchi;Lucia;002', $csv);
        $this->assertStringNotContainsString('Verdi', $csv);

        // Dal sito pubblico due simpatizzanti prenotano
        $nora = $this->visitatore('Nora', 'Neri');
        $tommaso = $this->visitatore('Tommaso', 'Galli');
        $bigliettoNora = $this->prenota($nora, $evento, 1);
        $bigliettoTommaso = $this->prenota($tommaso, $evento, 1);

        // Il giorno della gita l'admin spunta le presenze: Nora c'è, Tommaso non si presenta
        Carbon::setTestNow('2026-05-23 21:10:00');

        $this->comeAdmin()->get(route('eventi.presenze.index', $evento))
            ->assertOk()
            ->assertSee($bigliettoNora->codice)
            ->assertSee($bigliettoTommaso->codice);

        $this->post(route('eventi.presenze.store', $evento), ['prenotazioni' => [$bigliettoNora->id]])
            ->assertRedirect(route('eventi.presenze.index', $evento))
            ->assertSessionHas('success', 'Presenze registrate: 1.');

        $this->assertSame('2026-05-23 21:10', $bigliettoNora->fresh()->checkin_at->format('Y-m-d H:i'));
        $this->assertSame($this->admin->id, $bigliettoNora->fresh()->checkin_utente_id);
        $this->assertNull($bigliettoTommaso->fresh()->checkin_at);

        // La pagina riporta presenti e assenti
        $presenze = $this->get(route('eventi.presenze.index', $evento))->assertOk()->assertSee('23/05/2026 21:10');
        $elenco = $presenze->viewData('prenotazioni');
        $this->assertCount(2, $elenco);
        $this->assertSame(1, $elenco->filter(fn (Prenotazione $p) => $p->checkin_at !== null)->count());
        $this->assertSame(1, $elenco->filter(fn (Prenotazione $p) => $p->checkin_at === null)->count());

        // Il foglio presenze da stampare e far firmare
        $foglio = $this->get(route('eventi.presenze.foglio', $evento))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $foglio->getContent());
    }

    // ------------------------------------------------------------------
    // 7. Serie ricorrente
    // ------------------------------------------------------------------

    public function test_serie_settimanale_di_quattro_incontri_modifica_della_serie_e_cancellazione_di_una_sola_data(): void
    {
        $this->comeAdmin()
            ->post(route('eventi-ricorrenti.store'), $this->datiEvento([
                'titolo' => 'Corso di tango — lezione settimanale',
                'data_evento' => '2026-05-07T21:00',
                'data_fine' => '2026-05-07T23:00',
                'luogo' => 'Sala grande',
                'capienza' => 12,
                'frequenza' => 'settimanale',
                'fino_al' => '2026-05-28',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.index'))
            ->assertSessionHas('success', 'Serie ricorrente creata: 4 eventi in calendario.');

        $occorrenze = Evento::allTenants()->orderBy('data_evento')->get();
        $this->assertCount(4, $occorrenze);
        $this->assertSame(
            ['2026-05-07 21:00', '2026-05-14 21:00', '2026-05-21 21:00', '2026-05-28 21:00'],
            $occorrenze->map(fn (Evento $e) => $e->data_evento->format('Y-m-d H:i'))->all()
        );
        $this->assertCount(1, $occorrenze->pluck('serie_id')->unique());
        $this->assertSame('2026-05-07 23:00', $occorrenze[0]->data_fine->format('Y-m-d H:i'));

        $prima = $occorrenze[0];
        $seconda = $occorrenze[1];

        // Sul sito pubblico compaiono tutte e quattro le date, anche nel feed calendario
        $catalogo = $this->comeOspite()->get($this->urlPubblica('eventi.index'))->assertOk();
        $this->assertCount(4, $catalogo->viewData('eventi'));
        $this->assertSame(4, substr_count($this->get($this->urlPubblica('calendario.ics'))->getContent(), 'BEGIN:VEVENT'));

        // L'admin cambia nome e sala a tutta la serie: le date non si toccano
        $this->comeAdmin()
            ->get(route('eventi.serie.show', $prima))
            ->assertOk()
            ->assertViewHas('occorrenze', fn ($o) => count($o) === 4);

        $this->put(route('eventi.serie.update', $seconda), [
            'titolo' => 'Corso di tango — livello intermedio',
            'descrizione' => 'Dal 7 maggio si passa al livello intermedio.',
            'luogo' => 'Sala piccola',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 12,
            'prezzo' => '5,00',
            'iscrizioni_aperte' => '1',
            'richiede_approvazione' => '0',
            'solo_soci' => '0',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.serie.show', $seconda))
            ->assertSessionHas('success', 'Serie aggiornata: 4 occorrenze.');

        foreach (Evento::allTenants()->orderBy('data_evento')->get() as $i => $occorrenza) {
            $this->assertSame('Corso di tango — livello intermedio', $occorrenza->titolo);
            $this->assertSame('Sala piccola', $occorrenza->luogo);
            $this->assertSame('5.00', (string) $occorrenza->prezzo);
            $this->assertSame($occorrenze[$i]->data_evento->format('Y-m-d H:i'), $occorrenza->data_evento->format('Y-m-d H:i'));
        }

        // Il 14 maggio la sala non c'è: si cancella SOLO quella data
        $this->delete(route('eventi.destroy', $seconda))
            ->assertRedirect(route('eventi.index'))
            ->assertSessionHas('success', 'Evento eliminato con successo.');

        $rimaste = Evento::allTenants()->orderBy('data_evento')->get();
        $this->assertCount(3, $rimaste);
        $this->assertSame(
            ['2026-05-07 21:00', '2026-05-21 21:00', '2026-05-28 21:00'],
            $rimaste->map(fn (Evento $e) => $e->data_evento->format('Y-m-d H:i'))->all()
        );
        $this->assertSame($prima->serie_id, $rimaste->last()->serie_id);
        $this->assertNotNull(Evento::allTenants()->withTrashed()->find($seconda->id)?->deleted_at);

        $this->get(route('eventi.serie.show', $prima))
            ->assertOk()
            ->assertViewHas('occorrenze', fn ($o) => count($o) === 3);

        // Il sito e il feed riflettono le tre date rimaste
        $this->comeOspite()->get($this->urlPubblica('eventi.show', ['evento' => $seconda->id]))->assertNotFound();
        $this->assertSame(3, substr_count($this->get($this->urlPubblica('calendario.ics'))->getContent(), 'BEGIN:VEVENT'));
        $this->assertCount(3, $this->get($this->urlPubblica('eventi.index'))->viewData('eventi'));
    }

    // ------------------------------------------------------------------
    // 8. Il giorno dopo: il feedback
    // ------------------------------------------------------------------

    public function test_dopo_l_evento_solo_chi_ha_partecipato_riceve_la_richiesta_di_feedback_e_puo_votare(): void
    {
        // Il 1° maggio l'admin pubblica la serata del 3; due persone prenotano
        Carbon::setTestNow('2026-05-01 09:00:00');

        $evento = $this->adminCreaEvento([
            'titolo' => 'Serata di poesia',
            'data_evento' => '2026-05-03T21:00',
            'data_fine' => '2026-05-03T23:00',
        ]);

        $irene = $this->visitatore('Irene', 'Costa');
        $filippo = $this->visitatore('Filippo', 'Marini');
        $bigliettoIrene = $this->prenota($irene, $evento, 1);
        $bigliettoFilippo = $this->prenota($filippo, $evento, 1);

        // La sera del 3 entra solo Irene; Filippo non si presenta
        Carbon::setTestNow('2026-05-03 20:50:00');
        $this->comeAdmin()
            ->postJson(route('biglietti.checkin'), ['qr_token' => $bigliettoIrene->qr_token])
            ->assertOk()
            ->assertJson(['success' => true]);

        // La mattina dopo parte il giro di feedback (scheduler giornaliero)
        Carbon::setTestNow('2026-05-04 08:00:00');
        $this->artisan('eventi:invia-feedback')
            ->expectsOutputToContain('Richieste di feedback inviate: 1')
            ->assertSuccessful();

        $feedbackIrene = FeedbackEvento::allTenants()->where('prenotazione_id', $bigliettoIrene->id)->first();
        $this->assertNotNull($feedbackIrene);
        $this->assertNull($feedbackIrene->compilato_at);
        $this->assertTrue(FeedbackEvento::allTenants()->where('prenotazione_id', $bigliettoFilippo->id)->doesntExist());

        Notification::assertNotSentTo($filippo, FeedbackEventoNotification::class);

        $linkIrene = null;
        Notification::assertSentTo($irene, FeedbackEventoNotification::class, function (FeedbackEventoNotification $n) use ($irene, &$linkIrene): bool {
            $linkIrene = $n->toMail($irene)->actionUrl;

            return $n->feedback->prenotazione_id === $irene->prenotazioni()->value('id');
        });
        $this->assertNotNull($linkIrene);
        $this->assertStringContainsString('/feedback-eventi/'.$feedbackIrene->token, $linkIrene);

        // Irene apre il link dell'email e lascia il voto
        $this->comeOspite()->get($linkIrene)
            ->assertOk()
            ->assertSee('Serata di poesia')
            ->assertSee('Invia il feedback');

        $this->post(route('feedback-eventi.store', ['token' => $feedbackIrene->token]), [
            'voto' => 5,
            'commento' => 'Letture bellissime, sala un po\' fredda.',
        ])->assertOk()->assertSee('registrato');

        $feedbackIrene->refresh();
        $this->assertSame(5, $feedbackIrene->voto);
        $this->assertNotNull($feedbackIrene->compilato_at);

        // Ci riprova per cambiare voto: bloccata
        $this->post(route('feedback-eventi.store', ['token' => $feedbackIrene->token]), ['voto' => 1])
            ->assertSessionHasErrors('voto');
        $this->assertSame(5, $feedbackIrene->fresh()->voto);

        // Filippo non ha un link: senza firma è rifiutato, con un token inventato non esiste nulla
        $this->get('/feedback-eventi/'.$feedbackIrene->token)->assertForbidden();
        $this->get(URL::signedRoute('feedback-eventi.show', ['token' => str_repeat('f', 64)]))->assertNotFound();
        $this->post(route('feedback-eventi.store', ['token' => str_repeat('f', 64)]), ['voto' => 4])->assertNotFound();

        // L'admin legge il report
        $report = $this->comeAdmin()->get(route('eventi.feedback.index', $evento))
            ->assertOk()
            ->assertSee('Letture bellissime, sala un po&#039; fredda.', false);

        $stats = $report->viewData('stats');
        $this->assertSame(5.0, $stats['media']);
        $this->assertSame(1, $stats['totale_inviati']);
        $this->assertSame(1, $stats['totale_compilati']);
        $this->assertSame(1, $stats['distribuzione'][5]);
    }

    // ------------------------------------------------------------------
    // 9. Galleria foto
    // ------------------------------------------------------------------

    public function test_galleria_foto_caricata_dall_admin_visibile_al_pubblico_solo_quando_resa_pubblica(): void
    {
        Storage::fake('media_private');

        $evento = $this->adminCreaEvento(['titolo' => 'Festa di fine anno']);

        // Il giorno dopo la festa l'admin carica due foto
        $this->comeAdmin()
            ->post(route('eventi.galleria.store', $evento), [
                'foto' => [
                    UploadedFile::fake()->image('brindisi.jpg', 640, 480),
                    UploadedFile::fake()->image('torta.png', 640, 480),
                ],
            ])
            ->assertRedirect(route('eventi.galleria.index', $evento))
            ->assertSessionHas('success', '2 foto caricate.');

        $foto = $evento->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA);
        $this->assertCount(2, $foto);
        $this->assertSame('media_private', $foto[0]->disk);
        Storage::disk('media_private')->assertExists($foto[0]->getPathRelativeToRoot());

        $this->get(route('eventi.galleria.index', $evento))->assertOk()->assertSee('(2 caricate)');

        // Finché è privata, dal sito non si vede nulla e i file non si scaricano
        $urlFotoPubblica = $this->urlPubblica('eventi.galleria.file', ['evento' => $evento->id, 'media' => $foto[0]->id]);

        $this->comeOspite()
            ->get($this->urlPubblica('eventi.show', ['evento' => $evento->id]))
            ->assertOk()
            ->assertDontSee('Galleria foto');
        $this->get($urlFotoPubblica)->assertNotFound();

        // L'admin la rende pubblica
        $this->comeAdmin()
            ->post(route('eventi.galleria.visibilita', $evento))
            ->assertRedirect(route('eventi.galleria.index', $evento))
            ->assertSessionHas('success', 'Galleria ora visibile sulla pagina pubblica dell\'evento.');
        $this->assertTrue($evento->fresh()->galleria_pubblica);

        $this->comeOspite()
            ->get($this->urlPubblica('eventi.show', ['evento' => $evento->id]))
            ->assertOk()
            ->assertSee('Galleria foto')
            ->assertSee($urlFotoPubblica, false);

        $download = $this->get($urlFotoPubblica)->assertOk();
        $this->assertSame($foto[0]->mime_type, $download->headers->get('Content-Type'));

        // Un socio chiede di togliere la galleria: torna privata e il link non funziona più
        $this->comeAdmin()
            ->post(route('eventi.galleria.visibilita', $evento))
            ->assertSessionHas('success', 'Galleria nascosta dalla pagina pubblica.');

        $this->comeOspite()->get($urlFotoPubblica)->assertNotFound();

        // L'admin però la vede ancora dal pannello e cancella la foto contestata
        $this->comeAdmin()
            ->get(route('eventi.galleria.file', ['evento' => $evento->id, 'media' => $foto[0]->id]))
            ->assertOk();

        $this->delete(route('eventi.galleria.destroy', ['evento' => $evento->id, 'media' => $foto[0]->id]))
            ->assertRedirect(route('eventi.galleria.index', $evento))
            ->assertSessionHas('success', 'Foto eliminata.');

        $rimaste = $evento->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA);
        $this->assertCount(1, $rimaste);
        $this->assertSame('torta.png', $rimaste[0]->file_name);
    }

    // ------------------------------------------------------------------
    // 10. I conti dell'evento
    // ------------------------------------------------------------------

    public function test_economia_dell_evento_budget_spese_incassi_e_prima_nota(): void
    {
        $evento = $this->adminCreaEvento([
            'titolo' => 'Spettacolo teatrale',
            'prezzo' => '20',
        ]);

        // Una famiglia prenota due posti e paga alla cassa: 40 euro incassati
        $famiglia = $this->visitatore('Rita', 'Benedetti');
        $prenotazione = $this->prenota($famiglia, $evento, 2);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $prenotazione->stato);

        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('conferma-pagamento', $prenotazione))
            ->assertSessionHas('success');

        // Prima delle spese: incasso 40, margine 40, budget non gestito
        $this->get(route('eventi.economia.show', $evento))
            ->assertOk()
            ->assertViewHas('incassi', 40.0)
            ->assertViewHas('totaleSpese', 0.0)
            ->assertViewHas('margine', 40.0)
            ->assertSee('40,00');

        // L'admin fissa il budget e registra le spese della serata
        $this->put(route('eventi.economia.budget', $evento), ['budget' => '100'])
            ->assertRedirect(route('eventi.economia.show', $evento))
            ->assertSessionHas('success', 'Budget aggiornato.');
        $this->assertSame('100.00', (string) $evento->fresh()->budget);

        $this->post(route('eventi.economia.spese.store', $evento), [
            'descrizione' => 'Affitto sala',
            'importo' => '60',
            'data' => '2026-05-23',
        ])->assertRedirect(route('eventi.economia.show', $evento))
            ->assertSessionHas('success', 'Spesa registrata.');

        $this->post(route('eventi.economia.spese.store', $evento), [
            'descrizione' => 'Compenso compagnia',
            'importo' => '55,50',
            'data' => '2026-05-23',
        ])->assertSessionHasErrors('importo'); // la virgola italiana non è accettata qui

        $this->post(route('eventi.economia.spese.store', $evento), [
            'descrizione' => 'Compenso compagnia',
            'importo' => '55.50',
            'data' => '2026-05-23',
        ])->assertSessionHas('success', 'Spesa registrata.');

        $this->assertSame(2, SpesaEvento::allTenants()->where('evento_id', $evento->id)->count());

        // Il riepilogo: spese 115,50 contro 40 di incasso, 15,50 oltre il budget
        $riepilogo = $this->get(route('eventi.economia.show', $evento))
            ->assertOk()
            ->assertViewHas('incassi', 40.0)
            ->assertViewHas('totaleSpese', 115.5)
            ->assertViewHas('margine', -75.5)
            ->assertSee('Affitto sala')
            ->assertSee('Compenso compagnia')
            ->assertSee('100,00')
            ->assertSee('115,50')
            ->assertSee('-75,50')
            ->assertSee('Le spese superano il budget previsto di')
            ->assertSee('15,50');
        $this->assertCount(2, $riepilogo->viewData('spese'));

        // In prima nota c'è l'incasso della prenotazione; le spese dell'evento
        // restano nella scheda economia (nessun movimento automatico previsto)
        $movimenti = Movimento::allTenants()->where('associazione_id', $this->associazione->id)->get();
        $this->assertCount(1, $movimenti);
        $this->assertSame(Movimento::TIPO_ENTRATA, $movimenti[0]->tipo);
        $this->assertEqualsWithDelta(40.0, (float) $movimenti[0]->importo, 0.001);
        $this->assertSame($prenotazione->id, $movimenti[0]->origine_id);
        $this->assertStringContainsString('Spettacolo teatrale', (string) $movimenti[0]->descrizione);

        // Una spesa inserita per errore viene tolta e il saldo si aggiorna
        $affitto = SpesaEvento::allTenants()->where('descrizione', 'Affitto sala')->firstOrFail();
        $this->delete(route('eventi.economia.spese.destroy', $affitto))
            ->assertRedirect(route('eventi.economia.show', $evento))
            ->assertSessionHas('success', 'Spesa eliminata.');

        $this->get(route('eventi.economia.show', $evento))
            ->assertOk()
            ->assertViewHas('totaleSpese', 55.5)
            ->assertViewHas('margine', -15.5)
            ->assertDontSee('Le spese superano il budget previsto di');
    }
}
