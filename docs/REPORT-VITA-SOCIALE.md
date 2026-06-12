# Report — Vita sociale: rinnovi, eventi, registro, email, anagrafica pro

Implementazione completa del piano `docs/PIANO-VITA-SOCIALE.md` (workflow multi-agente: fondamenta → 5 moduli paralleli → finisher: scheduler, seeder demo, verifiche incrociate). Le 25 funzionalità sono tutte implementate e coperte da feature test PHPUnit dedicati; le suite preesistenti restano verdi.

## Architettura della consegna

- **Fondamenta**: migration unica additiva `database/migrations/2026_06_12_100000_add_vita_sociale_foundation.php` (SQLite-compatibile) con tutte le tabelle/colonne nuove (`promemoria_inviati`, `feedback_eventi`, `cessazioni`, `cariche`, `ricevute` + colonne su `associazioni`, `eventi`, `richieste_iscrizione`, `verbali`, `documenti_socio`), i Model condivisi (`Carica`, `Cessazione`, `Ricevuta`, `FeedbackEvento`, `PromemoriaInviato`) e le voci sidebar con helper `$nav()` tollerante.
- **Moduli**: `rinnovi` (A, f.1–6), `eventi-vita` (B, f.7–12), `registro` (C, f.13/15/16 — f.14 e f.17 estendono i moduli iscrizioni e documenti-verbali-scadenze), `email-conferme` (D, f.18–21), `anagrafica-pro` (E, f.22–25). Rotte in `routes/modules/<modulo>.php`, Form Request + Policy su permessi spatie esistenti, notifiche `ShouldQueue` database+mail via `TenantNotificationMailer`.
- **Finisher**: scheduler in `routes/console.php` (`rinnovi:promemoria` 07:00, `documenti:promemoria-scadenze` 07:45, `eventi:invia-feedback` 10:00, tutti `withoutOverlapping`), `DemoSeeder` arricchito (cariche, cessazione storica, verbale 1/{anno}, serie ricorrente solo-soci, ricevuta 1/{anno}), questo report, cross-check sidebar ↔ `route:list` (nessuna voce orfana).

---

## A — Rinnovi & tesseramento

### f.1 — Rinnovo online self-service — ✅
- **Rotte**: `POST /area-soci/rinnova` (`area-soci.rinnova`, guard `socio`), `POST /a/{slug}/account/rinnova` (`pubblico.account.rinnova`, guard `account`, solo account collegati a un socio). Entrambe throttle 10/min.
- **File**: `app/Http/Controllers/Rinnovi/RinnovoSocioController.php`, `RinnovoAccountController.php`, `app/Services/Rinnovi/RinnovoService.php` (riusa `PaymentService::createQuotaForSocio` + redirect a `pagamento.show` col `payment_token`); hook tessera in `PaymentService::onPaymentCompleted`.
- **Come provarla**: area soci o area account del mini-sito → pulsante "Rinnova per il {anno}" → pagina di pagamento pubblica; al pagamento la tessera dell'anno viene emessa/rinnovata. Test: `tests/Feature/Rinnovi/RinnovoSelfServiceTest.php`, `RinnovoAccountTest.php`, `RinnovoHookPagamentoTest.php`.

### f.2 — Campagna rinnovi — ✅
- **Rotte**: `GET /campagna-rinnovi` (`campagna-rinnovi.index`), `POST /campagna-rinnovi/invia` (`campagna-rinnovi.invia`).
- **File**: `app/Http/Controllers/Rinnovi/CampagnaRinnoviController.php`, `app/Services/Rinnovi/CampagnaRinnoviService.php`, vista `resources/views/rinnovi/campagna.blade.php`.
- **Come provarla**: sidebar → "Campagna rinnovi": lista dei soci attivi senza tessera/quota dell'anno, contatori, selezione e invio massivo email con link di pagamento personale (coda email per tenant), colonne stato inviata/pagata/rinnovata. Test: `tests/Feature/Rinnovi/CampagnaRinnoviTest.php`.

### f.3 — Promemoria rinnovo multi-step — ✅
- **Comando**: `php artisan rinnovi:promemoria` (schedulato daily 07:00, `withoutOverlapping`).
- **File**: `app/Console/Commands/RinnoviPromemoria.php`, `app/Services/Rinnovi/PromemoriaRinnoviService.php`, `app/Notifications/Rinnovi/PromemoriaRinnovoNotification.php`; step per tenant in `associazioni.promemoria_rinnovo_config` (json, default `[30, 15, 3]` da `Associazione::PROMEMORIA_RINNOVO_DEFAULT`); anti-duplicazione su tabella `promemoria_inviati` (unique socio+tessera+tipo+step).
- **Come provarla**: con una tessera attiva in scadenza entro 30 giorni, lanciare il comando: parte l'email al socio e viene tracciata la riga in `promemoria_inviati` (rilanci = nessun doppio invio). Test: `tests/Feature/Rinnovi/PromemoriaRinnoviTest.php`.

### f.4 — Stampa massiva tessere — ✅
- **Rotta**: `GET /tessere-stampa-massiva` (`tessere.stampa-massiva`, parametri di selezione/filtro in query string).
- **File**: `app/Http/Controllers/Rinnovi/StampaMassivaTessereController.php`, `app/Services/Rinnovi/StampaMassivaTessereService.php` (riusa `TesseraPdfRenderer` per pagina), vista `resources/views/rinnovi/stampa-massiva-pdf.blade.php`.
- **Come provarla**: pagina Tessere → selezione/filtro → "Stampa massiva": un unico PDF multi-tessera. Test: `tests/Feature/Rinnovi/StampaMassivaTessereTest.php`.

### f.5 — Apertura anno sociale — ✅
- **Rotte**: `GET /apertura-anno` (`apertura-anno.index`), `POST /apertura-anno` (`apertura-anno.genera`); comando equivalente `php artisan rinnovi:apri-anno` (manuale, non schedulato).
- **File**: `app/Http/Controllers/Rinnovi/AperturaAnnoController.php`, `app/Console/Commands/RinnoviApriAnno.php`, `app/Services/Rinnovi/AperturaAnnoService.php`, vista `resources/views/rinnovi/apertura-anno.blade.php`.
- **Come provarla**: sidebar → "Apertura anno": anteprima (n. soci attivi, importi da tipo socio/associazione) → genera in bulk le quote dell'anno nuovo per i soci attivi; idempotente, salta le esistenti. Test: `tests/Feature/Rinnovi/AperturaAnnoTest.php`.

### f.6 — Storico tesseramento socio — ✅
- **Rotte**: tab "Tesseramento" nella scheda socio (`soci.show`); export `GET /soci/{socio}/storico-tesseramento/export` (`soci.storico-tesseramento.export`, Excel via maatwebsite/excel).
- **File**: `app/Http/Controllers/Rinnovi/StoricoTesseramentoController.php`, `app/Services/Rinnovi/StoricoTesseramentoService.php`, partial in `resources/views/soci/show.blade.php` (tab `tesseramento`).
- **Come provarla**: scheda socio → tab "Tesseramento": timeline per anno (tessera, quota, pagamenti, presenze) + pulsante export Excel. Test: `tests/Feature/Rinnovi/StoricoTesseramentoTest.php`.

---

## B — Vita sociale & eventi

### f.7 — Calendario eventi + ICS — ✅
- **Rotte**: admin `GET /calendario-eventi` (`calendario-eventi.index`), `GET /eventi/{evento}/evento.ics` (`eventi.ics`); pubblico `GET /a/{slug}/calendario` (`pubblico.calendario`), feed `GET /a/{slug}/calendario.ics` (`pubblico.calendario.ics`, soli pubblicati, nessun token), `GET /a/{slug}/eventi/{evento}/evento.ics` (`pubblico.eventi.ics`).
- **File**: `app/Http/Controllers/EventiVita/CalendarioEventiController.php`, `CalendarioPubblicoController.php`, `app/Services/EventiVita/IcsService.php`, viste `resources/views/eventi-vita/calendario.blade.php` e `eventi-vita/pubblico/calendario.blade.php`.
- **Come provarla**: sidebar → "Calendario" (griglia mensile cliccabile); mini-sito `/a/associazione-demo/calendario`; download .ics dal dettaglio evento; il feed `calendario.ics` si abbona da Google/Apple Calendar. Test: `tests/Feature/EventiVita/CalendarioEventiTest.php`.

### f.8 — Eventi ricorrenti — ✅
- **Rotte**: `GET /eventi-ricorrenti/nuovo` (`eventi-ricorrenti.create`), `POST /eventi-ricorrenti` (`eventi-ricorrenti.store`), `GET|PUT|DELETE /eventi/{evento}/serie` (`eventi.serie.show|update|destroy`).
- **File**: `app/Http/Controllers/EventiVita/EventoRicorrenteController.php`, `SerieEventoController.php`, `app/Services/EventiVita/SerieEventiService.php`; occorrenze come eventi figli con `eventi.serie_id` uuid condiviso (max 52, settimanale/mensile fino a data N).
- **Come provarla**: Eventi → "Nuovo ricorrente": scegli ricorrenza e data fine → genera le occorrenze; dalla pagina serie modifichi/annulli la singola occorrenza o l'intera serie. Il DemoSeeder crea la serie "Serata giochi al circolo" (3 occorrenze settimanali). Test: `tests/Feature/EventiVita/EventiRicorrentiTest.php`.

### f.9 — Foglio presenze — ✅
- **Rotte**: `GET /eventi/{evento}/presenze` (`eventi.presenze.index`), `POST` (`eventi.presenze.store`, checkbox multipli in blocco), `GET /eventi/{evento}/foglio-presenze.pdf` (`eventi.presenze.foglio`).
- **File**: `app/Http/Controllers/EventiVita/PresenzeController.php`, viste `resources/views/eventi-vita/presenze.blade.php` e `eventi-vita/pdf/` (riga firma per prenotato/partecipante).
- **Come provarla**: dettaglio evento → "Presenze": PDF stampabile con righe firma; registrazione manuale in blocco affianca il check-in QR esistente. Test: `tests/Feature/EventiVita/PresenzeTest.php`.

### f.10 — Eventi riservati ai soci — ✅
- **Dove**: flag `eventi.solo_soci` (form evento + serie: `app/Http/Requests/Eventi/EventoRequest.php`, `EventiVita/SerieUpdateRequest.php`); enforcement in `app/Services/Prenotazioni/PrenotazioneService.php` (prenotabile solo da account collegati a socio Attivo e dai soci in area soci); badge "Riservato ai soci" e CTA spiegata in `resources/views/pubblico/eventi/_card.blade.php` e `pubblico/eventi/show.blade.php`.
- **Come provarla**: crea un evento con "Solo soci" attivo → nel catalogo pubblico appare il badge; un account non collegato a socio vede la CTA esplicativa e non può prenotare. Nel demo la serie ricorrente è solo-soci. Test: `tests/Feature/EventiVita/SoloSociTest.php`.

### f.11 — Feedback post-evento — ✅
- **Rotte/comando**: `php artisan eventi:invia-feedback` (schedulato daily 10:00) → email ai partecipanti con check-in dell'evento di ieri; form pubblico `GET /feedback-eventi/{token}` (`feedback-eventi.show`, link **firmato** + throttle) e `POST` (`feedback-eventi.store`); report admin `GET /eventi/{evento}/feedback` (`eventi.feedback.index`).
- **File**: `app/Console/Commands/EventiInviaFeedback.php`, `app/Services/EventiVita/FeedbackEventiService.php`, `app/Notifications/EventiVita/FeedbackEventoNotification.php`, `app/Http/Controllers/EventiVita/FeedbackPubblicoController.php`, `FeedbackEventoController.php`, tabella `feedback_eventi` (voto 1–5 + commento, un feedback per prenotazione), vista `resources/views/eventi-vita/feedback.blade.php`.
- **Come provarla**: evento con data ieri + prenotazione con check-in → lancia il comando → email con link firmato → compila il form; media e commenti nel dettaglio evento admin. Test: `tests/Feature/EventiVita/FeedbackEventiTest.php`.

### f.12 — Statistiche partecipazione socio — ✅
- **Dove**: tab "Vita sociale" nella scheda socio (`resources/views/eventi-vita/_vita-sociale-socio.blade.php`, incluso da `soci/show.blade.php`): eventi prenotati/partecipati, ultima presenza, tasso di partecipazione; top partecipanti in dashboard (`resources/views/eventi-vita/_top-partecipanti.blade.php`, incluso con `@includeIf` da `dashboard/index.blade.php`).
- **File**: `app/Services/EventiVita/VitaSocialeService.php`.
- **Come provarla**: scheda socio → tab "Vita sociale"; dashboard → card "Top partecipanti". Test: `tests/Feature/EventiVita/VitaSocialeSocioTest.php`.

---

## C — Registro & governance

### f.13 — Libro soci ufficiale — ✅
- **Rotte**: `GET /libro-soci` (`libro-soci.index`), `GET /libro-soci/pdf` (`libro-soci.pdf`, PDF intestato), `GET /libro-soci/excel` (`libro-soci.excel`).
- **File**: `app/Http/Controllers/Registro/LibroSociController.php`, `app/Services/Registro/LibroSociService.php`, viste `resources/views/registro/libro-soci/`.
- **Come provarla**: sidebar → Registro → "Libro soci": registro cronologico per anno con numero progressivo di iscrizione, dati anagrafici, data ammissione + delibera (f.14), data/motivo cessazione (f.15); export PDF/Excel. Read-only da snapshot reale (soci + storico). Test: `tests/Feature/Registro/LibroSociTest.php`.

### f.14 — Delibere di ammissione — ✅
- **Dove**: campi opzionali `delibera_numero`/`delibera_data` nella modale di approvazione richiesta iscrizione (`POST richieste-iscrizione/{richiesta}/approva`), salvati su `richieste_iscrizione` e nello storico socio; mostrati nel libro soci.
- **File**: `app/Http/Requests/Iscrizioni/ApprovaRichiestaIscrizioneRequest.php`, `app/Services/Iscrizioni/ApprovazioneIscrizioneService.php` (parametri `$deliberaNumero`/`$deliberaData`).
- **Come provarla**: Richieste iscrizione → Approva → compila n./data delibera → la riga appare nel libro soci con gli estremi della delibera. Test: coperto in `tests/Feature/Registro/LibroSociTest.php` e nei test iscrizioni.

### f.15 — Cessazioni formali — ✅
- **Rotte**: `GET /cessazioni` (`cessazioni.index`), `POST /cessazioni` (`cessazioni.store`); azione "Cessazione" anche dalla scheda socio.
- **File**: `app/Http/Controllers/Registro/CessazioneController.php`, `app/Services/Registro/CessazioneService.php` (transazione: riga in `cessazioni`, stato socio coerente via `Cessazione::STATO_SOCIO_PER_TIPO`, tessere attive → annullate, poi storico fail-safe), `app/Models/Cessazione.php`.
- **Come provarla**: scheda socio attivo → "Cessazione" (dimissioni/decadenza/espulsione/decesso) con data, motivo, delibera → stato socio aggiornato (Radiato/Deceduto), tessere annullate, riga nel libro soci. Il DemoSeeder include la cessazione storica di "Paolo Gallo". Test: `tests/Feature/Registro/CessazioniTest.php`.

### f.16 — Organi sociali — ✅
- **Rotte**: `GET|POST /organi-sociali` (`organi-sociali.index|store`), `PUT|DELETE /organi-sociali/{carica}` (`organi-sociali.update|destroy`).
- **File**: `app/Http/Controllers/Registro/CaricaController.php`, `app/Models/Carica.php` (scope `attive()`, `inScadenza()` a 90 giorni — `Carica::GIORNI_ALERT_SCADENZA`), viste `resources/views/registro/organi-sociali/`, alert mandati in scadenza in dashboard.
- **Come provarla**: sidebar → Registro → "Organi sociali": cariche attive e storiche (ruolo, socio, inizio/fine mandato, note); nel demo il mandato del Presidente scade tra 60 giorni e attiva l'alert in dashboard. Test: `tests/Feature/Registro/OrganiSocialiTest.php`.

### f.17 — Verbali potenziati — ✅
- **Dove**: modulo documenti-verbali-scadenze esteso — numerazione automatica per anno (`Verbale::prossimoNumero()`, accessor `numero_completo` es. "3/2026", unique a DB su associazione+anno+numero), collegamento opzionale a evento (`verbali.evento_id`) e riferimenti delibere nel campo `deliberazioni`, ricerca con `?search=` su titolo/presenti/ordine del giorno/deliberazioni.
- **File**: `app/Models/Verbale.php`, `app/Http/Controllers/DocumentiVerbaliScadenze/VerbaleController.php`, rotte `verbali.*` esistenti (`routes/modules/documenti-verbali-scadenze.php`).
- **Come provarla**: Verbali → nuovo verbale → numero proposto automaticamente per l'anno, select evento (assemblea); barra di ricerca full-text. Il demo include il verbale 1/{anno} collegato all'assemblea annuale. Test: in `tests/Feature/documenti_verbali_scadenze/` (estensioni del modulo).
- **Limitazione**: la "ricerca full-text" è `LIKE %…%` (portabile SQLite/MySQL), non un indice FULLTEXT.

---

## D — Email & conferme

### f.18 — Layout email brandizzato unico — ✅
- **File**: `resources/views/emails/layout.blade.php` (+ `layout-text.blade.php`), `app/Services/Email/EmailLayout.php` (`wrap()` con logo, nome e colore del tenant, fallback pulito senza logo), agganciato al canale mail via `app/Services/Email/TenantNotificationMailer.php`: TUTTE le notifiche di sistema escono con lo stesso layout.
- **Come provarla**: qualsiasi email di sistema (conferma prenotazione, promemoria rinnovo, ricevuta…) arriva nel layout del tenant; senza logo configurato il fallback resta pulito. Test: `tests/Feature/EmailConferme/LayoutEmailBrandizzatoTest.php`.

### f.19 — Conferme iscrizione complete — ✅
- **File**: `app/Notifications/Iscrizioni/ConfermaRichiestaInviataNotification.php` e `RichiestaIscrizioneRicevutaNotification.php` (email immediata "richiesta ricevuta" con riepilogo dei dati inviati), `app/Notifications/Quote/BenvenutoSocioNotification.php` (all'approvazione: "benvenuto socio" con PDF tessera allegato se generata + prossimi passi).
- **Come provarla**: compila `/a/associazione-demo/iscriviti` → arriva il riepilogo; approva la richiesta da admin (con "genera tessera") → arriva il benvenuto con la tessera in allegato. Test: `tests/Feature/EmailConferme/ConfermeIscrizioneTest.php`.

### f.20 — Centro anteprime email — ✅
- **Rotte**: `GET /email/anteprime` (`email-anteprime.index`), `GET /email/anteprime/{codice}` (`email-anteprime.show`, anteprima HTML su dati di esempio), `POST /email/anteprime/{codice}/prova` (`email-anteprime.prova`, "invia prova a me", throttle 10/min).
- **File**: `app/Http/Controllers/EmailConferme/AnteprimaEmailController.php`, `app/Services/Email/EmailAnteprimaService.php` (catalogo di ~23 notifiche: iscrizioni, quote/ricevute, prenotazioni, account, rinnovi, feedback, scadenze…), vista `resources/views/email-conferme/anteprime.blade.php`.
- **Come provarla**: sidebar → Email → "Anteprime email": elenco per categoria, click per il render su dati fittizi, bottone di invio prova al proprio indirizzo. Test: `tests/Feature/EmailConferme/AnteprimeEmailTest.php`.

### f.21 — Ricevute quote — ✅
- **Rotte**: admin `GET /quote/{quota}/ricevuta` (`quote.ricevuta`); area soci `GET /area-soci/quote/{quota}/ricevuta` (`area-soci.quote.ricevuta`); area account `GET /a/{slug}/account/quote/{quota}/ricevuta` (`pubblico.account.quote.ricevuta`).
- **File**: `app/Services/Pagamenti/RicevutaService.php` (emissione idempotente alla conferma pagamento, numero progressivo per tenant+anno sotto lock, unique a DB per quota), `app/Models/Ricevuta.php`, `app/Notifications/Quote/RicevutaQuotaNotification.php` (invio email con PDF), PDF `resources/views/email-conferme/ricevuta-pdf.blade.php`, `app/Http/Controllers/EmailConferme/RicevutaAreaRiservataController.php`.
- **Come provarla**: conferma il pagamento di una quota → ricevuta numerata "N/{anno}" emessa e inviata via email; scaricabile da admin (pagina Quote) e dalle aree riservate. Il demo include la ricevuta 1/{anno} sulla quota pagata di Anna Verdi. Test: `tests/Feature/EmailConferme/RicevuteQuoteTest.php`.

---

## E — Anagrafica & iscrizioni

### f.22 — Slug e link di invito — ✅
- **Rotte**: slug editabile nel form associazione (`associazioni.store|update`); QR `GET /configurazioni/invito-qr` (`configurazioni.invito-qr`, PNG inline o `?download=1`); box "Invita al tuo mini-sito" in `GET /configurazioni?section=invito`.
- **File**: `app/Http/Requests/Struttura/StoreAssociazioneRequest.php` / `UpdateAssociazioneRequest.php` (kebab-case + unique, vuoto = invariato), `app/Http/Controllers/AnagraficaPro/InvitoQrController.php` (riusa simplesoftwareio/qrcode), sezione `invito` in `resources/views/struttura/configurazioni/index.blade.php` (link copiabile verso `/a/{slug}/iscriviti` + anteprima URL).
- **Come provarla**: Associazioni → modifica → campo slug con anteprima `/a/{slug}`; Configurazioni → "Invita al mini-sito" → copia link / scarica QR. Test: `tests/Feature/AnagraficaPro/SlugInvitoTest.php`.

### f.23 — Documenti socio con scadenza — ✅
- **Rotte/comando**: campi `tipo_documento` + `data_scadenza` su `POST /documenti-socio` (`documenti-socio.store`, medialibrary esistente); promemoria `php artisan documenti:promemoria-scadenze` (schedulato daily 07:45, digest a -30 giorni ad admin e socio, dedup su `promemoria_inviati`).
- **File**: `app/Models/DocumentoSocio.php` (tipi suggeriti, `isScaduto()`/`inScadenza()`, preavviso 30 giorni), `app/Console/Commands/DocumentiPromemoriaScadenze.php`, `app/Notifications/AnagraficaPro/DocumentiInScadenzaNotification.php`; badge scaduto/in scadenza nella scheda socio (tab Documenti).
- **Come provarla**: scheda socio → Documenti → carica con tipo e scadenza; con scadenza entro 30 giorni il badge diventa "in scadenza" e il comando invia il digest. Test: `tests/Feature/AnagraficaPro/DocumentiScadenzaTest.php`.

### f.24 — Rileva duplicati — ✅
- **Rotte**: `GET /soci-duplicati` (`soci-duplicati.index`), `POST /soci-duplicati/unisci` (`soci-duplicati.unisci`).
- **File**: `app/Http/Controllers/AnagraficaPro/DuplicatiController.php`, `app/Services/AnagraficaPro/DuplicatiService.php` (gruppi per stessa email O stesso CF O nome+cognome+data nascita normalizzati), `MergeSociService.php` (merge assistito: scegli il principale, sposta tessere/quote/prenotazioni/documenti/storico, soft-delete del duplicato).
- **Come provarla**: sidebar → "Possibili duplicati": gruppi rilevati → "Unisci" → scegli il record principale → i dati collegati migrano e il duplicato finisce nel cestino. Test: `tests/Feature/AnagraficaPro/DuplicatiTest.php`.

### f.25 — Export/import pro — ✅
- **Rotte**: `GET /export-import` (`export-import.index`), `GET /export-import/export` (`export-import.export`, Excel multi-foglio: Soci, Tessere anno, Quote anno, Libro soci), `POST /export-import/import/anteprima` (`export-import.import.anteprima`, diff prima di applicare), `POST /export-import/import/applica` (`export-import.import.applica`).
- **File**: `app/Http/Controllers/AnagraficaPro/ExportImportController.php`, `app/Services/AnagraficaPro/ImportAggiornamentoService.php` (import di AGGIORNAMENTO: match per numero socio/email), viste `resources/views/anagrafica-pro/export-import/`.
- **Come provarla**: sidebar → "Export / Import": scarica l'Excel multi-foglio; ricarica un file modificato → anteprima diff campo per campo → applica. Test: `tests/Feature/AnagraficaPro/ExportImportTest.php`.

---

## Scheduler (routes/console.php)

| Comando | Orario | Funzionalità |
|---|---|---|
| `rinnovi:promemoria` | daily 07:00 | f.3 promemoria rinnovo multi-step |
| `documenti:promemoria-scadenze` | daily 07:45 | f.23 documenti socio in scadenza |
| `eventi:invia-feedback` | daily 10:00 | f.11 richieste feedback post-evento |

Tutti con `withoutOverlapping`; verificati con `php artisan schedule:list`. `rinnovi:apri-anno` (f.5) resta manuale by design (azione annuale con anteprima in pagina admin).

## Dati demo (DemoSeeder)

Oltre ai contenuti preesistenti: 2 cariche (Presidente con mandato in scadenza a 60 giorni → alert dashboard; Segretario in corso), ex socio "Paolo Gallo" con cessazione formale per dimissioni (tessera annullata + storico → visibile nel libro soci), verbale 1/{anno} collegato all'assemblea annuale, serie ricorrente "Serata giochi al circolo" (3 occorrenze settimanali, solo-soci), ricevuta 1/{anno} sulla quota pagata.

## Limitazioni note

- **f.3 / f.23**: i promemoria partono solo se lo scheduler di sistema gira (`php artisan schedule:run` da cron) e il queue worker è attivo (notifiche `ShouldQueue`).
- **f.7**: il feed `/a/{slug}/calendario.ics` espone i soli eventi pubblicati senza token: è volutamente pubblico (come il mini-sito); niente feed privato per-utente.
- **f.8**: la ricorrenza genera al massimo 52 occorrenze e supporta settimanale/mensile (niente RRULE complesse, es. "primo lunedì del mese").
- **f.11**: il feedback è raccolto solo dai partecipanti con check-in registrato; chi prenota ma non viene "checkato" non riceve il link.
- **f.17**: ricerca verbali con `LIKE` (portabile), non FULLTEXT; la numerazione automatica non rinumera i verbali storici privi di numero.
- **f.21**: la ricevuta è una ricevuta numerata di pagamento quota, non un documento fiscale (nessuna marca da bollo / numerazione fiscale).
- **f.24**: il rilevamento duplicati è euristico (email/CF/nome+cognome+nascita normalizzati): omonimi senza altri dati coincidenti non vengono proposti; il merge è irreversibile salvo ripristino del soft-delete.
- **f.25**: l'import aggiorna i soci esistenti (match numero socio/email) ma non crea soci nuovi: per i nuovi c'è il form di iscrizione o l'inserimento manuale.
- Generale: gli invii email reali dipendono dalla configurazione SMTP del tenant (fallback al mailer di default dell'app).
