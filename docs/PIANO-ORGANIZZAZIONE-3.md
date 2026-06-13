# Round 3 — 25 funzionalità per l'ORGANIZZAZIONE della vita associativa

Obiettivo: gli strumenti organizzativi che fanno funzionare un'associazione giorno
per giorno: volontari e turni, spazi e attrezzature, progetti e rendicontazione,
riunioni del direttivo e decisioni, relazioni esterne e crescita.
NON sovrapporsi ai round precedenti (PIANO-VITA-SOCIALE.md, PIANO-GESTIONE-2.md).
Regole architetturali identiche ai round precedenti (in fondo).

## A — Volontari & turni (1–6)
1. **Profilo volontario del socio**: disponibilità (giorni della settimana + fascia mattina/pomeriggio/sera, json su soci o tabella `disponibilita_soci`) e flag volontario_attivo; sezione nella scheda socio e modificabile dal socio in area riservata.
2. **Mansioni/competenze**: tassonomia per tenant (`mansioni`: nome, descrizione) assegnabile ai soci (pivot socio_mansioni); filtro "per mansione" nella lista soci; gestione in config.
3. **Turni**: tabella `turni` (titolo, data, ora inizio/fine, posti, mansione_id opzionale, evento_id opzionale, spazio_id opzionale, note, stato aperto/chiuso) + `turni_assegnazioni` (turno_id, socio_id, stato proposto/confermato/rinunciato, ore_effettive null, unique turno+socio); pagina admin pianificazione (lista + per evento) con assegnazione volontari (suggeriti per mansione e disponibilità).
4. **Auto-candidatura**: in area soci e area account (se collegati) elenco turni aperti compatibili → "Mi offro" (stato proposto, conferma admin; se turno con auto_conferma bool → confermato subito); rinuncia con motivo → slot riaperto + notifica admin.
5. **Registro ore volontariato**: al completamento del turno (azione admin "registra presenze" con ore effettive precompilate dalla durata) le ore finiscono nel registro; più inserimento manuale ore fuori turno; totale ore nella scheda socio e **attestato PDF** annuale ore volontariato (dompdf, intestato).
6. **Promemoria turno**: comando `turni:promemoria` (email il giorno prima ai confermati, tracking in promemoria_inviati tipo 'turno'); NON registrarlo in console.php (lo fa Rifiniture).

## B — Spazi & risorse (7–11)
7. **Spazi**: anagrafica `spazi` (nome, descrizione, capienza, sede_id opzionale, attivo); CRUD in configurazioni.
8. **Prenotazioni spazi interne**: `prenotazioni_spazi` (spazio_id, titolo/uso, dal, al, richiedente utente, note) con **rilevazione conflitti** (overlap stesso spazio → errore con indicazione della prenotazione esistente); vista calendario settimanale per spazio + lista.
9. **Inventario**: `attrezzature` (nome, descrizione, quantita, valore_unitario null, stato buono/da_riparare/fuori_uso, ubicazione/spazio_id opzionale); CRUD con ricerca.
10. **Prestiti attrezzature**: `prestiti_attrezzature` (attrezzatura_id, socio_id, quantita, dal, restituzione_prevista, restituito_at null, note) con disponibilità calcolata (quantita - prestiti attivi), blocco se non disponibile, azione "registra restituzione", promemoria ritardo (comando `prestiti:promemoria`, NON registrare); storico per socio nella scheda.
11. **Evento ↔ spazio**: campo `spazio_id` opzionale su eventi (select nel form) con controllo conflitto contro prenotazioni_spazi e altri eventi nello stesso spazio/orario (warning non bloccante con dettaglio).

## C — Progetti & rendicontazione (12–16)
12. **Progetti**: `progetti` (nome, descrizione, dal, al, responsabile utente_id, stato bozza/attivo/concluso, budget decimal null); CRUD + pagina dettaglio.
13. **Prima nota → progetti**: `progetto_id` opzionale su movimenti (select nel form movimento e nei filtri); rendiconto entrate/uscite per progetto nel dettaglio.
14. **Eventi e turni → progetti**: `progetto_id` opzionale su eventi e turni; il dettaglio progetto elenca eventi e turni collegati.
15. **Report progetto PDF**: dal dettaglio, export PDF intestato: anagrafica progetto, rendiconto economico, eventi svolti con partecipanti, ore volontariato dai turni collegati.
16. **Milestone**: `milestone_progetto` (titolo, scadenza, completata_at null, ordine); checklist nel dettaglio con barra avanzamento (% completate), evidenza scadute.

## D — Riunioni & decisioni (17–20)
17. **Riunioni direttivo**: `riunioni` (titolo, data/ora, luogo/spazio_id, odg json [{punto, descrizione}], stato pianificata/svolta/annullata, verbale_id null) + `riunioni_partecipanti` (riunione_id, utente o socio con carica → usa cariche attive del round 1: invito agli intestatari delle cariche, stato invitato/confermato/assente); pagina admin con convocazione email ai membri dell'organo (notifica brandizzata).
18. **Votazioni sui punti OdG**: per riunione svolta, registrazione esito per punto (`votazioni_riunione`: punto_indice, esito approvato/respinto/rinviato, favorevoli, contrari, astenuti, note); al termine bottone **"Genera bozza verbale"** → crea Verbale (modulo esistente, numerazione automatica) precompilato con data, presenti, punti e esiti.
19. **Archivio decisioni**: pagina "Decisioni" che aggrega le votazioni approvate (con riunione, data, punto, esito numerico) + le delibere di ammissione del round 1, ricercabile per testo/anno; link al verbale collegato.
20. **Adempimenti ricorrenti**: `adempimenti` (titolo, descrizione, mese_scadenza 1-12, attivo) per tenant con seed iniziale suggerito (assicurazione volontari, bilancio annuale, rinnovo cariche...); checklist per anno (`adempimenti_anni`: adempimento_id, anno, completato_at, utente) nella pagina Scadenzario esistente (nuova sezione) + promemoria mensile (comando `adempimenti:promemoria`, NON registrare).

## E — Relazioni & crescita (21–25)
21. **Contatti esterni (mini-CRM)**: `contatti_esterni` (tipo fornitore/ente/sponsor/stampa/altro, ragione sociale o nome, referente, email, telefono, note, tag json); CRUD con ricerca e filtro tipo.
22. **Sponsor & partner**: `sponsor` (contatto_esterno_id opzionale, nome, logo_url, livello oro/argento/bronzo/partner, dal, al, visibile_sul_sito bool, sito_url); gestione admin + sezione "I nostri sostenitori" su home pubblica del mini-sito (solo visibili e nel periodo).
23. **Porta un amico (referral)**: codice referral personale del socio (slug univoco, visibile in area soci/account con link di invito a /a/{slug}/iscriviti?ref=CODICE); il form iscrizione salva il referral sulla richiesta; classifica "ambasciatori" admin (iscrizioni approvate portate) + contatore nella scheda socio.
24. **Galleria foto eventi**: upload immagini sull'evento (medialibrary, collection 'galleria', max 20, conversioni thumb); tab admin + carosello/griglia sulla pagina pubblica dell'evento se `galleria_pubblica` bool attivo (anche per eventi passati: "com'è andata").
25. **Anno in cifre**: pagina admin "Statistiche annuali" con confronto anno corrente vs precedente: nuovi soci, cessazioni, retention %, tessere emesse, partecipazioni eventi, ore volontariato, entrate/uscite, donazioni; grafici Chart.js + **export PDF per l'assemblea**.

## Regole operative (VINCOLANTI, identiche ai round precedenti)
- ⚠️ MAI migrate:fresh/db:wipe su associazione_soci_laravel. PHPUnit = SQLite. MySQL solo associazione_soci_e2e con E2E_FIXTURES=1. MAI scrivere il .env reale.
- Migrations additive SQLite-ok, TUTTE create da Fondamenta insieme a estensioni model, factories e voci sidebar ($nav tollerante). I moduli non toccano Model condivisi, console.php, DemoSeeder, sidebar, file altrui. Scheduler e seeder li completa Rifiniture.
- Form Request + Policy (famiglie permessi esistenti: eventi.* per turni/spazi, struttura.* per progetti/riunioni/contatti, soci.* per volontari). Viste sul design system. Notifiche ShouldQueue brandizzate. Niente pacchetti nuovi (dompdf, medialibrary, Chart.js, excel già presenti).
- Ogni modulo: feature test completi a verde. Suite esistenti (1472 PHPUnit, 93 E2E) DEVONO restare verdi.
