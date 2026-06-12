<?php

namespace App\Http\Controllers\Economia;

use App\Http\Controllers\Controller;
use App\Models\Carica;
use App\Models\DocumentoSocio;
use App\Models\Quota;
use App\Models\Scadenza;
use App\Models\Utente;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Scadenzario unificato (f.18 del piano "Gestione 2"): vista aggregata
 * (nessuna tabella nuova) di quote in scadenza non pagate, documenti
 * socio in scadenza, mandati cariche in scadenza e scadenze custom,
 * con filtri per tipo e range di date.
 *
 * Ogni sezione è inclusa solo se l'utente ha il permesso view del
 * modulo corrispondente (quote.view, documenti.view, soci.view,
 * scadenze.view); il super_admin vede tutto.
 */
class ScadenzarioController extends Controller
{
    public const TIPI = ['quota', 'documento', 'carica', 'scadenza'];

    /** Range di default: da oggi a +90 giorni. */
    public const GIORNI_DEFAULT = 90;

    public function index(Request $request): View
    {
        /** @var Utente $utente */
        $utente = $request->user();

        [$dal, $al] = $this->range($request);
        $tipo = in_array($request->query('tipo'), self::TIPI, true) ? (string) $request->query('tipo') : null;

        $voci = collect();

        if ($this->includi('quota', $tipo, $utente, 'quote.view')) {
            $voci = $voci->merge($this->quote($dal, $al));
        }

        if ($this->includi('documento', $tipo, $utente, 'documenti.view')) {
            $voci = $voci->merge($this->documenti($dal, $al));
        }

        if ($this->includi('carica', $tipo, $utente, 'soci.view')) {
            $voci = $voci->merge($this->cariche($dal, $al));
        }

        if ($this->includi('scadenza', $tipo, $utente, 'scadenze.view')) {
            $voci = $voci->merge($this->scadenzeCustom($dal, $al));
        }

        return view('economia.scadenzario.index', [
            'voci' => $voci->sortBy('data')->values(),
            'dal' => $dal,
            'al' => $al,
            'filtroTipo' => $tipo,
        ]);
    }

    protected function includi(string $sezione, ?string $tipoFiltro, Utente $utente, string $permesso): bool
    {
        if ($tipoFiltro !== null && $tipoFiltro !== $sezione) {
            return false;
        }

        return $utente->isSuperAdmin() || $utente->can($permesso);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(Request $request): array
    {
        try {
            $dal = filled($request->query('dal'))
                ? Carbon::parse((string) $request->query('dal'))->startOfDay()
                : now()->startOfDay();
        } catch (\Throwable) {
            $dal = now()->startOfDay();
        }

        try {
            $al = filled($request->query('al'))
                ? Carbon::parse((string) $request->query('al'))->endOfDay()
                : now()->addDays(self::GIORNI_DEFAULT)->endOfDay();
        } catch (\Throwable) {
            $al = now()->addDays(self::GIORNI_DEFAULT)->endOfDay();
        }

        if ($dal->gt($al)) {
            [$dal, $al] = [$al->copy()->startOfDay(), $dal->copy()->endOfDay()];
        }

        return [$dal, $al];
    }

    /**
     * Quote non pagate con scadenza nel range.
     *
     * @return list<array<string, mixed>>
     */
    protected function quote(Carbon $dal, Carbon $al): array
    {
        return Quota::query()
            ->with('socio')
            ->whereNull('data_pagamento')
            ->whereDate('data_scadenza', '>=', $dal->toDateString())
            ->whereDate('data_scadenza', '<=', $al->toDateString())
            ->orderBy('data_scadenza')
            ->get()
            ->map(fn (Quota $quota) => [
                'tipo' => 'quota',
                'titolo' => 'Quota '.$quota->anno.' — '.($quota->socio !== null ? trim($quota->socio->cognome.' '.$quota->socio->nome) : 'socio eliminato'),
                'dettaglio' => '€ '.number_format((float) $quota->importo, 2, ',', '.'),
                'data' => $quota->data_scadenza,
                'url' => route('quote.index', ['year' => $quota->anno]),
            ])
            ->all();
    }

    /**
     * Documenti socio con scadenza nel range.
     *
     * @return list<array<string, mixed>>
     */
    protected function documenti(Carbon $dal, Carbon $al): array
    {
        return DocumentoSocio::query()
            ->with('socio')
            ->whereNotNull('data_scadenza')
            ->whereDate('data_scadenza', '>=', $dal->toDateString())
            ->whereDate('data_scadenza', '<=', $al->toDateString())
            ->orderBy('data_scadenza')
            ->get()
            ->map(fn (DocumentoSocio $documento) => [
                'tipo' => 'documento',
                'titolo' => ($documento->tipo_documento ?: 'Documento').' — '.($documento->socio !== null ? trim($documento->socio->cognome.' '.$documento->socio->nome) : 'socio eliminato'),
                'dettaglio' => $documento->nome_file ?? '',
                'data' => $documento->data_scadenza,
                'url' => $documento->socio !== null ? route('soci.show', $documento->socio_id) : null,
            ])
            ->all();
    }

    /**
     * Mandati delle cariche sociali in scadenza nel range.
     *
     * @return list<array<string, mixed>>
     */
    protected function cariche(Carbon $dal, Carbon $al): array
    {
        return Carica::query()
            ->with('socio')
            ->whereNotNull('fine')
            ->whereDate('fine', '>=', $dal->toDateString())
            ->whereDate('fine', '<=', $al->toDateString())
            ->orderBy('fine')
            ->get()
            ->map(fn (Carica $carica) => [
                'tipo' => 'carica',
                'titolo' => $carica->ruolo.' — '.($carica->socio !== null ? trim($carica->socio->cognome.' '.$carica->socio->nome) : 'socio eliminato'),
                'dettaglio' => 'Fine mandato',
                'data' => $carica->fine,
                'url' => route('organi-sociali.index'),
            ])
            ->all();
    }

    /**
     * Scadenze custom attive nel range.
     *
     * @return list<array<string, mixed>>
     */
    protected function scadenzeCustom(Carbon $dal, Carbon $al): array
    {
        return Scadenza::query()
            ->where('stato', Scadenza::STATO_ATTIVA)
            ->whereDate('data_scadenza', '>=', $dal->toDateString())
            ->whereDate('data_scadenza', '<=', $al->toDateString())
            ->orderBy('data_scadenza')
            ->get()
            ->map(fn (Scadenza $scadenza) => [
                'tipo' => 'scadenza',
                'titolo' => $scadenza->titolo,
                'dettaglio' => (string) ($scadenza->tipo_scadenza ?? ''),
                'data' => $scadenza->data_scadenza,
                'url' => route('scadenze.index'),
            ])
            ->all();
    }
}
