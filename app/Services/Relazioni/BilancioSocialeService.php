<?php

namespace App\Services\Relazioni;

use App\Models\Associazione;
use App\Models\BilancioSociale;
use App\Models\Carica;
use App\Models\Evento;
use App\Models\Movimento;
use App\Models\Progetto;
use App\Models\Proposta;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\TurnoAssegnazione;
use App\Models\VotazioneRiunione;

/**
 * Dati del bilancio sociale (issue #15) calcolati dal gestionale per le
 * sezioni delle linee guida: ente, governo e partecipazione, persone,
 * attività, situazione economica, con il confronto con l'anno precedente
 * (dalle Statistiche annuali). Il risultato è salvato come snapshot nel
 * bilancio: rigenerarlo aggiorna i numeri senza toccare i testi.
 */
class BilancioSocialeService
{
    public function __construct(
        private readonly StatisticheAnnualiService $statistiche,
    ) {}

    public function genera(Associazione $associazione, int $anno): BilancioSociale
    {
        $bilancio = BilancioSociale::query()->firstOrNew(
            ['anno' => $anno],
            ['associazione_id' => $associazione->id],
        );

        $bilancio->fill(['dati' => $this->calcola($associazione, $anno), 'generato_at' => now()])->save();

        return $bilancio;
    }

    /**
     * @return array<string, mixed>
     */
    public function calcola(Associazione $associazione, int $anno): array
    {
        $inizio = "{$anno}-01-01";
        $fine = "{$anno}-12-31";
        $confronto = $this->statistiche->confronto($anno);

        return [
            'anno' => $anno,
            'ente' => [
                'nome' => $associazione->nome,
                'codice_fiscale' => $associazione->codice_fiscale,
                'indirizzo' => trim(implode(' ', array_filter([$associazione->indirizzo, $associazione->cap, $associazione->citta, $associazione->provincia ? "({$associazione->provincia})" : null]))),
                'sedi' => Sede::query()->orderBy('nome')->pluck('nome')->all(),
            ],
            'governo' => $this->governo($anno, $inizio, $fine),
            'persone' => $this->persone($anno, $fine, $confronto),
            'attivita' => $this->attivita($anno, $inizio, $fine, $confronto),
            'economia' => $this->economia($anno, $confronto),
            'confronto' => [
                'anno_precedente' => $confronto['anno_precedente'],
                'precedente' => $confronto['precedente'],
                'variazioni' => $confronto['variazioni'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function governo(int $anno, string $inizio, string $fine): array
    {
        $cariche = Carica::query()
            ->whereDate('inizio', '<=', $fine)
            ->where(fn ($q) => $q->whereNull('fine')->orWhereDate('fine', '>=', $fine))
            ->with(['socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
            ->orderBy('ruolo')
            ->get()
            ->map(fn (Carica $c) => ['ruolo' => $c->ruolo, 'nome' => $c->socio?->nome_completo])
            ->values()
            ->all();

        $riunioni = Riunione::query()
            ->whereYear('data_ora', $anno)
            ->where('stato', Riunione::STATO_SVOLTA)
            ->get(['id', 'organo']);

        $assemblee = $riunioni->filter(fn (Riunione $r) => $r->isAssemblea());

        // Partecipazione media alle assemblee: presenti su aventi diritto al voto
        $partecipazione = $assemblee->map(function (Riunione $r) {
            $aventi = RiunionePartecipante::query()->where('riunione_id', $r->id)->where('avente_diritto_voto', true)->count();
            $presenti = RiunionePartecipante::query()->where('riunione_id', $r->id)->where('avente_diritto_voto', true)
                ->where('stato', RiunionePartecipante::STATO_CONFERMATO)->count();

            return $aventi > 0 ? $presenti / $aventi * 100 : null;
        })->filter(fn ($v) => $v !== null);

        return [
            'cariche' => $cariche,
            'riunioni_direttivo' => $riunioni->where('organo', Riunione::ORGANO_DIRETTIVO)->count(),
            'assemblee' => $assemblee->count(),
            'partecipazione_assemblee' => $partecipazione->isEmpty() ? null : round($partecipazione->avg(), 1),
            'decisioni' => VotazioneRiunione::query()->approvate()->whereHas('riunione', fn ($q) => $q->whereYear('data_ora', $anno))->count(),
            'proposte_presentate' => Proposta::query()->whereBetween('created_at', [$inizio.' 00:00:00', $fine.' 23:59:59'])->count(),
            'proposte_accolte' => Proposta::query()->where('stato', Proposta::STATO_ACCOLTA)->whereYear('esito_at', $anno)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $confronto
     * @return array<string, mixed>
     */
    private function persone(int $anno, string $fine, array $confronto): array
    {
        $soci = Socio::query()
            ->withoutGlobalScope('sede')
            ->whereDate('data_iscrizione', '<=', $fine)
            ->whereDoesntHave('cessazioni', fn ($q) => $q->whereDate('data', '<=', $fine));

        $perTipo = (clone $soci)->with('tipoSocio:id,nome')->get(['id', 'tipo_socio_id', 'sede_id'])
            ->groupBy(fn (Socio $s) => $s->tipoSocio->nome ?? 'Senza tipo')
            ->map->count()
            ->sortDesc()
            ->all();

        $volontari = TurnoAssegnazione::query()
            ->whereHas('turno', fn ($q) => $q->whereYear('data', $anno))
            ->whereNotNull('ore_effettive')
            ->distinct()
            ->count('socio_id');

        return [
            'soci_fine_anno' => (clone $soci)->count(),
            'soci_per_tipo' => $perTipo,
            'nuovi_soci' => $confronto['corrente']['nuovi_soci'],
            'cessazioni' => $confronto['corrente']['cessazioni'],
            'volontari' => $volontari,
            'ore_volontariato' => $confronto['corrente']['ore_volontariato'],
        ];
    }

    /**
     * @param  array<string, mixed>  $confronto
     * @return array<string, mixed>
     */
    private function attivita(int $anno, string $inizio, string $fine, array $confronto): array
    {
        $progetti = Progetto::query()
            ->whereDate('dal', '<=', $fine)
            ->where(fn ($q) => $q->whereNull('al')->orWhereDate('al', '>=', $inizio))
            ->withCount(['milestones', 'milestones as milestones_completate_count' => fn ($q) => $q->whereNotNull('completata_at')])
            ->orderBy('nome')
            ->get()
            ->map(fn (Progetto $p) => ['nome' => $p->nome, 'stato' => $p->stato, 'avanzamento' => $p->percentualeAvanzamento()])
            ->values()
            ->all();

        return [
            'eventi' => Evento::query()->whereYear('data_evento', $anno)->where('stato_pubblicazione', '!=', Evento::STATO_BOZZA)->count(),
            'partecipazioni' => $confronto['corrente']['partecipazioni'],
            'progetti' => $progetti,
        ];
    }

    /**
     * @param  array<string, mixed>  $confronto
     * @return array<string, mixed>
     */
    private function economia(int $anno, array $confronto): array
    {
        $perCategoria = fn (string $tipo) => Movimento::query()
            ->where('tipo', $tipo)
            ->whereYear('data', $anno)
            ->with('categoria:id,nome')
            ->get(['id', 'categoria_id', 'importo'])
            ->groupBy(fn (Movimento $m) => $m->categoria->nome ?? 'Senza categoria')
            ->map(fn ($movimenti) => round((float) $movimenti->sum('importo'), 2))
            ->sortDesc()
            ->all();

        return [
            'entrate' => $confronto['corrente']['entrate'],
            'uscite' => $confronto['corrente']['uscite'],
            'saldo' => $confronto['corrente']['saldo'],
            'donazioni' => $confronto['corrente']['donazioni'],
            'entrate_per_categoria' => $perCategoria(Movimento::TIPO_ENTRATA),
            'uscite_per_categoria' => $perCategoria(Movimento::TIPO_USCITA),
        ];
    }
}
