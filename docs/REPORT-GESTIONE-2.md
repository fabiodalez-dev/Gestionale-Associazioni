# Report — Gestione 2: operatività, comunicazione, economia, membri, piattaforma

Implementazione completa del piano `docs/PIANO-GESTIONE-2.md` (workflow multi-agente: fondamenta → 5 moduli paralleli → finisher: scheduler, seeder demo, verifiche incrociate). Le 25 funzionalità sono tutte implementate e coperte da feature test PHPUnit dedicati; le suite preesistenti (PHPUnit + E2E) restano verdi.

## Architettura della consegna

- **Fondamenta**: migration unica additiva `database/migrations/2026_06_12_110000_add_gestione_foundation.php` (SQLite-compatibile) con tutte le tabelle/colonne nuove (`note_socio`, `attivita_admin`, `annunci`, `newsletter_inviate`, `sondaggi`, `risposte_sondaggio`, `categorie_movimento`, `movimenti`, `donazioni`, `spese_evento`, `richieste_modifica_dati`, `nuclei`, `accessi` + colonne su `associazioni`, `eventi`, `soci`), i Model condivisi e le voci sidebar con helper `$nav()` tollerante.
- **Moduli**: `operativita` (A, f.1–f.6), `comunicazione` (B, f.7–f.12), `economia` (C, f.13–f.18), `membri` (D, f.19–f.22), `piattaforma` (E, f.23–f.25). Rotte in `routes/modules/<modulo>.php`, Form Request + Policy su permessi spatie esistenti (prima nota e affini sulla famiglia `quote.*`), notifiche `ShouldQueue` brandizzate (layout email f.18 del round 1, `TenantNotificationMailer`).
- **Finisher**: scheduler in `routes/console.php` (`attivita:promemoria` 07:15, `compleanni:auguri` 08:15, `cestino:svuota` lunedì 05:00, tutti `withoutOverlapping`), `DemoSeeder` arricchito (annuncio, sondaggio, prima nota con 3 movimenti, donazione pagata, nucleo familiare, attività admin), questo report, cross-check sidebar ↔ `route:list` (47 nomi rotta in sidebar, nessuna voce orfana).

---

## A — Operatività quotidiana

### f.1 — Ricerca globale (⌘K) — ✅
- **Rotte**: `GET /ricerca-globale` (`ricerca-globale`, JSON, throttle 30/min, tenant-scoped).
- **File**: `app/Http/Controllers/Operativita/RicercaGlobaleController.php` (soci via Scout — driver `database` —, LIKE per eventi/prenotazioni per codice/documenti, catalogo pagine admin filtrato sui permessi), modale `resources/views/operativita/_global-search.blade.php` inclusa nel layout.
- **Come provarla**: in qualsiasi pagina admin premi ⌘K / Ctrl+K (o l'icona lente in sidebar) → digita nome/email/numero socio, titolo evento, codice prenotazione o nome pagina → risultati raggruppati, navigabili con frecce + Invio. Test: `tests/Feature/Operativita/RicercaGlobaleTest.php`.

### f.2 — Azioni in blocco sui soci — ✅
- **Rotte**: `POST /soci/azioni-blocco` (`soci.azioni-blocco`), `POST /soci/azioni-blocco/export` (`soci.azioni-blocco.export`, Excel della selezione).
- **File**: `app/Http/Controllers/Operativita/AzioniBloccoSociController.php`, barra `resources/views/operativita/_azioni-blocco.blade.php` (checkbox multiple nella lista soci); ogni applicazione scrive una riga di audit nello storico attività di ciascun socio.
- **Come provarla**: lista Soci → seleziona più righe → barra azioni (cambia stato/tipo/categoria/sede, assegna/rimuovi tag, esporta selezione) → conferma con riepilogo. Test: `tests/Feature/Operativita/AzioniBloccoSociTest.php`.

### f.3 — Note interne sul socio — ✅
- **Rotte**: `POST /soci/{socio}/note` (`soci.note.store`), `PATCH /soci/{socio}/note/{nota}/pin` (`soci.note.pin`), `DELETE /soci/{socio}/note/{nota}` (`soci.note.destroy`).
- **File**: `app/Models/NotaSocio.php` (tabella `note_socio`: autore admin, testo, `fissata`), `app/Http/Controllers/Operativita/NotaSocioController.php`, tab `resources/views/operativita/_tab-note.blade.php` nella scheda socio.
- **Come provarla**: scheda socio → tab "Note": timeline delle note interne (distinte dallo storico automatico), pin per fissarle in cima, visibili solo agli admin del tenant. Test: `tests/Feature/Operativita/NoteSocioTest.php`.

### f.4 — Attività/promemoria admin — ✅
- **Rotte**: `GET|POST /attivita-admin` (`attivita-admin.index|store`), `PUT /attivita-admin/{attivita}` (`attivita-admin.update`), `POST /attivita-admin/{attivita}/toggle` (`attivita-admin.toggle`), `DELETE` (`attivita-admin.destroy`); comando `php artisan attivita:promemoria` (schedulato daily 07:15).
- **File**: `app/Models/AttivitaAdmin.php` (tabella `attivita_admin`: scadenza, assegnatario, collegamento polimorfico `notabile` a socio/evento, stato aperta/completata), `app/Http/Controllers/Operativita/AttivitaAdminController.php`, `app/Console/Commands/AttivitaPromemoria.php` (anti-duplicazione via activitylog, rieseguibile in sicurezza), `app/Notifications/Operativita/PromemoriaAttivitaNotification.php`, widget `resources/views/operativita/_widget-attivita.blade.php` in dashboard.
- **Come provarla**: sidebar → "Attività": crea un'attività con scadenza e assegnatario; il widget "Le mie attività" appare in dashboard; il giorno della scadenza il comando invia l'email all'assegnatario. Il demo include un'attività aperta collegata all'assemblea. Test: `tests/Feature/Operativita/AttivitaAdminTest.php`.

### f.5 — Viste salvate ovunque — ✅
- **Rotte**: `POST /viste-salvate/{scope}` (`viste-salvate.store`, scope `tessere|quote|prenotazioni`), `DELETE /viste-salvate/{filtro}` (`viste-salvate.destroy`).
- **File**: `app/Http/Controllers/Operativita/VisteSalvateController.php` (riusa il modello `SavedFilter` esistente; whitelist parametri per scope + permesso dedicato), partial `resources/views/operativita/_viste-salvate.blade.php` nelle liste tessere, quote e prenotazioni evento.
- **Come provarla**: pagina Tessere/Quote/Prenotazioni evento → applica filtri → "Salva vista" → il preset compare nel menu della pagina (condiviso per tenant), applicabile/eliminabile. Test: `tests/Feature/Operativita/VisteSalvateTest.php`.

### f.6 — Registro modifiche (UI per activitylog) — ✅
- **Rotte**: `GET /registro-modifiche` (`registro-modifiche.index`, permesso `struttura.view`).
- **File**: `app/Http/Controllers/Operativita/RegistroModificheController.php` (sul log spatie/activitylog già attivo), vista `resources/views/operativita/registro-modifiche/`.
- **Come provarla**: sidebar → Amministrazione → "Registro modifiche": filtri per modello/utente/periodo/azione, diff leggibile dei campi (old → new), paginazione. Test: `tests/Feature/Operativita/RegistroModificheTest.php`.

---

## B — Comunicazione & coinvolgimento

### f.7 — Bacheca annunci — ✅
- **Rotte**: admin CRUD `GET|POST /annunci`, `GET /annunci/nuovo`, `GET /annunci/{annuncio}/modifica`, `PUT|DELETE /annunci/{annuncio}` (`annunci.*`); pubblico `GET /a/{slug}/novita` (`pubblico.novita`); area soci `GET /area-soci/bacheca` (`area-soci.bacheca`, inclusi i `solo_soci`).
- **File**: `app/Models/Annuncio.php` (corpo HTML purificato, finestra `pubblicato_dal/al`, `in_evidenza`, `solo_soci`), `app/Http/Controllers/Comunicazione/AnnuncioController.php`, `NovitaPubblicheController.php`, `BachecaSocioController.php`, card `resources/views/comunicazione/_annuncio-card.blade.php`; sezione "Novità" anche sulla home del mini-sito `/a/{slug}`.
- **Come provarla**: sidebar → Email & Notifiche → "Annunci": crea un annuncio pubblicato → appare su `/a/associazione-demo/novita` (se non solo-soci) e nella bacheca dell'area soci. Il demo include un annuncio in evidenza. Test: `tests/Feature/Comunicazione/AnnunciTest.php`.

### f.8 — Newsletter da annunci — ✅
- **Rotte**: `GET /newsletter` (`newsletter.index`, composizione + storico), `POST /newsletter/anteprima` (`newsletter.anteprima`, render col layout brandizzato), `POST /newsletter` (`newsletter.store`, invio).
- **File**: `app/Http/Controllers/Comunicazione/NewsletterController.php`, `app/Services/Comunicazione/NewsletterService.php` (destinatari = soci attivi con email, rispetta l'opt-out, accodamento via coda email del tenant), `app/Models/NewsletterInviata.php` (storico con conteggio destinatari).
- **Come provarla**: sidebar → Email & Notifiche → "Newsletter": seleziona annunci pubblicati → anteprima brandizzata → invia; lo storico elenca invii e destinatari raggiunti. Test: `tests/Feature/Comunicazione/NewsletterTest.php`.

### f.9 — Compleanni — ✅
- **Rotte/comando**: widget "Compleanni del mese" in dashboard (`resources/views/comunicazione/dashboard/_compleanni.blade.php`); toggle per tenant `POST /configurazioni/comunicazione` (`configurazioni.comunicazione`, campo `associazioni.auguri_automatici`); comando `php artisan compleanni:auguri` (schedulato daily 08:15).
- **File**: `app/Console/Commands/CompleanniAuguri.php`, `app/Services/Comunicazione/CompleanniService.php`, `app/Notifications/Comunicazione/AuguriCompleannoNotification.php`; anti-duplicazione su `promemoria_inviati` (tipo `compleanno`, step = anno corrente).
- **Come provarla**: Configurazioni → attiva "Auguri automatici"; con un socio attivo nato oggi lancia il comando → email di auguri (rilanci = nessun doppio invio); il widget dashboard mostra i compleanni del mese. Test: `tests/Feature/Comunicazione/CompleanniTest.php`.

### f.10 — Contatti rapidi — ✅
- **Dove**: scheda socio e liste — bottoni `wa.me` (con normalizzazione del prefisso internazionale, default +39), `mailto:` e `tel:`. Pura UI, zero API esterne.
- **File**: `app/Services/Comunicazione/ContattiRapidi.php` (helper statici per URL WhatsApp/telefono), partial nelle viste soci.
- **Come provarla**: scheda socio con telefono → icone WhatsApp/chiama/email accanto ai contatti. Test: `tests/Feature/Comunicazione/ContattiRapidiTest.php`.

### f.11 — Sondaggi ai soci — ✅
- **Rotte**: admin CRUD + risultati `GET|POST /sondaggi`, `GET /sondaggi/{sondaggio}` (risultati aggregati), `GET /sondaggi/nuovo`, `GET /sondaggi/{sondaggio}/modifica`, `PUT|DELETE` (`sondaggi.*`); voto area soci `GET /area-soci/sondaggi` + `POST /area-soci/sondaggi/{sondaggio}/vota` (`area-soci.sondaggi*`, throttle 20/min); voto area account `GET /a/{slug}/account/sondaggi` + `POST .../vota` (`pubblico.account.sondaggi*`).
- **File**: `app/Models/Sondaggio.php` (domanda, `opzioni` json, finestra `aperto_dal/al`, `solo_soci`) e `RispostaSondaggio.php` (unique sondaggio+votante polimorfico socio/account), `app/Http/Controllers/Comunicazione/SondaggioController.php`, `SondaggiSocioController.php`, `SondaggiAccountController.php`, `app/Services/Comunicazione/SondaggiVotoService.php`, partial voto `resources/views/comunicazione/_sondaggio-voto.blade.php`.
- **Come provarla**: sidebar → Email & Notifiche → "Sondaggi": crea un sondaggio aperto → vota dall'area soci o dall'area account del mini-sito (un voto a testa) → risultati con barre % nel dettaglio admin. Il demo include un sondaggio aperto. Test: `tests/Feature/Comunicazione/SondaggiTest.php`.

### f.12 — Messaggio diretto al socio — ✅
- **Rotte**: `POST /soci/{socio}/invia-email` (`soci.invia-email`).
- **File**: `app/Http/Controllers/Comunicazione/MessaggioSocioController.php` (oggetto + corpo testo → HTML escapato nel layout brandizzato, via coda email del tenant, riga `email_diretta` nello storico attività del socio), `app/Http/Requests/Comunicazione/MessaggioSocioRequest.php`, modale nella scheda socio.
- **Come provarla**: scheda socio → "Invia email" → oggetto e messaggio → l'invio è accodato (richiede SMTP del tenant configurato) e tracciato nello storico; con opt-out attivo l'invio è bloccato. Test: `tests/Feature/Comunicazione/MessaggioSocioTest.php`.

---

## C — Economia & trasparenza

### f.13 — Prima nota — ✅
- **Rotte**: `GET|POST /prima-nota` (`prima-nota.index|store`), `PUT|DELETE /prima-nota/{movimento}` (`prima-nota.update|destroy`). Permessi famiglia `quote.*`.
- **File**: `app/Models/Movimento.php` + `CategoriaMovimento.php` (tabelle `movimenti`/`categorie_movimento`; unique su origine per idempotenza), `app/Http/Controllers/Economia/PrimaNotaController.php`, `app/Services/Economia/PrimaNotaService.php`: 8 categorie di default seedate per tenant al primo accesso e hook automatici best-effort (`registraIncassoQuotaBestEffort`, `registraIncassoPrenotazioneBestEffort`, `registraDonazioneBestEffort`) agganciati ai flussi di pagamento esistenti — mai eccezioni, al massimo un movimento per origine anche su webhook replay.
- **Come provarla**: sidebar → Economia → "Prima nota": saldo progressivo, movimenti manuali entrata/uscita con categoria/metodo; paga una quota o una prenotazione → il movimento entrata compare da solo. Il demo include 3 movimenti (quota, donazione, affitto). Test: `tests/Feature/Economia/PrimaNotaTest.php`, `HookPrimaNotaTest.php`.

### f.14 — Report economico — ✅
- **Rotte**: `GET /report-economico` (`report-economico.index`, periodo selezionabile), `GET /report-economico/export` (`report-economico.export`, Excel).
- **File**: `app/Http/Controllers/Economia/ReportEconomicoController.php`, `app/Exports/Economia/MovimentiExport.php`, vista `resources/views/economia/report/` (entrate/uscite per categoria, andamento mensile con Chart.js già vendorizzato, saldo).
- **Come provarla**: sidebar → Economia → "Report economico": scegli il periodo → tabelle per categoria + grafico mensile + saldo; bottone export Excel. Test: `tests/Feature/Economia/ReportEconomicoTest.php`.

### f.15 — Donazioni online — ✅
- **Rotte**: pubblico `GET|POST /a/{slug}/dona` (`pubblico.dona.show|store`, honeypot + throttle 10/min), `GET /a/{slug}/dona/{donazione}/esito` (`pubblico.dona.esito`); admin `GET /donazioni` (`donazioni.index`).
- **File**: `app/Models/Donazione.php` (implementa l'interfaccia `Pagabile`, opzione anonimato), `app/Http/Controllers/Economia/DonazionePubblicaController.php`, `DonazioneAdminController.php`, `app/Services/Economia/DonazioneService.php`, `app/Notifications/Economia/RicevutaDonazioneNotification.php`. La conferma arriva SEMPRE dai webhook Stripe/PayPal già esistenti (dispatch tipo `donazione`); alla conferma: ricevuta email + movimento automatico in prima nota.
- **Come provarla**: `/a/associazione-demo/dona` → importo libero (con minimo), nome/email, anche anonima → checkout Stripe/PayPal (gateway del tenant configurati) → email di ringraziamento e riga in Economia → Donazioni + prima nota. Il demo include una donazione pagata. Test: `tests/Feature/Economia/DonazioniTest.php`.

### f.16 — Solleciti quote — ✅
- **Rotte**: `GET /solleciti-quote` (`solleciti-quote.index`, quote non pagate con conteggio solleciti e data ultimo invio), `POST /solleciti-quote/{quota}` (`solleciti-quote.invia`, singolo), `POST /solleciti-quote/bulk` (`solleciti-quote.bulk`).
- **File**: `app/Http/Controllers/Economia/SollecitoQuoteController.php`, `app/Services/Economia/SollecitoQuoteService.php` (tracking su `promemoria_inviati`, tipo `sollecito_quota`, step = anno quota), `app/Notifications/Economia/SollecitoQuotaNotification.php` (email brandizzata col link di pagamento).
- **Come provarla**: sidebar → Economia (da Quote) → "Solleciti": seleziona le quote non pagate → sollecito singolo o bulk → la lista mostra n. solleciti e ultimo invio. Test: `tests/Feature/Economia/SollecitiQuoteTest.php`.

### f.17 — Budget evento — ✅
- **Rotte**: `GET /eventi/{evento}/economia` (`eventi.economia.show`), `PUT /eventi/{evento}/economia/budget` (`eventi.economia.budget`), `POST /eventi/{evento}/economia/spese` (`eventi.economia.spese.store`), `DELETE /economia/spese-evento/{spesa}` (`eventi.economia.spese.destroy`).
- **File**: campo `eventi.budget` + `app/Models/SpesaEvento.php` (tabella `spese_evento`), `app/Http/Controllers/Economia/EconomiaEventoController.php`, vista `resources/views/economia/eventi/`.
- **Come provarla**: dettaglio evento → sezione "Economia": imposta il budget, registra le spese → confronto spese vs incassi delle prenotazioni pagate e margine. Test: `tests/Feature/Economia/EconomiaEventoTest.php`.

### f.18 — Scadenzario unificato — ✅
- **Rotte**: `GET /scadenzario` (`scadenzario.index`).
- **File**: `app/Http/Controllers/Economia/ScadenzarioController.php` (vista aggregata, NESSUNA tabella nuova: quote in scadenza, documenti socio in scadenza, mandati cariche in scadenza, scadenze custom esistenti), vista `resources/views/economia/scadenzario/`.
- **Come provarla**: sidebar → "Scadenzario": lista unificata con filtri per tipo e range di date; ogni riga linka alla pagina di origine. Test: `tests/Feature/Economia/ScadenzarioTest.php`.

---

## D — Membri & self-service

### f.19 — Richieste modifica dati — ✅
- **Rotte**: admin `GET /richieste-modifica-dati` (`richieste-modifica-dati.index`), `POST .../{richiesta}/approva|rifiuta` (`richieste-modifica-dati.approva|rifiuta`); area soci `GET|POST /area-soci/modifica-dati` (`area-soci.modifica-dati*`, throttle 10/min); area account `GET|POST /a/{slug}/account/modifica-dati` (`pubblico.account.modifica-dati*`, solo account collegati a un socio).
- **File**: `app/Models/RichiestaModificaDati.php` (campi proposti json, origine polimorfica socio/account), `app/Http/Controllers/Membri/RichiestaModificaDatiAdminController.php`, `ModificaDatiSocioController.php`, `ModificaDatiAccountController.php`, `app/Services/Membri/ModificaDatiService.php` (approvazione = applica al socio + storico); badge col conteggio pendenti in sidebar ("Richieste modifiche").
- **Come provarla**: area soci → "Modifica dati" → proponi un cambio → in admin appare il diff campo per campo → approva (dati applicati + storico) o rifiuta. Test: `tests/Feature/Membri/RichiesteModificaDatiTest.php`.

### f.20 — Nuclei familiari — ✅
- **Rotte**: `GET /soci-nuclei` (`nuclei.index`, vista raggruppata per nucleo), `POST /nuclei` (`nuclei.store`), `PUT|DELETE /nuclei/{nucleo}` (`nuclei.update|destroy`), `POST /nuclei/{nucleo}/membri` (`nuclei.membri.store`), `DELETE /nuclei/{nucleo}/membri/{socio}` (`nuclei.membri.destroy`).
- **File**: `app/Models/Nucleo.php` (capofamiglia + membri via `soci.nucleo_id`), `app/Http/Controllers/Membri/NucleoController.php`, viste `resources/views/membri/nuclei/` + gestione nella scheda socio e indicazione nei dettagli.
- **Come provarla**: scheda socio → "Crea nucleo"/"Aggiungi al nucleo"; pagina "Nuclei" con vista raggruppata. Il demo include la "Famiglia Verdi-Bianchi" (Anna capofamiglia + Luca). Test: `tests/Feature/Membri/NucleiFamiliariTest.php`.

### f.21 — Check-in kiosk — ✅
- **Rotte**: `GET /checkin-kiosk/{evento}` (`checkin-kiosk.show`, fullscreen con `ScannerSecurityHeaders` al posto di CSP/SecurityHeaders standard per la fotocamera), `GET /checkin-kiosk/{evento}/contatore` (`checkin-kiosk.contatore`, JSON live, throttle 120/min).
- **File**: `app/Http/Controllers/Membri/CheckinKioskController.php`, layout dedicato senza sidebar `resources/views/membri/checkin-kiosk.blade.php` (html5-qrcode già in `assets/vendor`, scanner continuo, esito grande verde/rosso con auto-reset 3s, contatore presenze).
- **Come provarla**: dettaglio evento → "Modalità kiosk" (o URL diretto da utente autenticato) → schermo intero da totem: inquadra i QR dei biglietti → esito immediato + contatore live. Test: `tests/Feature/Membri/CheckinKioskTest.php`.

### f.22 — Soci minorenni — ✅
- **Dove**: nessuna rotta dedicata — campi `soci.tutore_nome/tutore_telefono/tutore_email`; se la data di nascita indica età <18 al momento dell'iscrizione i campi tutore diventano obbligatori sia nel form pubblico di iscrizione sia in admin (validazione dinamica nei Form Request soci/iscrizione); badge "Minorenne" nelle liste e colonna tutore nell'export.
- **File**: Form Request del modulo soci e iscrizioni (validazione condizionale), partial badge/colonne nelle viste e negli export.
- **Come provarla**: inserisci un socio con data di nascita <18 anni → i campi tutore diventano obbligatori; nelle liste compare il badge "Minorenne"; l'export soci include la colonna tutore. Test: `tests/Feature/Membri/SociMinorenniTest.php`.

---

## E — Piattaforma & qualità

### f.23 — Onboarding admin — ✅
- **Rotte**: `POST /onboarding/dismiss` (`onboarding.dismiss`); card in dashboard (`resources/views/piattaforma/_onboarding-card.blade.php`, inclusa con `@includeIf`).
- **File**: `app/Services/Piattaforma/OnboardingChecklist.php` — ogni passo è calcolato dallo STATO REALE del tenant (logo, slug, SMTP configurato, costo tessera, form iscrizione, primo evento pubblicato, primo socio) con link diretto alla pagina dove completarlo; dismiss persistente per tenant (`Cache::forever`); al 100% la card sparisce da sola.
- **Come provarla**: dashboard di un tenant nuovo → card con progress e checklist cliccabile; completa i passi (o premi "Nascondi") → la card scompare. Test: `tests/Feature/Piattaforma/OnboardingTest.php`.

### f.24 — Cestino unificato — ✅
- **Rotte**: `GET /cestino` (`cestino.index`, filtro per tipo), `POST /cestino/{tipo}/{id}/ripristina` (`cestino.ripristina`), `DELETE /cestino/{tipo}/{id}` (`cestino.elimina-definitivamente`); comando `php artisan cestino:svuota {--giorni=90}` (schedulato weekly, lunedì 05:00).
- **File**: `app/Http/Controllers/Piattaforma/CestinoController.php` (soft-deleted di soci/eventi/documenti/verbali, permessi per modulo), `app/Console/Commands/CestinoSvuota.php` (forceDelete record per record per innescare gli eventi del model, es. pulizia file medialibrary), vista `resources/views/piattaforma/cestino/`.
- **Come provarla**: elimina un socio/evento/documento/verbale → sidebar → "Cestino": ripristina o elimina definitivamente; gli elementi oltre 90 giorni vengono svuotati dal comando settimanale. Test: `tests/Feature/Piattaforma/CestinoUnificatoTest.php`.

### f.25 — Audit accessi — ✅
- **Rotte**: `GET /audit-accessi` (`audit-accessi.index`, senza middleware `tenant`: il super_admin in vista globale vede anche le righe senza tenant).
- **File**: `app/Models/Accesso.php` (tabella `accessi`: guard, autenticabile polimorfico utente/socio/account, email tentata, ip, user agent, esito login/fallito/logout), listener `app/Listeners/RegistraAccesso.php` (eventi `Login`/`Failed`/`Logout` di Laravel per TUTTE le guard, best-effort: mai blocca il login) e `app/Listeners/NotificaTentativiFallitiAccesso.php` (alert email agli admin del tenant — o super_admin — dopo 5 tentativi falliti in 10 minuti sulla stessa email, throttled: un alert per email per finestra), `app/Notifications/Piattaforma/TentativiAccessoFallitiNotification.php`, `app/Http/Controllers/Piattaforma/AuditAccessiController.php`.
- **Come provarla**: fai login/logout o un login errato su admin/area soci/area account → sidebar → Amministrazione → "Audit accessi" con filtri per guard/esito/email/periodo; 5 password errate sulla stessa email in 10 minuti → email di alert agli admin. Test: `tests/Feature/Piattaforma/AuditAccessiTest.php`.

---

## Scheduler (routes/console.php)

| Comando | Orario | Funzionalità |
|---|---|---|
| `attivita:promemoria` | daily 07:15 | f.4 promemoria attività admin in scadenza oggi |
| `compleanni:auguri` | daily 08:15 | f.9 auguri automatici (tenant con `auguri_automatici`) |
| `cestino:svuota` | weekly, lunedì 05:00 | f.24 svuotamento cestino oltre 90 giorni |

Tutti con `withoutOverlapping`; verificati con `php artisan schedule:list` (rispettivamente `15 7 * * *`, `15 8 * * *`, `0 5 * * 1`). Tutti i comandi sono idempotenti/rieseguibili: i due promemoria deduplicano (activitylog / `promemoria_inviati`), lo svuotamento agisce solo su soft-deleted oltre soglia.

## Dati demo (DemoSeeder)

Oltre ai contenuti dei round precedenti (senza factory, eseguibile con `composer install --no-dev`): un annuncio pubblicato in evidenza ("Aperte le iscrizioni…"), un sondaggio aperto con 3 opzioni, le 8 categorie di prima nota seedate + 3 movimenti (incasso quota e donazione generati dagli STESSI hook automatici di `PrimaNotaService`, più un'uscita manuale "Affitto sede"), una donazione pagata di Carla Fontana (€50), il nucleo "Famiglia Verdi-Bianchi" (Anna capofamiglia + Luca) e un'attività admin aperta collegata all'assemblea annuale (senza assegnatario: il seeder non crea utenti amministratori). Verificato con `tests/Feature/Installer/DemoSeederTest.php` + assert dedicati eseguiti in fase di rifinitura.

## Verifica sidebar ↔ rotte

Cross-check automatico dei 47 nomi rotta referenziati in `resources/views/layouts/sidebar.blade.php` (helper `$nav()` + `route()`) contro `php artisan route:list --json`: **nessuna voce orfana**. Verificata anche la registrazione di tutte le rotte dei 5 moduli del round 2 (`operativita`, `comunicazione`, `economia`, `membri`, `piattaforma`).

## Limitazioni note

- **f.1**: la ricerca soci usa Laravel Scout col driver `database` (LIKE indicizzato), non un motore full-text esterno; eventi/prenotazioni/documenti sono `LIKE %…%` puri.
- **f.4 / f.9 / f.24**: i comandi schedulati richiedono il cron di sistema (`php artisan schedule:run`) e — per le email — il queue worker attivo (notifiche `ShouldQueue`).
- **f.8 / f.12 / f.16**: gli invii reali dipendono dall'SMTP del tenant (fallback al mailer di default dell'app); l'opt-out email del socio è sempre rispettato.
- **f.11**: il voto referenzia l'indice dell'opzione: modificare le opzioni di un sondaggio già votato può disallineare i risultati storici.
- **f.13**: i movimenti automatici nascono dai pagamenti confermati DOPO l'introduzione del modulo: nessun backfill dei pagamenti storici (registrabili a mano in prima nota).
- **f.15**: la pagina pubblica `/a/{slug}/dona` richiede i gateway Stripe/PayPal del tenant configurati; la conferma passa solo dai webhook (nessuna conferma sincrona).
- **f.18**: lo scadenzario è una vista aggregata read-only (nessuna tabella propria): le azioni si fanno nelle pagine di origine.
- **f.21**: il kiosk richiede HTTPS (vincolo browser per la fotocamera) e una sessione admin autenticata sul totem.
- **f.22**: il vincolo tutore vale al momento dell'inserimento/iscrizione: il compimento dei 18 anni non rimuove automaticamente i dati tutore (restano come storico).
- **f.23**: il dismiss usa la cache dell'app (`Cache::forever`): un `cache:clear` ripresenta la card (finché la checklist non è al 100%).
- **f.25**: l'audit registra gli esiti delle guard di sessione (`web`, `socio`, `account`), non le chiamate API con chiave; l'alert è per-email (un attacco distribuito su email diverse non supera la soglia singola).
