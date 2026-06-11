<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eventi\PartecipanteStoreRequest;
use App\Http\Requests\Eventi\PartecipanteUpdateRequest;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestione partecipanti di un evento (pagina admin "partecipanti" del legacy).
 *
 * Un evento_id di un altro tenant produce 404 grazie al binding scoped
 * (equivalente del redirect silenzioso legacy).
 */
class PartecipanteController extends Controller
{
    use AuthorizesRequests;

    /**
     * Lista partecipanti con filtro per stato, statistiche e soci disponibili.
     *
     * Nota (fix del bug legacy, spec §7.8): le statistiche sono SEMPRE calcolate
     * sull'intero evento, non sul set filtrato.
     */
    public function index(Request $request, Evento $evento): View
    {
        $this->authorize('view', $evento);

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, EventoPartecipante::STATI, true)) {
            $status = 'all';
        }

        $partecipanti = $evento->partecipanti()
            ->with('socio')
            ->when($status !== 'all', fn ($q) => $q->where('stato_partecipazione', $status))
            ->join('soci', 'soci.id', '=', 'eventi_partecipanti.socio_id')
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->select('eventi_partecipanti.*')
            ->get();

        // Statistiche sull'intero evento (indipendenti dal filtro attivo)
        $statiCount = $evento->partecipanti()
            ->selectRaw('stato_partecipazione, COUNT(*) as totale')
            ->groupBy('stato_partecipazione')
            ->pluck('totale', 'stato_partecipazione');

        $stats = [
            'confermati' => (int) ($statiCount[EventoPartecipante::STATO_CONFERMATO] ?? 0),
            'forse' => (int) ($statiCount[EventoPartecipante::STATO_FORSE] ?? 0),
            'non_partecipa' => (int) ($statiCount[EventoPartecipante::STATO_NON_PARTECIPA] ?? 0),
        ];
        $stats['totale'] = $stats['confermati'] + $stats['forse'] + $stats['non_partecipa'];

        $tessereCreate = Tessera::query()
            ->where('evento_creazione_id', $evento->id)
            ->count();

        $sociDisponibili = $this->sociDisponibili($evento);

        return view('eventi.partecipanti', [
            'evento' => $evento,
            'partecipanti' => $partecipanti,
            'stats' => $stats,
            'tessereCreate' => $tessereCreate,
            'sociDisponibili' => $sociDisponibili,
            'status' => $status,
            'stati' => EventoPartecipante::STATI,
        ]);
    }

    /**
     * Aggiunta di un singolo socio. Upsert: se il socio è già iscritto
     * (doppio submit / race) aggiorna stato e note invece di fallire
     * (parità con l'ON DUPLICATE KEY UPDATE legacy).
     */
    public function store(PartecipanteStoreRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        EventoPartecipante::query()->updateOrCreate(
            [
                'evento_id' => $evento->id,
                'socio_id' => $request->validated('socio_id'),
            ],
            [
                'associazione_id' => $evento->associazione_id,
                'stato_partecipazione' => $request->validated('stato_partecipazione'),
                'note' => $request->validated('note'),
                'data_conferma' => now(),
            ]
        );

        return redirect()
            ->route('eventi.partecipanti.index', $evento)
            ->with('success', 'Socio aggiunto all\'evento!');
    }

    /**
     * Aggiunta massiva: tutti i soci Attivi non ancora iscritti, con stato
     * default "Non Partecipa" e data_conferma NULL. I duplicati vengono
     * ignorati (insertOrIgnore + UNIQUE evento_id/socio_id) e il conteggio
     * riflette solo gli inserimenti effettivi.
     */
    public function storeAll(Request $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $rows = $this->sociDisponibili($evento)
            ->map(fn (Socio $socio) => [
                'id' => (string) Str::uuid(),
                'associazione_id' => $evento->associazione_id,
                'evento_id' => $evento->id,
                'socio_id' => $socio->id,
                'stato_partecipazione' => EventoPartecipante::STATO_NON_PARTECIPA,
                'data_conferma' => null,
                'note' => null,
                'created_at' => now(),
            ])
            ->all();

        $inseriti = $rows === [] ? 0 : EventoPartecipante::query()->insertOrIgnore($rows);

        return redirect()
            ->route('eventi.partecipanti.index', $evento)
            ->with('success', "Aggiunti {$inseriti} soci all'evento!");
    }

    /**
     * Aggiornamento stato/note. data_conferma viene SEMPRE riscritta a now()
     * a ogni modifica (è il timestamp "ultima transizione di stato").
     */
    public function update(PartecipanteUpdateRequest $request, Evento $evento, string $partecipante): RedirectResponse
    {
        $this->authorize('update', $evento);

        /** @var EventoPartecipante $record */
        $record = $evento->partecipanti()->findOrFail($partecipante);

        $record->update([
            'stato_partecipazione' => $request->validated('stato_partecipazione'),
            'note' => $request->validated('note'),
            'data_conferma' => now(),
        ]);

        return redirect()
            ->route('eventi.partecipanti.index', $evento)
            ->with('success', 'Partecipazione aggiornata con successo!');
    }

    /**
     * Rimozione partecipante (idempotente: nessun errore se inesistente,
     * parità legacy).
     */
    public function destroy(Request $request, Evento $evento, string $partecipante): RedirectResponse
    {
        $this->authorize('update', $evento);

        $evento->partecipanti()->whereKey($partecipante)->delete();

        return redirect()
            ->route('eventi.partecipanti.index', $evento)
            ->with('success', 'Partecipante rimosso dall\'evento!');
    }

    /**
     * Esportazione CSV dei partecipanti (rispetta il filtro stato corrente).
     * Separatore ';' e BOM UTF-8 per compatibilità con Excel italiano.
     */
    public function export(Request $request, Evento $evento): StreamedResponse
    {
        $this->authorize('view', $evento);

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, EventoPartecipante::STATI, true)) {
            $status = 'all';
        }

        $partecipanti = $evento->partecipanti()
            ->with('socio')
            ->when($status !== 'all', fn ($q) => $q->where('stato_partecipazione', $status))
            ->join('soci', 'soci.id', '=', 'eventi_partecipanti.socio_id')
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->select('eventi_partecipanti.*')
            ->get();

        $filename = 'partecipanti-'.Str::slug($evento->titolo ?: 'evento').'.csv';

        return response()->streamDownload(function () use ($partecipanti) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 per Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Cognome', 'Nome', 'Numero Socio', 'Email', 'Telefono', 'Stato', 'Data Conferma', 'Note'], ';');

            foreach ($partecipanti as $p) {
                fputcsv($out, [
                    $p->socio?->cognome,
                    $p->socio?->nome,
                    $p->socio?->numero_socio,
                    $p->socio?->email,
                    $p->socio?->telefono,
                    $p->stato_partecipazione,
                    $p->data_conferma?->format('d/m/Y H:i'),
                    $p->note,
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Soci Attivi del tenant dell'evento non ancora iscritti, ordinati
     * per cognome e nome.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Socio>
     */
    private function sociDisponibili(Evento $evento): \Illuminate\Database\Eloquent\Collection
    {
        return Socio::query()
            ->forAssociazione($evento->associazione_id)
            ->where('stato', Socio::STATO_ATTIVO)
            ->whereNotIn('id', $evento->partecipanti()->select('socio_id'))
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome', 'numero_socio']);
    }
}
