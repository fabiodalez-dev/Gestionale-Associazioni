<?php

namespace App\Http\Controllers\Operativita;

use App\Http\Controllers\Controller;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\ManualePagina;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Endpoint JSON della ricerca globale / command palette ⌘K (f.1 del piano
 * "Gestione 2"). Tenant-scoped tramite i global scope dei modelli; throttled
 * dalle rotte. Cerca in:
 * - soci (nome/email/numero/CF) via Scout, come la lista soci;
 * - eventi (titolo) e prenotazioni (codice) via LIKE;
 * - documenti (nome file / descrizione) via LIKE;
 * - pagine admin (catalogo statico filtrato dai permessi dell'utente).
 *
 * Risultati raggruppati: [{gruppo, icona, risultati: [{titolo, sottotitolo, url}]}].
 */
class RicercaGlobaleController extends Controller
{
    private const LIMITE_PER_GRUPPO = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['query' => $q, 'gruppi' => []]);
        }

        /** @var Utente $utente */
        $utente = $request->user();

        $gruppi = array_values(array_filter([
            $this->gruppoSoci($utente, $q),
            $this->gruppoEventi($utente, $q),
            $this->gruppoPrenotazioni($utente, $q),
            $this->gruppoDocumenti($utente, $q),
            $this->gruppoManuale($utente, $q),
            $this->gruppoPagine($utente, $q),
        ]));

        return response()->json(['query' => $q, 'gruppi' => $gruppi]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gruppoSoci(Utente $utente, string $q): ?array
    {
        if (! $this->can($utente, 'soci.view')) {
            return null;
        }

        // Scout (driver database) per nome/cognome/email/CF/numero socio,
        // poi whereIn sulla query scoped → isolamento tenant garantito.
        $ids = Socio::search($q)->keys()->take(50);

        $risultati = Socio::query()
            ->whereIn('id', $ids)
            ->orderBy('cognome')
            ->orderBy('nome')
            ->limit(self::LIMITE_PER_GRUPPO)
            ->get(['id', 'nome', 'cognome', 'numero_socio', 'email', 'stato'])
            ->map(fn (Socio $socio) => [
                'titolo' => trim($socio->cognome.' '.$socio->nome),
                'sottotitolo' => 'N. '.$socio->numero_socio.($socio->email ? ' — '.$socio->email : ''),
                'url' => route('soci.show', $socio),
            ]);

        return $this->gruppo('Soci', 'bi-people', $risultati->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gruppoEventi(Utente $utente, string $q): ?array
    {
        if (! $this->can($utente, 'eventi.view')) {
            return null;
        }

        $risultati = Evento::query()
            ->where('titolo', 'like', '%'.$this->escapeLike($q).'%')
            ->orderByDesc('data_evento')
            ->limit(self::LIMITE_PER_GRUPPO)
            ->get(['id', 'titolo', 'data_evento'])
            ->map(fn (Evento $evento) => [
                'titolo' => (string) $evento->titolo,
                'sottotitolo' => $evento->data_evento->format('d/m/Y'),
                'url' => route('eventi.index', ['search' => $evento->titolo]),
            ]);

        return $this->gruppo('Eventi', 'bi-calendar-event', $risultati->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gruppoPrenotazioni(Utente $utente, string $q): ?array
    {
        if (! $this->can($utente, 'eventi.view')) {
            return null;
        }

        $risultati = Prenotazione::query()
            ->with(['evento', 'account' => fn ($query) => $query->withTrashed()])
            ->where('codice', 'like', '%'.$this->escapeLike($q).'%')
            ->orderByDesc('created_at')
            ->limit(self::LIMITE_PER_GRUPPO)
            ->get()
            ->map(fn (Prenotazione $p) => [
                'titolo' => (string) $p->codice,
                'sottotitolo' => trim($p->evento->titolo.' — '.$p->account->nome_completo),
                'url' => route('eventi.prenotazioni.index', ['evento' => $p->evento_id, 'stato' => 'all']),
            ]);

        return $this->gruppo('Prenotazioni', 'bi-ticket-perforated', $risultati->values()->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gruppoDocumenti(Utente $utente, string $q): ?array
    {
        if (! $this->can($utente, 'documenti.view') || ! Route::has('documenti.index')) {
            return null;
        }

        $like = '%'.$this->escapeLike($q).'%';

        $risultati = Documento::query()
            ->where(fn ($query) => $query
                ->where('nome_file', 'like', $like)
                ->orWhere('descrizione', 'like', $like))
            ->orderByDesc('created_at')
            ->limit(self::LIMITE_PER_GRUPPO)
            ->get(['id', 'nome_file', 'categoria'])
            ->map(fn (Documento $documento) => [
                'titolo' => (string) $documento->nome_file,
                'sottotitolo' => $documento->categoria ?: 'Documento',
                'url' => route('documenti.index', ['search' => $documento->nome_file]),
            ]);

        return $this->gruppo('Documenti', 'bi-file-earmark-text', $risultati->all());
    }

    /**
     * Pagine del manuale operativo (issue #12): titolo, sezione e testo.
     *
     * @return array<string, mixed>|null
     */
    private function gruppoManuale(Utente $utente, string $q): ?array
    {
        if (! $this->can($utente, 'manuale.view') || ! Route::has('manuale.show')) {
            return null;
        }

        $like = '%'.$this->escapeLike($q).'%';

        $risultati = ManualePagina::query()
            ->where(fn ($query) => $query
                ->where('titolo', 'like', $like)
                ->orWhere('sezione', 'like', $like)
                ->orWhere('contenuto', 'like', $like))
            ->orderBy('titolo')
            ->limit(self::LIMITE_PER_GRUPPO)
            ->get(['id', 'titolo', 'sezione'])
            ->map(fn (ManualePagina $pagina) => [
                'titolo' => (string) $pagina->titolo,
                'sottotitolo' => 'Manuale · '.$pagina->sezione,
                'url' => route('manuale.show', $pagina),
            ]);

        return $this->gruppo('Manuale operativo', 'bi-book', $risultati->all());
    }

    /**
     * Catalogo statico delle pagine admin, filtrato per permesso e
     * disponibilità della rotta (i moduli vengono attivati progressivamente).
     *
     * @return array<string, mixed>|null
     */
    private function gruppoPagine(Utente $utente, string $q): ?array
    {
        $risultati = [];

        foreach ($this->pagineAdmin() as $pagina) {
            if ($pagina['permesso'] !== null && ! $this->can($utente, $pagina['permesso'])) {
                continue;
            }

            if (! Route::has($pagina['route'])) {
                continue;
            }

            $testo = $pagina['titolo'].' '.($pagina['keywords'] ?? '');

            if (! Str::contains(Str::lower($testo), Str::lower($q))) {
                continue;
            }

            $risultati[] = [
                'titolo' => $pagina['titolo'],
                'sottotitolo' => 'Pagina',
                'url' => route($pagina['route']),
            ];

            if (count($risultati) >= self::LIMITE_PER_GRUPPO) {
                break;
            }
        }

        return $this->gruppo('Pagine', 'bi-window', $risultati);
    }

    /**
     * @return list<array{titolo: string, route: string, permesso: ?string, keywords?: string}>
     */
    private function pagineAdmin(): array
    {
        return [
            ['titolo' => 'Dashboard', 'route' => 'dashboard', 'permesso' => null, 'keywords' => 'home pannello'],
            ['titolo' => 'Soci', 'route' => 'soci.index', 'permesso' => 'soci.view', 'keywords' => 'anagrafica lista membri'],
            ['titolo' => 'Nuovo socio', 'route' => 'soci.create', 'permesso' => 'soci.create', 'keywords' => 'crea iscrizione'],
            ['titolo' => 'Tessere', 'route' => 'tessere.index', 'permesso' => 'tessere.view', 'keywords' => 'tesseramento card'],
            ['titolo' => 'Quote', 'route' => 'quote.index', 'permesso' => 'quote.view', 'keywords' => 'pagamenti incassi'],
            ['titolo' => 'Eventi', 'route' => 'eventi.index', 'permesso' => 'eventi.view', 'keywords' => 'calendario manifestazioni'],
            ['titolo' => 'Calendario eventi', 'route' => 'calendario-eventi.index', 'permesso' => 'eventi.view', 'keywords' => 'agenda mese'],
            ['titolo' => 'Documenti', 'route' => 'documenti.index', 'permesso' => 'documenti.view', 'keywords' => 'file archivio'],
            ['titolo' => 'Verbali', 'route' => 'verbali.index', 'permesso' => 'verbali.view', 'keywords' => 'assemblee consiglio'],
            ['titolo' => 'Scadenze', 'route' => 'scadenze.index', 'permesso' => 'scadenze.view', 'keywords' => 'promemoria deadline'],
            ['titolo' => 'Attività admin', 'route' => 'attivita-admin.index', 'permesso' => null, 'keywords' => 'promemoria task da fare todo'],
            ['titolo' => 'Registro modifiche', 'route' => 'registro-modifiche.index', 'permesso' => 'struttura.view', 'keywords' => 'audit log storico activity'],
            ['titolo' => 'Campagna rinnovi', 'route' => 'campagna-rinnovi.index', 'permesso' => 'tessere.view', 'keywords' => 'rinnovo tessere'],
            ['titolo' => 'Invia comunicazione', 'route' => 'comunicazioni.index', 'permesso' => 'email.view', 'keywords' => 'email newsletter messaggio'],
            ['titolo' => 'Impostazioni', 'route' => 'configurazioni.index', 'permesso' => 'struttura.view', 'keywords' => 'configurazione associazione settings'],
            ['titolo' => 'Utenti', 'route' => 'amministratori.index', 'permesso' => 'struttura.view', 'keywords' => 'amministratori operatori admin'],
            ['titolo' => 'Export/Import', 'route' => 'export-import.index', 'permesso' => 'soci.view', 'keywords' => 'excel csv esporta importa'],
            ['titolo' => 'Libro soci', 'route' => 'libro-soci.index', 'permesso' => 'soci.view', 'keywords' => 'registro elenco'],
        ];
    }

    /**
     * @param  list<array<string, string>>  $risultati
     * @return array<string, mixed>|null
     */
    private function gruppo(string $nome, string $icona, array $risultati): ?array
    {
        if ($risultati === []) {
            return null;
        }

        return ['gruppo' => $nome, 'icona' => $icona, 'risultati' => $risultati];
    }

    /**
     * Permesso granulare con fallback su ruolo (stessa logica delle Policy:
     * in ambienti senza RolesSeeder i permessi spatie non esistono).
     */
    private function can(Utente $utente, string $permesso): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        try {
            return $utente->hasPermissionTo($permesso);
        } catch (\Throwable) {
            return true; // permessi non seedati: la pagina di destinazione applica comunque le proprie Policy
        }
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
