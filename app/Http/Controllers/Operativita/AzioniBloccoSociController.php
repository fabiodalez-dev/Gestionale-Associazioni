<?php

namespace App\Http\Controllers\Operativita;

use App\Exports\Operativita\SociSelezioneExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operativita\AzioniBloccoSociRequest;
use App\Models\CategoriaSocio;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\TipoSocio;
use App\Services\Soci\SocioActivityLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Azioni in blocco sulla lista soci (f.2 del piano "Gestione 2"):
 * cambio stato/tipo/categoria/sede, assegnazione/rimozione tag ed export
 * della selezione. Ogni modifica è tracciata nello storico attività del
 * singolo socio (audit, come da spec). I soci di altri tenant o per cui
 * l'utente non ha il permesso di aggiornamento vengono ignorati.
 */
class AzioniBloccoSociController extends Controller
{
    use AuthorizesRequests;

    public const TIPO_ATTIVITA_AUDIT = 'Azione in blocco';

    public function __construct(private readonly SocioActivityLogger $activityLogger) {}

    public function applica(AzioniBloccoSociRequest $request): RedirectResponse
    {
        $this->authorize('viewAny', Socio::class);

        $azione = (string) $request->validated('azione');
        $valore = (string) $request->validated('valore');

        // Global scope tenant: gli id di altri tenant spariscono dalla query
        $soci = Socio::query()
            ->whereIn('id', $request->validated('soci_ids'))
            ->get();

        $descrizione = $this->descrizioneAzione($azione, $valore);
        $aggiornati = 0;

        foreach ($soci as $socio) {
            if (Gate::denies('update', $socio)) {
                continue;
            }

            $this->applicaAzione($socio, $azione, $valore);

            $this->activityLogger->log(
                $socio,
                self::TIPO_ATTIVITA_AUDIT,
                $descrizione,
                ['azione' => $azione, 'valore' => $valore]
            );

            $aggiornati++;
        }

        if ($aggiornati === 0) {
            return back()->with('error', 'Nessun socio aggiornato: selezione vuota o permessi insufficienti.');
        }

        return back()->with(
            'success',
            "{$descrizione} applicata a {$aggiornati} ".($aggiornati === 1 ? 'socio' : 'soci').'.'
        );
    }

    /**
     * Export Excel della selezione corrente (stesse colonne dell'export soci).
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Socio::class);

        $validated = $request->validate([
            'soci_ids' => ['required', 'array', 'min:1', 'max:500'],
            'soci_ids.*' => ['required', 'uuid'],
        ]);

        return Excel::download(
            new SociSelezioneExport($validated['soci_ids']),
            'soci-selezione-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    private function applicaAzione(Socio $socio, string $azione, string $valore): void
    {
        match ($azione) {
            'stato' => $socio->update(['stato' => $valore]),
            'tipo_socio' => $socio->update(['tipo_socio_id' => $valore]),
            'categoria' => $socio->update(['categoria_socio_id' => $valore]),
            'sede' => $socio->update(['sede_id' => $valore]),
            'tag_assegna' => $socio->tags()->syncWithoutDetaching([$valore]),
            'tag_rimuovi' => $socio->tags()->detach($valore),
            default => null,
        };
    }

    /**
     * Descrizione leggibile dell'azione per il riepilogo e l'audit.
     */
    private function descrizioneAzione(string $azione, string $valore): string
    {
        return match ($azione) {
            'stato' => "Cambio stato in \"{$valore}\"",
            'tipo_socio' => 'Cambio tipo socio in "'.(TipoSocio::query()->whereKey($valore)->value('nome') ?? $valore).'"',
            'categoria' => 'Cambio categoria in "'.(CategoriaSocio::query()->whereKey($valore)->value('nome') ?? $valore).'"',
            'sede' => 'Cambio sede in "'.(Sede::query()->whereKey($valore)->value('nome') ?? $valore).'"',
            'tag_assegna' => 'Assegnazione tag "'.(Tag::query()->whereKey($valore)->value('nome_tag') ?? $valore).'"',
            'tag_rimuovi' => 'Rimozione tag "'.(Tag::query()->whereKey($valore)->value('nome_tag') ?? $valore).'"',
            default => $azione,
        };
    }
}
