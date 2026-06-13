# Report — Organizzazione 3: volontari/turni, spazi/risorse, progetti, riunioni/decisioni, relazioni

Implementazione completa del piano `docs/PIANO-ORGANIZZAZIONE-3.md` (workflow multi-agente: fondamenta → 5 moduli paralleli → finisher: scheduler, seeder demo, verifiche incrociate). Le 25 funzionalità sono implementate e coperte da feature test PHPUnit dedicati; le suite preesistenti (PHPUnit + E2E) restano verdi.

## Architettura della consegna

- **Fondamenta**: migration unica additiva `database/migrations/2026_06_13_100000_add_organizzazione_foundation.php` (SQLite-compatibile) con tutte le tabelle/colonne nuove (`mansioni`, `socio_mansioni`, `turni`, `turni_assegnazioni`, registro ore volontariato, `spazi`, `prenotazioni_spazi`, `attrezzature`, `prestiti_attrezzature`, `progetti`, `milestone_progetto`, `riunioni`, `riunioni_partecipanti`, `votazioni_riunione`, `adempimenti`, `adempimenti_anni`, `contatti_esterni`, `sponsor` + colonne disponibilità/volontario su `soci`, `progetto_id`/`spazio_id` su `eventi`/`turni`/`movimenti`, slug referral su `soci`), i Model condivisi, le factory e le voci sidebar con helper `$nav()` tollerante.
- **Moduli**: `volontari-turni` (A, f.1–f.6), `spazi-risorse` (B, f.7–f.11), `progetti` (C, f.12–f.16), `riunioni-decisioni` (D, f.17–f.20), `relazioni` (E, f.21–f.25). Rotte in `routes/modules/<modulo>.php`, Form Request + Policy su permessi spatie esistenti (`eventi.*` per turni/spazi, `struttura.*` per progetti/riunioni/contatti, `soci.*` per volontari), notifiche `ShouldQueue` brandizzate, viste sul design system, riuso di cariche/Verbale/prima nota/Scadenzario/medialibrary/Chart.js.
- **Finisher** (questa consegna): scheduler in `routes/console.php`, `DemoSeeder` arricchito, questo report, cross-check sidebar ↔ `route:list`.

---

## Compito 1 — Scheduler (`routes/console.php`)

Registrati i tre comandi nuovi (i nomi reali verificati sui rispettivi `$signature`), tutti con `withoutOverlapping()`:

| Comando | Funzione | Frequenza | Espressione cron |
|---|---|---|---|
| `turni:promemoria` | f.6 — email il giorno prima ai volontari confermati (dedup `promemoria_inviati` tipo `turno`) | daily 17:00 | `0 17 * * *` |
| `prestiti:promemoria` | f.10 — promemoria ritardo per prestiti attrezzature non restituiti oltre `restituzione_prevista` | daily 09:00 | `0 9 * * *` |
| `adempimenti:promemoria` | f.20 — promemoria mensile adempimenti ricorrenti in scadenza nel mese (usa di default il mese corrente) | mensile, 1° giorno 08:00 | `0 8 1 * *` |

`adempimenti:promemoria` è schedulato con `monthlyOn(1, '08:00')`: il comando filtra gli adempimenti con `mese_scadenza` = mese corrente, quindi il run del 1° del mese intercetta gli adempimenti di quel mese non ancora completati per l'anno.

Verifica: `php artisan schedule:list` mostra le tre righe attese; `php -l routes/console.php` ok.

```
0 17 * * *  php artisan turni:promemoria
0 9 * * *   php artisan prestiti:promemoria
0 8 1 * *   php artisan adempimenti:promemoria
```

---

## Compito 2 — Dati dimostrativi (`DemoSeeder`)

Arricchito `database/seeders/DemoSeeder.php` con la sezione "Organizzazione 3", **senza factory** (Faker è dipendenza dev, assente con `composer install --no-dev`) e senza creare utenti amministratori (i campi `responsabile_id`/`registrato_da`/`completato_da` restano `null`, tutti nullable in migration). Aggiunte:

- **Mansione** "Accoglienza" (f.2), assegnata ad Anna Verdi via pivot `socio_mansioni` (alimenta filtro libro soci e suggerimenti volontari).
- **Spazio** "Sala Riunioni" (f.7, capienza 25, collegato alla sede) + **prenotazione interna** "Prova del coro" domani 10:00–12:00 (f.8, riferimento per il rilevatore di conflitti e la vista calendario).
- **Turno** "Accoglienza assemblea" domani 09:00–13:00 (f.3) con mansione, spazio ed evento collegati, **stato aperto**, + **assegnazione confermata** di Anna Verdi (f.4-5, compare tra i confermati per `turni:promemoria`).
- **Attrezzatura** "Proiettore Full HD" (f.9, quantità 2, valore unitario, stato buono, ubicazione = spazio) + **prestito attivo** non restituito a Luca Bianchi (f.10, riduce la disponibilità, alimenta lo storico prestiti in scheda socio).
- **Progetto** "Festa di quartiere {anno}" attivo con budget 3000 (f.12), **due milestone** — una completata e una in scadenza futura (f.16, barra avanzamento 50%) — e un **movimento di prima nota** (uscita 250 €, noleggio service) imputato al progetto (f.13, rendiconto per progetto).
- **Riunione** "Consiglio Direttivo di programmazione" **svolta** con OdG strutturato (f.17), **partecipante confermato** (Anna Verdi, intestataria carica Presidente) e **votazione approvata** sul primo punto (f.18, confluisce nell'Archivio decisioni f.19).
- **Due adempimenti** ricorrenti (f.20): "Rinnovo assicurazione volontari" (mese 1) e "Approvazione bilancio annuale" (mese 4), entrambi attivi.
- **Contatto esterno** "Caffè del Borgo S.r.l." (f.21, tipo sponsor, referente, tag) + **sponsor visibile sul sito** livello oro, periodo in corso (f.22, sezione "I nostri sostenitori" della home pubblica), collegato al contatto.

Verifica: `php -l database/seeders/DemoSeeder.php` ok; `tests/Feature/Installer/DemoSeederTest.php` verde (3 test, 16 asserzioni) con il seeder eseguito end-to-end su SQLite.

---

## Compito 3 — Verifica sidebar ↔ `route:list`

La sidebar (`resources/views/layouts/sidebar.blade.php`) è data-driven con l'helper tollerante `$nav('<route>')`: 54 nomi-rotta referenziati. Cross-check con `php artisan route:list --json` (510 rotte registrate, 510 con nome):

- **Nessuna voce orfana**: tutti i 54 nomi-rotta della sidebar esistono in `route:list` (`comm -23` vuoto).
- Le voci Organizzazione 3 presenti e risolte: `turni.index`, `spazi.index`, `attrezzature.index`, `prestiti.index`, `progetti.index`, `riunioni.index`, `decisioni.index`, `contatti-esterni.index`, `sponsor.index`, `ambasciatori.index`, `statistiche-annuali.index`.
- Le **prenotazioni spazi** (f.8) sono nidificate sotto `spazi.prenotazioni.*` (calendario/lista raggiunti dalla voce `spazi.index`), quindi non esiste — correttamente — alcun nome `prenotazioni-spazi.*` separato nella sidebar.

Gruppi di rotte registrati per i 5 moduli (estratto): `turni.*` (11), `spazi.*` (8, incl. `spazi.prenotazioni.*`), `progetti.*` (12), `riunioni.*` (9), `adempimenti.*` (6), `mansioni.*` (4), `attrezzature.*` (4), `prestiti.*` (4), `contatti-esterni.*` (4), `sponsor.*` (4), `volontari.*` (2), `statistiche-annuali.*` (2), `decisioni.*` (1), `ambasciatori.*` (1).

---

## Esito test

- `php artisan test tests/Feature/VolontariTurni tests/Feature/SpaziRisorse tests/Feature/Progetti tests/Feature/Struttura tests/Feature/Installer` → **222 test, 732 asserzioni, tutti verdi** (include i feature dei 5 moduli, i command test `PrestitiPromemoriaCommandTest`/registro ore, gli adempimenti e il `DemoSeederTest` con il seeder arricchito).
- `php -l` ok su `routes/console.php` e `database/seeders/DemoSeeder.php`.
- Suite esistenti (1472 PHPUnit, 93 E2E) non toccate dalle modifiche del finisher (solo scheduler additivo, seeder additivo, report): nessuna regressione attesa.
