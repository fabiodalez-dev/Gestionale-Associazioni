<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\RichiestaIscrizione;
use App\Models\Riunione;
use App\Models\VotazioneRiunione;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Archivio Decisioni (f.19 del piano "Organizzazione 3"): pagina aggregata
 * e ricercabile che unisce
 * - le votazioni APPROVATE delle riunioni (con riunione, data, punto, esito
 *   numerico favorevoli/contrari/astenuti e link al verbale collegato);
 * - le delibere di ammissione dei soci del round 1 (richieste approvate con
 *   numero/data di delibera).
 *
 * Ricerca per testo (titolo punto/decisione) e filtro per anno.
 * Governance: stessa policy delle riunioni (struttura.view).
 */
class DecisioneController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Riunione::class);

        $search = trim((string) $request->query('search', ''));
        $anno = is_numeric($request->query('anno')) ? (int) $request->query('anno') : null;

        $decisioni = $this->votazioniApprovate($search, $anno)
            ->merge($this->delibereAmmissione($search, $anno))
            ->sortByDesc('data')
            ->values();

        return view('struttura.decisioni.index', [
            'decisioni' => $decisioni,
            'search' => $search,
            'anno' => $anno,
            'anni' => $this->anniDisponibili(),
        ]);
    }

    /**
     * Votazioni approvate -> righe di decisione (f.19).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function votazioniApprovate(string $search, ?int $anno): Collection
    {
        return VotazioneRiunione::query()
            ->approvate()
            ->with(['riunione' => fn ($q) => $q->with('verbale')])
            ->get()
            ->filter(fn (VotazioneRiunione $v) => $v->riunione !== null)
            ->when($anno !== null, fn ($c) => $c->filter(
                fn (VotazioneRiunione $v) => (int) $v->riunione->data_ora->year === $anno
            ))
            ->when($search !== '', fn ($c) => $c->filter(function (VotazioneRiunione $v) use ($search) {
                $testo = ($v->puntoOdg() ?? '').' '.$v->riunione->titolo.' '.(string) $v->note;

                return mb_stripos($testo, $search) !== false;
            }))
            ->map(fn (VotazioneRiunione $v): array => $this->riga(
                tipo: 'votazione',
                titolo: $v->puntoOdg() ?? ('Punto '.($v->punto_indice + 1)),
                contesto: $v->riunione->titolo,
                data: $v->riunione->data_ora,
                esito: 'Approvato',
                numerico: "Favorevoli {$v->favorevoli} · Contrari {$v->contrari} · Astenuti {$v->astenuti}",
                note: (string) $v->note,
                riunioneId: $v->riunione_id,
                verbaleId: $v->riunione->verbale_id,
                verbaleNumero: $v->riunione->verbale?->numero_completo,
            ))
            ->values();
    }

    /**
     * Delibere di ammissione (round 1) -> righe di decisione (f.19).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function delibereAmmissione(string $search, ?int $anno): Collection
    {
        return RichiestaIscrizione::query()
            ->where('stato', RichiestaIscrizione::STATO_APPROVATA)
            ->whereNotNull('delibera_numero')
            ->with(['socio' => fn ($q) => $q->withTrashed()])
            ->when($anno !== null, fn ($q) => $q->whereYear('delibera_data', $anno))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('delibera_numero', 'like', "%{$search}%")
                        ->orWhereHas('socio', function ($q) use ($search) {
                            $q->where('nome', 'like', "%{$search}%")
                                ->orWhere('cognome', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('delibera_data')
            ->get()
            ->map(function (RichiestaIscrizione $r): array {
                return $this->riga(
                    tipo: 'ammissione',
                    titolo: 'Ammissione socio: '.($r->socio->nome_completo ?? 'Socio'),
                    contesto: 'Delibera n. '.$r->delibera_numero,
                    data: $r->delibera_data,
                    esito: 'Ammesso',
                );
            })
            ->values();
    }

    /**
     * Costruisce una riga di decisione normalizzata (f.19).
     *
     * @return array<string, mixed>
     */
    private function riga(
        string $tipo,
        string $titolo,
        string $contesto,
        mixed $data,
        string $esito,
        ?string $numerico = null,
        string $note = '',
        ?string $riunioneId = null,
        ?string $verbaleId = null,
        ?string $verbaleNumero = null,
    ): array {
        return [
            'tipo' => $tipo,
            'titolo' => $titolo,
            'contesto' => $contesto,
            'data' => $data,
            'esito' => $esito,
            'numerico' => $numerico,
            'note' => $note,
            'riunione_id' => $riunioneId,
            'verbale_id' => $verbaleId,
            'verbale_numero' => $verbaleNumero,
        ];
    }

    /**
     * Anni presenti nelle decisioni (votazioni approvate + delibere) per il
     * filtro a tendina.
     *
     * @return list<int>
     */
    private function anniDisponibili(): array
    {
        $anniVotazioni = VotazioneRiunione::query()
            ->approvate()
            ->with('riunione:id,data_ora')
            ->get()
            ->map(fn (VotazioneRiunione $v) => $v->riunione !== null ? (int) $v->riunione->data_ora->year : null)
            ->filter()
            ->all();

        $anniDelibere = RichiestaIscrizione::query()
            ->where('stato', RichiestaIscrizione::STATO_APPROVATA)
            ->whereNotNull('delibera_data')
            ->get(['delibera_data'])
            ->map(fn ($r) => (int) $r->delibera_data->year)
            ->all();

        $anni = collect($anniVotazioni)->merge($anniDelibere)->unique()->sortDesc()->values();

        if ($anni->isEmpty()) {
            return [(int) now()->format('Y')];
        }

        return $anni->all();
    }
}
