# 25 funzionalità per la gestione della vita sociale delle associazioni

Obiettivo: portare l'app da gestionale a piattaforma completa per la vita
di un'associazione: tesseramento e rinnovi, eventi e partecipazione,
registro ufficiale e governance, email/conferme di qualità, anagrafica avanzata.
Tutto coerente con l'esistente (multi-tenant, design system, librerie già installate).

## A — Rinnovi & tesseramento (1–6)
1. **Rinnovo online self-service**: in area soci e area account (se collegati a un socio) pulsante "Rinnova per il {anno}": crea la Quota dell'anno (se mancante) via `PaymentService::createQuotaForSocio` e porta al pagamento col `payment_token` esistente; al pagamento la tessera dell'anno viene emessa/rinnovata (hook già esistente in onPaymentCompleted, verificare/estendere).
2. **Campagna rinnovi**: pagina admin "Campagna rinnovi {anno}": soci attivi senza tessera/quota dell'anno, selezione e invio massivo email con link di pagamento personale (coda email per tenant), colonne stato (inviata / pagata / rinnovata), contatori.
3. **Promemoria rinnovo multi-step**: scheduler che invia a -30/-15/-3 giorni dalla scadenza tessera (configurabili per tenant in `associazioni.promemoria_rinnovo_config` json, default [30,15,3]), con tracking in tabella `promemoria_inviati` (socio, tessera, step, inviato_at) per non duplicare.
4. **Stampa massiva tessere**: da tessere admin, selezione/filtro → un unico PDF multi-tessera (riusa TesseraPdfRenderer per pagina) per la stampa in blocco.
5. **Apertura anno sociale**: comando + pagina admin "Apri anno {anno+1}": anteprima (n. soci attivi, importi da tipo socio/associazione) → genera in bulk le quote del nuovo anno per i soci attivi (idempotente, salta esistenti).
6. **Storico tesseramento socio**: tab nella scheda socio con timeline per anno (tessera, quota, pagamenti, presenze) + export Excel del singolo socio.

## B — Vita sociale & eventi (7–12)
7. **Calendario eventi**: vista calendario mensile in admin (griglia, eventi cliccabili) e nel mini-sito pubblico; **export ICS** per singolo evento (download .ics con dati evento) e feed `/a/{slug}/calendario.ics` degli eventi pubblicati (token non necessario, soli pubblicati).
8. **Eventi ricorrenti**: alla creazione, opzione ricorrenza (settimanale/mensile, fino a data N, max 52): genera le occorrenze come eventi figli (`serie_id` uuid condiviso), modifica/annulla singola occorrenza o intera serie.
9. **Foglio presenze**: per evento, PDF stampabile dei prenotati/partecipanti con riga firma; registrazione presenze manuale in blocco (checkbox multipli) oltre al check-in QR.
10. **Eventi riservati ai soci**: flag `solo_soci` su evento: prenotabile solo da account collegati a socio Attivo (e dai soci in area soci); badge "Riservato ai soci" nel catalogo, CTA spiegata per i non soci.
11. **Feedback post-evento**: il giorno dopo `data_evento` (scheduler) email ai partecipanti con check-in: link firmato a form pubblico di valutazione (voto 1–5 + commento, tabella `feedback_eventi`, un feedback per prenotazione); media e commenti nel dettaglio evento admin.
12. **Statistiche partecipazione socio**: nella scheda socio tab "Vita sociale": eventi prenotati/partecipati (check-in), ultima presenza, tasso di partecipazione; top partecipanti nella dashboard.

## C — Registro & governance (13–17)
13. **Libro soci ufficiale**: pagina admin "Libro soci": registro cronologico per anno con numero progressivo di iscrizione, dati anagrafici, data ammissione (e delibera, v. 14), data/motivo cessazione; export PDF intestato e Excel. Vista read-only ordinata, da snapshot reale (soci + storico).
14. **Delibere di ammissione**: all'approvazione di una richiesta di iscrizione campi opzionali `delibera_numero`/`delibera_data` (salvati sulla richiesta e nello storico socio), mostrati nel libro soci.
15. **Cessazioni formali**: azione "Cessazione" sul socio (dimissioni/decadenza/espulsione/decesso) con data, motivo, delibera: aggiorna stato socio coerente, scrive `cessazioni` (tabella) + storico + libro soci; tessere attive → annullate.
16. **Organi sociali**: gestione cariche per tenant (`cariche` tabella: ruolo es. Presidente/Segretario/Consigliere, socio_id, inizio/fine mandato, note); pagina admin con cariche attive e storiche, alert mandati in scadenza (90 giorni) in dashboard.
17. **Verbali potenziati**: numerazione automatica per anno (es. 3/2026), collegamento opzionale a un evento (assemblea) e alle delibere (campo riferimenti), ricerca full-text sul contenuto.

## D — Email & conferme (18–21)
18. **Layout email brandizzato unico**: template Blade markdown/HTML per-tenant (logo, nome, colore) usato da TUTTE le notifiche del sistema (estendere il canale mail esistente con vista condivisa `emails.layout`); fallback pulito senza logo.
19. **Conferme iscrizione complete**: email immediata di "richiesta ricevuta" all'ospite/account con riepilogo dei dati inviati; alla approvazione email "benvenuto socio" con allegato PDF tessera (se generata) e i prossimi passi.
20. **Centro anteprime email**: pagina admin che elenca tutte le notifiche di sistema (rinnovi, iscrizioni, prenotazioni, feedback...) con anteprima HTML renderizzata su dati di esempio e bottone "invia prova a me".
21. **Ricevute quote**: alla conferma pagamento quota, ricevuta PDF numerata per anno (`ricevute` tabella: numero progressivo, importo, data, socio) inviata via email e scaricabile da admin + area soci/account.

## E — Anagrafica & iscrizioni (22–25)
22. **Slug e link di invito**: campo slug EDITABILE nel form associazione (validazione unique, kebab-case, anteprima URL `/a/{slug}`); box "Invita al tuo mini-sito" in configurazioni con link copiabile e QR scaricabile (PNG) che punta a `/a/{slug}/iscriviti`.
23. **Documenti socio con scadenza**: sui documenti socio (medialibrary esistente) campo `scadenza` opzionale + tipo (certificato medico, documento identità...); promemoria scheduler a -30 giorni a admin (e socio se email), badge scaduto/in scadenza nella scheda socio.
24. **Rileva duplicati**: pagina admin "Possibili duplicati": gruppi di soci con stessa email O stesso CF O (nome+cognome+data nascita) normalizzati; azione "Unisci" assistita (scegli il principale, sposta tessere/quote/prenotazioni/documenti/storico, soft-delete del duplicato).
25. **Export/import pro**: export Excel multi-foglio (Soci, Tessere anno, Quote anno, Libro soci) da pagina dedicata; import Excel di AGGIORNAMENTO soci (match per numero socio/email) con anteprima diff prima di applicare.

## Regole architetturali per gli agenti
- ⚠️ MAI migrate:fresh/db:wipe su `associazione_soci_laravel`. PHPUnit = SQLite. MySQL solo `associazione_soci_e2e` con E2E_FIXTURES=1.
- Migrations ADDITIVE SQLite-compatibili, create TUTTE dalla fase Fondamenta (i moduli non creano migrations né toccano i Model condivisi: campi/relazioni/costanti li prepara Fondamenta).
- I moduli NON modificano: routes/console.php, DemoSeeder, sidebar (le voci menu le aggiunge Fondamenta con `$nav()` che tollera rotte mancanti), né file di altri moduli. Scheduler e seeder li completa la fase Rifiniture.
- Rotte per modulo in routes/modules/ (file nuovi). Form Request + Policy (permessi spatie esistenti per area: tessere.*, eventi.*, soci.*, struttura.*). Viste sul design system. Notifiche ShouldQueue database+mail.
- Ogni modulo scrive feature test PHPUnit completi dei propri flussi e li esegue fino a verde. Suite esistenti (954 PHPUnit, 52 E2E) DEVONO restare verdi.
- Pacchetti: SOLO quelli già installati (dompdf, qrcode, excel, medialibrary, purifier, ecc.).
