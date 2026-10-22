<?php

namespace App\Services\Dashboard;

use App\Models\Associazione;
use App\Models\CategoriaSocio;
use App\Models\Evento;
use App\Models\Quota;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dati della dashboard ridisegnata: tesseramento, incassi, andamento
 * mensile, composizione per categoria, rinnovi in scadenza e attività
 * recenti. Tutte le query passano dai global scope (tenant e perimetro
 * sede), quindi in vista globale del super admin coprono tutti i tenant.
 */
class PanoramicaDashboard
{
    private const MESI = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

    /**
     * @return array<string, mixed>
     */
    public function calcola(): array
    {
        $oggi = now()->startOfDay();
        $anno = (int) $oggi->year;

        $sociAttivi = Socio::query()->where('stato', 'Attivo')->count();
        $tesserati = Tessera::query()
            ->where('stato', 'Attiva')
            ->where(fn ($q) => $q->whereNull('data_scadenza')->orWhere('data_scadenza', '>=', $oggi))
            ->distinct()
            ->count('socio_id');

        $incassatoAnno = (float) Quota::query()->where('stato', 'Pagata')->where('anno', $anno)->sum('importo');
        $incassatoPrecedente = (float) Quota::query()->where('stato', 'Pagata')->where('anno', $anno - 1)->sum('importo');
        $daIncassare = (float) Quota::query()->where('stato', '!=', 'Pagata')->where('anno', $anno)->sum('importo');

        $inizioMese = $oggi->copy()->startOfMonth();
        $nuoviMese = Socio::query()->where('data_iscrizione', '>=', $inizioMese)->count();
        $nuoviMesePrecedente = Socio::query()
            ->whereBetween('data_iscrizione', [$inizioMese->copy()->subMonth(), $inizioMese->copy()->subDay()->endOfDay()])
            ->count();

        return [
            'soci_attivi' => $sociAttivi,
            'tesserati' => $tesserati,
            'percentuale_tesserati' => $sociAttivi > 0 ? (int) round(min(100, $tesserati * 100 / $sociAttivi)) : 0,
            'richieste_in_attesa' => RichiestaIscrizione::query()->inAttesa()->count(),
            'incassato_anno' => $incassatoAnno,
            'variazione_incassi' => $this->variazione($incassatoAnno, $incassatoPrecedente),
            'da_incassare' => $daIncassare,
            'nuovi_mese' => $nuoviMese,
            'variazione_nuovi' => $this->variazione($nuoviMese, $nuoviMesePrecedente),
            'andamento' => $this->andamento($oggi),
            'composizione' => $this->composizione($sociAttivi),
            'rinnovi' => $this->rinnovi($oggi),
            'attivita' => $this->attivita(),
        ];
    }

    /**
     * Variazione percentuale; null se manca il termine di confronto.
     */
    private function variazione(float|int $attuale, float|int $precedente): ?float
    {
        if ($precedente <= 0) {
            return null;
        }

        return round(($attuale - $precedente) * 100 / $precedente, 1);
    }

    /**
     * Ultimi 12 mesi: nuovi soci (data di iscrizione) e tessere emesse.
     *
     * @return array{mesi: list<array{etichetta: string, anno: int, nuovi: int, tessere: int}>, massimo: int}
     */
    private function andamento(Carbon $oggi): array
    {
        $inizio = $oggi->copy()->startOfMonth()->subMonths(11);

        $chiave = fn ($data) => $data ? Carbon::parse($data)->format('Y-m') : null;
        $nuovi = Socio::query()->where('data_iscrizione', '>=', $inizio)->pluck('data_iscrizione')->map($chiave)->countBy();
        $tessere = Tessera::query()->where('data_emissione', '>=', $inizio)->pluck('data_emissione')->map($chiave)->countBy();

        $mesi = [];
        for ($i = 0; $i < 12; $i++) {
            $mese = $inizio->copy()->addMonths($i);
            $k = $mese->format('Y-m');
            $mesi[] = [
                'etichetta' => self::MESI[$mese->month - 1],
                'anno' => (int) $mese->year,
                'nuovi' => (int) ($nuovi[$k] ?? 0),
                'tessere' => (int) ($tessere[$k] ?? 0),
            ];
        }

        $massimo = max(1, ...array_map(fn ($m) => max($m['nuovi'], $m['tessere']), $mesi));
        // Fondo scala "tondo" per le tre linee guida dell'asse
        $passo = $massimo <= 6 ? 3 : (int) (ceil($massimo / 3 / 5) * 5);

        return ['mesi' => $mesi, 'massimo' => max(3, $passo * 3)];
    }

    /**
     * Soci attivi per categoria (le categorie senza soci non compaiono).
     *
     * @return list<array{nome: string, numero: int, percentuale: int}>
     */
    private function composizione(int $sociAttivi): array
    {
        if ($sociAttivi === 0) {
            return [];
        }

        $conteggi = Socio::query()
            ->where('stato', 'Attivo')
            ->selectRaw('categoria_socio_id, count(*) as numero')
            ->groupBy('categoria_socio_id')
            ->pluck('numero', 'categoria_socio_id');

        $nomi = CategoriaSocio::query()->whereIn('id', $conteggi->keys()->filter())->pluck('nome', 'id');

        return $conteggi
            ->map(fn ($numero, $id) => [
                'nome' => $id ? ($nomi[$id] ?? 'Categoria') : 'Senza categoria',
                'numero' => (int) $numero,
                'percentuale' => (int) round($numero * 100 / $sociAttivi),
            ])
            ->sortByDesc('numero')
            ->values()
            ->all();
    }

    /**
     * Tessere scadute da poco o in scadenza nei prossimi 30 giorni.
     *
     * @return Collection<int, Tessera>
     */
    private function rinnovi(Carbon $oggi): Collection
    {
        return Tessera::query()
            ->with(['socio' => fn ($q) => $q->with('categoriaSocio:id,nome')])
            ->whereHas('socio', fn ($q) => $q->where('stato', 'Attivo'))
            ->whereIn('stato', ['Attiva', 'Scaduta'])
            ->whereBetween('data_scadenza', [$oggi->copy()->subDays(30), $oggi->copy()->addDays(30)->endOfDay()])
            ->orderBy('data_scadenza')
            ->limit(5)
            ->get();
    }

    /**
     * Ultimi fatti: nuovi soci, pagamenti, richieste dal sito, eventi.
     *
     * @return Collection<int, array{tipo: string, titolo: string, dettaglio: string, quando: Carbon}>
     */
    private function attivita(): Collection
    {
        $voci = collect();

        Socio::query()->latest('created_at')->limit(4)->get(['id', 'nome', 'cognome', 'created_at'])
            ->each(fn (Socio $s) => $voci->push([
                'tipo' => 'ok',
                'titolo' => 'Nuovo socio',
                'dettaglio' => $s->nome.' '.$s->cognome,
                'quando' => $s->created_at,
            ]));

        Quota::query()->with('socio:id,nome,cognome')->where('stato', 'Pagata')->whereNotNull('data_pagamento')
            ->latest('data_pagamento')->limit(4)->get()
            ->each(fn (Quota $q) => $voci->push([
                'tipo' => 'info',
                'titolo' => 'Pagamento ricevuto',
                'dettaglio' => '€ '.number_format((float) $q->importo, 2, ',', '.').($q->socio ? ' da '.$q->socio->nome.' '.$q->socio->cognome : ''),
                'quando' => Carbon::parse($q->data_pagamento),
            ]));

        RichiestaIscrizione::query()->latest('created_at')->limit(4)->get()
            ->each(function (RichiestaIscrizione $r) use ($voci): void {
                $dati = $r->dati_form;
                $voci->push([
                    'tipo' => 'warn',
                    'titolo' => 'Richiesta di iscrizione',
                    'dettaglio' => trim(($dati['nome'] ?? '').' '.($dati['cognome'] ?? '')) ?: 'Dal modulo online',
                    'quando' => $r->created_at,
                ]);
            });

        Evento::query()->latest('created_at')->limit(3)->get(['id', 'titolo', 'data_evento', 'created_at'])
            ->each(fn (Evento $e) => $voci->push([
                'tipo' => 'muted',
                'titolo' => 'Evento creato',
                'dettaglio' => $e->titolo.' · '.$e->data_evento->format('d/m'),
                'quando' => $e->created_at,
            ]));

        return $voci->filter(fn ($v) => $v['quando'] !== null)->sortByDesc('quando')->take(5)->values();
    }

    /**
     * Associazioni attive con numero di soci (vista globale del super admin).
     *
     * @return Collection<int, Associazione>
     */
    public function associazioni(): Collection
    {
        return Associazione::query()
            ->where('attiva', true)
            ->withCount(['soci' => fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at')])
            ->orderByDesc('soci_count')
            ->get(['id', 'nome', 'citta']);
    }
}
