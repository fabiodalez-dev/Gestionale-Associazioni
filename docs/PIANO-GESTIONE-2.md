# Round 2 — 25 funzionalità per migliorare e SEMPLIFICARE la gestione associativa

Obiettivo: ridurre i click e il lavoro manuale dell'admin, dare trasparenza economica,
aumentare il coinvolgimento dei soci. NON sovrapporsi alle 25 del round 1
(docs/PIANO-VITA-SOCIALE.md). Stesse regole architetturali del round 1.

## A — Operatività quotidiana (1–6)
1. **Ricerca globale (⌘K)**: command palette nell'admin (modale con input, attivata da ⌘K/Ctrl+K e icona in navbar/sidebar): cerca in soci (nome/email/numero/CF), eventi, prenotazioni (codice), documenti, pagine admin; risultati raggruppati, navigazione tastiera; endpoint JSON throttled che riusa Scout per i soci e LIKE per il resto.
2. **Azioni in blocco sui soci**: checkbox multiple nella lista soci + barra azioni (cambia stato/tipo/categoria/sede, assegna/rimuovi tag, esporta selezione); conferma con riepilogo; audit nello storico di ogni socio.
3. **Note interne sul socio**: tabella `note_socio` (autore admin, testo, pin opzionale); tab "Note" nella scheda con timeline, distinte dallo storico automatico; solo admin del tenant.
4. **Attività/promemoria admin**: tabella `attivita_admin` (titolo, descrizione, scadenza, assegnatario utente, collegamento polimorfico opzionale socio/evento, stato aperta/completata); pagina elenco + widget "Le mie attività" in dashboard; promemoria email il giorno della scadenza (comando schedulato).
5. **Viste salvate ovunque**: estendere il pattern SavedFilter (scope esistente 'soci') a tessere, quote e prenotazioni evento: preset per pagina con salva/applica/elimina, condivisibili per tenant.
6. **Registro modifiche (UI per activitylog)**: pagina admin "Registro modifiche" sul log spatie già attivo: filtri per modello/utente/periodo/azione, diff leggibile dei campi, paginazione; permesso struttura.view.

## B — Comunicazione & coinvolgimento (7–12)
7. **Bacheca annunci**: tabella `annunci` (titolo, corpo_html purificato, pubblicato_dal/al, in_evidenza); CRUD admin + sezione "Novità" su home pubblica /a/{slug} e pagina elenco; visibilità opzionale solo_soci (area riservata).
8. **Newsletter da annunci**: composizione newsletter scegliendo annunci pubblicati (anteprima col layout brandizzato) e invio ai soci attivi con email (rispetta opt-out) via coda per tenant; storico invii.
9. **Compleanni**: widget dashboard "Compleanni del mese"; toggle per tenant `auguri_automatici` + template email; comando giornaliero che invia gli auguri ai soci attivi nel giorno del compleanno (tracking in promemoria_inviati con tipo 'compleanno').
10. **Contatti rapidi**: nella scheda socio e nelle liste, bottoni click-to-chat/chiama (wa.me con prefisso internazionale dal telefono, mailto, tel:) — pura UI, zero API.
11. **Sondaggi ai soci**: tabelle `sondaggi` (domanda, opzioni json, aperto_dal/al, solo_soci) e `risposte_sondaggio` (unique sondaggio+votante polimorfico socio/account); CRUD admin con risultati aggregati (barre %); voto da area soci e area account.
12. **Messaggio diretto al socio**: dall'admin, modale "Invia email" sulla scheda socio (oggetto+corpo, layout brandizzato, rispetta opt-out), inviata via coda tenant e tracciata nello storico attività.

## C — Economia & trasparenza (13–18)
13. **Prima nota**: tabella `movimenti` (tipo entrata/uscita, importo, data, categoria FK `categorie_movimento` seedate per tenant, descrizione, collegamento polimorfico opzionale a quota/prenotazione/donazione, metodo); CRUD admin con saldo progressivo; le quote pagate e le prenotazioni incassate generano il movimento AUTOMATICAMENTE (hook post-pagamento, idempotente).
14. **Report economico**: pagina con periodo selezionabile: entrate/uscite per categoria, andamento mensile (Chart.js già vendorizzato), saldo; export Excel.
15. **Donazioni online**: pagina pubblica /a/{slug}/dona (importo libero con minimi, donatore con email, anche anonimo opzionale); pagamento Stripe/PayPal riusando l'interfaccia Pagabile (tabella `donazioni`); ricevuta email + movimento automatico in prima nota; elenco donazioni admin.
16. **Solleciti quote**: dalla lista quote non pagate, bottone sollecito singolo e bulk (email col link pagamento, layout brandizzato); tracking in promemoria_inviati (tipo 'sollecito_quota') con conteggio solleciti per quota e data ultimo invio in lista.
17. **Budget evento**: campi su eventi `budget` decimal null + tabella `spese_evento` (descrizione, importo, data); nel dettaglio evento admin sezione economia: spese vs incassi prenotazioni (pagate) e margine.
18. **Scadenzario unificato**: pagina "Scadenzario" che aggrega in un'unica lista/calendario: quote in scadenza, documenti socio in scadenza, mandati cariche in scadenza, scadenze custom esistenti; filtri per tipo, range; nessuna tabella nuova (vista aggregata).

## D — Membri & self-service (19–22)
19. **Richieste modifica dati**: il socio (area soci) o l'account collegato propone modifiche anagrafiche (tabella `richieste_modifica_dati`: campi proposti json, stato); l'admin vede il diff e approva/rifiuta (applica al socio + storico); badge in sidebar.
20. **Nuclei familiari**: tabella `nuclei` + `socio_id` capofamiglia e relazione `nucleo_id` su soci; gestione nella scheda socio (crea nucleo / aggiungi membro), vista raggruppata nella lista soci (filtro "per nucleo") e indicazione nei dettagli.
21. **Check-in kiosk**: rotta admin /checkin-kiosk/{evento} fullscreen (layout dedicato senza sidebar, ScannerSecurityHeaders): scanner html5-qrcode continuo con esito grande verde/rosso e auto-reset 3s, contatore presenze live; pensata per totem all'ingresso.
22. **Soci minorenni**: se data_nascita → età <18 al momento dell'iscrizione: campi tutore (nome, telefono, email) obbligatori nel form pubblico e in admin (validazione dinamica), badge "Minorenne" nelle liste, colonna tutore nell'export; tabella: campi `tutore_*` su soci.

## E — Piattaforma & qualità (23–25)
23. **Onboarding admin**: checklist per tenant calcolata dallo stato reale (logo caricato, slug ok, SMTP configurato, costo tessera, form iscrizione configurato, primo evento pubblicato, primo socio): card in dashboard con progress e link diretti; sparisce al 100% (dismissibile).
24. **Cestino unificato**: pagina "Cestino" che elenca i soft-deleted di soci/eventi/documenti/verbali con ripristino ed eliminazione definitiva (permessi per modulo), filtro per tipo; svuotamento automatico oltre 90 giorni (comando schedulato).
25. **Audit accessi**: tabella `accessi` (guard, utente/socio/account polimorfico, email tentata, ip, user_agent, esito, created_at) popolata via listener sugli eventi Login/Failed/Logout di Laravel per TUTTE le guard; pagina admin consultabile con filtri; alert email agli admin dopo 5 tentativi falliti in 10 minuti sulla stessa email (throttled).

## Regole operative (identiche al round 1 — VINCOLANTI)
- ⚠️ MAI migrate:fresh/db:wipe su associazione_soci_laravel. PHPUnit = SQLite. MySQL solo associazione_soci_e2e con E2E_FIXTURES=1. MAI scrivere il .env reale (i test installer usano INSTALLER_ENV_FILE).
- Migrations additive SQLite-ok, TUTTE create da Fondamenta insieme alle estensioni model e alle voci sidebar ($nav tollerante). I moduli non toccano Model condivisi, console.php, DemoSeeder, sidebar, file altrui. Scheduler e seeder li completa Rifiniture.
- Form Request + Policy (permessi spatie esistenti per area; per la prima nota usare quote.* come famiglia economica). Viste sul design system. Notifiche ShouldQueue brandizzate (layout f.18 round 1). Niente pacchetti nuovi (Chart.js è già in assets/vendor).
- Ogni modulo: feature test completi eseguiti a verde. Suite esistenti (1201 PHPUnit, 73 E2E) DEVONO restare verdi.
