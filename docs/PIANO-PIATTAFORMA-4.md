# Piano Piattaforma 4 — PHP 8.5, Formello, ruoli e pacchetti

Piano del quarto round di lavoro. Parte da quanto già fatto il 2026-10-22 (recupero password admin con Fortify, `PasswordPolicy` con zxcvbn, pagina Impostazioni di sistema: 1732 test verdi) e copre tutto ciò che è stato proposto nell'analisi dei pacchetti.

Ogni fase si chiude solo con: suite PHPUnit verde, PHPStan livello 5 pulito, Pint, smoke test nel browser e, dove la fase tocca flussi coperti, la suite E2E Playwright. Le fasi sono in ordine di dipendenza: le prime cambiano fondamenta su cui poggiano le successive.

## Fase 0 — PHP 8.5: Laravel Excel 4

**Problema.** L'unico pacchetto che impedisce PHP 8.5 è `phpoffice/phpspreadsheet` 1.x (`php <8.5.0`), richiesto da `maatwebsite/excel` 3.1. Oggi l'app gira solo con PHP 8.4.

**Scelta.** Aggiornare a `maatwebsite/excel` 4.x (rilasciata il 2026-10-21, richiede phpspreadsheet ^5.9 che supporta PHP 8.5). Il dry-run di Composer su PHP 8.5 si risolve. È preferibile a riscrivere tutto con `rap2hpoutre/fast-excel`: le 12 classi Import/Export usano concern standard (`FromQuery`, `WithHeadings`, `WithMapping`, `WithMultipleSheets`, `ToCollection`, `WithHeadingRow`…) che restano in Laravel Excel, mentre fast-excel non ha fogli multipli con titoli, autosize né heading row con la stessa API.

**Passi.**
1. `composer require maatwebsite/excel:^4.0 -W` con PHP 8.5, leggendo prima le note di upgrade della 4.0.
2. Adattare le 12 classi in `app/Imports` e `app/Exports` e i 2 punti che usano direttamente PhpSpreadsheet (`SociImport`, `ImportAggiornamentoService`).
3. Test di export/import esistenti + nuovo test di round-trip (export → import dello stesso file).
4. `config.platform.php` in composer.json a 8.5, aggiornare README e requisiti dell'installer.

**Piano B.** Se la 4.x introduce regressioni non risolvibili: fast-excel (openspout 4.32, PHP 8.5 ok) per gli export semplici e import, mantenendo gli export multi-foglio come generazione diretta con openspout.

## Fase 1 — Ruoli dell'associazione con spatie/laravel-permission

**Decisione (2026-10-22).** Valutato `shipfastlabs/grant` (v0.0.1, nessuna release, un solo maintainer): si resta su `spatie/laravel-permission`, già integrato e maturo. In fase di implementazione si è scelto di **non attivare i team** di spatie: ogni utente staff appartiene a una sola associazione, quindi i team non aggiungono nulla, mentre richiederebbero un "team corrente" impostato in ogni contesto (richieste, job in coda, comandi, API) con il rischio di negare permessi dove manca. I ruoli sono globali e il confine tra associazioni resta garantito dal tenant.

**Design.**
- Matrice ruoli × permessi in `App\Support\RuoliAssociazione`, unica fonte per seeder, pagina e test. 18 moduli × 4 azioni = 72 permessi.
- Nuovi moduli per le aree dei round 2-3, prima autorizzate con permessi di altre famiglie: `economia` (prima nota, donazioni), `iscrizioni`, `volontari`, `spazi`, `progetti`, `riunioni` (con adempimenti), `relazioni` (contatti, sponsor), `comunicazione` (annunci, newsletter, sondaggi).
- Cariche: `presidente`, `vicepresidente`, `segretario`, `tesoriere`, `consigliere`, `volontario`, oltre a `operatore`. Si assegnano agli utenti con ruolo di sistema operatore; i permessi di più cariche si sommano.
- L'operatore conserva esattamente i permessi precedenti; sui nuovi moduli eredita quelli della famiglia che li autorizzava prima.
- Il listener di login non sovrascrive più le cariche e rimuove i ruoli di sistema non più validi (admin declassato a operatore).
- Pagina **Ruoli e permessi** per admin dell'associazione e super_admin: assegnazione delle cariche agli operatori della propria associazione e matrice in sola lettura.
- Comando `app:aggiorna-permessi` (idempotente), eseguito anche dall'aggiornamento in-app dopo le migration.

## Fase 2 — Formello sui form di iscrizione

**Ambito.** Form generati da classi `App\Forms\*` (Formello v2, Bootstrap 5):
- pre-iscrizione socio pubblica (`struttura/preiscrizione`);
- registrazione account pubblico (`pubblico/auth/registrazione`);
- creazione e modifica amministratore, accettazione invito;
- creazione e modifica associazione (con admin);
- passo "amministratore" dell'installer;
- profilo/cambio password dove ha senso.

**Fuori ambito (motivato).** Il form `/a/{slug}/iscriviti` ha uno schema diverso per ogni associazione (`FormIscrizioneService`, campi configurabili): Formello lavora per classe e modello, quindi lì costruisco i `FormelloField` dinamicamente dallo schema del tenant solo se il risultato resta più semplice dell'attuale; altrimenti resta com'è.

**Design.**
- Widget custom `PasswordWidget` con indicatore di robustezza lato client (zxcvbn-ts servito in locale da `public/assets/vendor`, compatibile con la CSP) che rispecchia `PasswordPolicy`; la validazione vera resta sul server.
- Widget per codice fiscale (maschera + validazione `robertogallea/laravel-codicefiscale`) e telefono (`laravel-phone`).
- Le FormRequest esistenti restano la fonte delle regole: i form Formello non duplicano la validazione.
- Asset di Formello pubblicati con `vendor:publish --tag=formello-assets` e librerie già presenti (Bootstrap) disabilitate nella config.

**Passi.** Un form alla volta, con i test HTTP esistenti come rete di sicurezza e verifica visiva in chiaro/scuro e da mobile.

## Fase 3 — Impostazioni per associazione con spatie/laravel-settings

**Stato.** Pacchetto installato, migrazione presente, nessuna classe Settings. Le impostazioni di ogni associazione sono colonne sparse sulla tabella `associazioni` e in tabelle dedicate.

**Design.** spatie/laravel-settings è globale per natura (un gruppo = una riga per proprietà). Per il multi-tenant: colonna `associazione_id` sulla tabella `settings` e un `TenantSettingsRepository` che filtra per il tenant corrente; le classi (`AnagraficaSettings`, `TesseramentoSettings`, `PagamentiSettings`, `ComunicazioniSettings`, `AspettoSettings`) dichiarano il repository tenant. I valori di piattaforma restano nel `.env` (pagina Impostazioni di sistema).

**Passi.** Mappare le impostazioni oggi sulle colonne di `associazioni`, creare le classi con migrazione dei valori esistenti, sostituire le letture nel codice, aggiornare la pagina Impostazioni dell'associazione. Le colonne vecchie si rimuovono in una release successiva, dopo un periodo di doppia lettura.

## Fase 4 — Esportazione dati personali (GDPR)

`spatie/laravel-personal-data-export` per **soci** (area soci) e **account pubblici** (area riservata eventi).
- Pulsante "Scarica i miei dati": job in coda che crea uno ZIP con JSON e PDF leggibile (anagrafica, consensi, tessere, quote e ricevute, prenotazioni, iscrizioni, documenti caricati, attività), email con link firmato, scadenza e cancellazione automatica dopo 7 giorni.
- Richiesta registrata nel registro attività; throttle a una richiesta ogni 24 ore.
- Lato admin: possibilità di generare l'export per conto del socio (richieste via email o di persona).

## Fase 5 — Monitoraggio dei job falliti

`spatie/laravel-failed-job-monitor`: notifica email (indirizzo configurabile da Impostazioni di sistema, default `BACKUP_NOTIFICATION_MAIL`) quando un job fallisce, con throttling per non inondare la casella. Pagina **Code e job falliti** per il super_admin: elenco, dettaglio eccezione, riprova, elimina. Controllo aggiuntivo in laravel-health sul numero di job falliti nelle ultime 24 ore.

## Fase 6 — Medialibrary estesa

Oggi medialibrary è usata da `Documento`, `DocumentoSocio` ed `Evento` (galleria); gli upload manuali restano per il logo dell'associazione (`LogoStorageService`, `AssociazioneController`, `ConfigurazioniController`) e per `Soci/DocumentoSocioController`.
- Logo e immagine di copertina dell'associazione come collezioni media con conversioni (miniature, versione per PDF e email).
- Foto del socio (tessera) con conversione quadrata, e collezione documenti unificata.
- Allegati a verbali, riunioni e progetti (rendiconti).
- Comando di migrazione dei file esistenti verso medialibrary, idempotente, con `--dry-run`.
- Disco privato per i documenti, URL temporanei firmati per il download.

## Fase 7 — Sincronizzazione Google Calendar

`spatie/laravel-google-calendar` con credenziali **per associazione** (service account JSON cifrato + ID calendario, nelle impostazioni dell'associazione), non globali.
- Observer sugli eventi (crea/aggiorna/annulla) che accoda un job di sincronizzazione; `google_event_id` salvato sull'evento.
- Opzionale per tenant e disattivato di default; l'export ICS esistente resta.
- Test con il client Google mockato; prova reale su un calendario di test.

## Ordine e stima

| Fase | Contenuto | Rischio |
|---|---|---|
| 0 | Laravel Excel 4 e PHP 8.5 | medio (major upgrade) |
| 1 | Ruoli dell'associazione (spatie) | medio (tocca l'autorizzazione) |
| 2 | Formello sui form di iscrizione | basso |
| 3 | Settings per associazione | medio |
| 4 | Export GDPR | basso |
| 5 | Job falliti | basso |
| 6 | Medialibrary estesa | medio (migrazione file) |
| 7 | Google Calendar | medio (API esterna) |

Ogni fase produce un report `docs/REPORT-PIATTAFORMA-4.md` aggiornato con esito dei test e decisioni prese.

## Fase 8 — Perimetro per sede (aggiunta)

Associazione e sede restano livelli distinti; il ruolo dice cosa un utente può fare, le sedi assegnate su quali dati. Tabella `utente_sedi` (nessuna sede = tutta l'associazione), ruolo "Responsabile di sede", scope automatico per sede sui modelli con `sede_id` e su quelli collegati tramite socio o evento, `sede_id` facoltativo su eventi e prima nota (vuoto = di tutta l'associazione, visibile ma non modificabile dagli utenti limitati). Esclusi per scelta: spatie/laravel-multitenancy (il tenant è già gestito), pivot socio–sedi multiple e utente in più associazioni (finché non servono davvero), Horizon (richiede Redis).
