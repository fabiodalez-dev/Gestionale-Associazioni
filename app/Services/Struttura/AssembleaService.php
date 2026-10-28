<?php

namespace App\Services\Struttura;

use App\Models\DelegaRiunione;
use App\Models\Quota;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\VotazioneRiunione;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Assemblea dei soci (issue #6): elenco degli aventi diritto, quorum
 * costitutivo per prima e seconda convocazione ed esito delle votazioni
 * calcolato dalla maggioranza richiesta dal punto dell'OdG.
 *
 * L'elenco è uno snapshot: viene salvato come partecipanti della riunione,
 * così un socio che paga la quota dopo il calcolo non cambia i numeri finché
 * la segreteria non aggiorna l'elenco.
 *
 * Le deleghe valide (issue #7: delegato presente, delegante assente) contano
 * come presenze sia per il quorum sia come voti esprimibili.
 */
class AssembleaService
{
    /**
     * Calcola (o aggiorna) l'elenco degli aventi diritto: soci Attivi e,
     * se richiesto, con la quota dell'anno della riunione pagata. I minorenni
     * alla data della riunione partecipano senza voto se la riunione lo
     * prevede. All'aggiornamento entrano i nuovi aventi diritto ed escono
     * quelli che non lo sono più, purché la presenza non sia già registrata.
     *
     * @return array{aggiunti: int, rimossi: int, totale: int}
     */
    public function generaAventiDiritto(Riunione $riunione): array
    {
        $dataRiferimento = $riunione->data_ora;

        // Tutti i soci dell'associazione, anche fuori dal perimetro di sede
        // dell'operatore: l'assemblea è dell'intera associazione
        $soci = Socio::query()
            ->withoutGlobalScope('sede')
            ->where('associazione_id', $riunione->associazione_id)
            ->where('stato', Socio::STATO_ATTIVO)
            ->when($riunione->requisito_quota_pagata, fn ($q) => $q->whereHas(
                'quote',
                fn ($quote) => $quote->withoutGlobalScopes()
                    ->where('anno', (int) $dataRiferimento->year)
                    ->where('stato', Quota::STATO_PAGATA)
            ))
            ->get(['id', 'data_nascita'])
            ->mapWithKeys(fn (Socio $socio) => [
                $socio->id => ! ($riunione->escludi_minorenni_voto && $socio->isMinorenne($dataRiferimento)),
            ]);

        return DB::transaction(function () use ($riunione, $soci): array {
            $esistenti = RiunionePartecipante::query()
                ->where('riunione_id', $riunione->id)
                ->whereNotNull('socio_id')
                ->get(['id', 'socio_id', 'stato', 'avente_diritto_voto']);

            $daRimuovere = $esistenti
                ->reject(fn (RiunionePartecipante $p) => $soci->has($p->socio_id))
                ->filter(fn (RiunionePartecipante $p) => $p->stato === RiunionePartecipante::STATO_INVITATO)
                ->pluck('id');

            if ($daRimuovere->isNotEmpty()) {
                $sociRimossi = $esistenti->whereIn('id', $daRimuovere)->pluck('socio_id');

                // Chi esce dall'elenco perde le deleghe date e ricevute
                DelegaRiunione::query()
                    ->where('riunione_id', $riunione->id)
                    ->where(fn ($q) => $q->whereIn('delegante_socio_id', $sociRimossi)
                        ->orWhereIn('delegato_socio_id', $sociRimossi))
                    ->delete();

                RiunionePartecipante::query()->whereKey($daRimuovere)->delete();
            }

            // Il diritto di voto segue l'età alla data della riunione anche
            // per chi era già in elenco
            foreach ($esistenti as $partecipante) {
                $voto = $soci->get($partecipante->socio_id);

                if ($voto !== null && $voto !== $partecipante->avente_diritto_voto) {
                    $partecipante->update(['avente_diritto_voto' => $voto]);
                }
            }

            $giaPresenti = $esistenti->pluck('socio_id')->flip();
            $ora = now();
            $nuovi = $soci
                ->reject(fn (bool $voto, string $socioId) => $giaPresenti->has($socioId))
                ->map(fn (bool $voto, string $socioId) => [
                    'id' => (string) Str::uuid(),
                    'associazione_id' => $riunione->associazione_id,
                    'riunione_id' => $riunione->id,
                    'socio_id' => $socioId,
                    'carica_ruolo' => null,
                    'avente_diritto_voto' => $voto,
                    'stato' => RiunionePartecipante::STATO_INVITATO,
                    'created_at' => $ora,
                    'updated_at' => $ora,
                ])
                ->values();

            foreach ($nuovi->chunk(500) as $blocco) {
                RiunionePartecipante::query()->insert($blocco->all());
            }

            $riunione->forceFill(['aventi_diritto_at' => $ora])->save();

            return [
                'aggiunti' => $nuovi->count(),
                'rimossi' => $daRimuovere->count(),
                'totale' => RiunionePartecipante::query()->where('riunione_id', $riunione->id)->count(),
            ];
        });
    }

    /**
     * Stato del quorum costitutivo: aventi diritto al voto, presenti con
     * diritto di voto (di persona più deleghe valide) e, per ogni
     * convocazione, regola, minimo e raggiungimento.
     *
     * @return array{
     *     aventi_diritto: int,
     *     presenti: int,
     *     presenti_in_persona: int,
     *     deleghe: int,
     *     presenti_totali: int,
     *     prima: array{regola: string, etichetta: string, minimo: int, raggiunto: bool, mancano: int},
     *     seconda: array{regola: string, etichetta: string, minimo: int, raggiunto: bool, mancano: int, iniziata: bool}|null,
     *     costituita_in: 'prima'|'seconda'|null
     * }
     */
    public function quorum(Riunione $riunione): array
    {
        $conteggi = RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->selectRaw('SUM(CASE WHEN avente_diritto_voto = 1 THEN 1 ELSE 0 END) AS aventi')
            ->selectRaw('SUM(CASE WHEN avente_diritto_voto = 1 AND stato = ? THEN 1 ELSE 0 END) AS presenti', [RiunionePartecipante::STATO_CONFERMATO])
            ->selectRaw('SUM(CASE WHEN stato = ? THEN 1 ELSE 0 END) AS presenti_totali', [RiunionePartecipante::STATO_CONFERMATO])
            ->first();

        $aventi = (int) ($conteggi->aventi ?? 0);
        $inPersona = (int) ($conteggi->presenti ?? 0);
        $deleghe = DelegheService::queryValide($riunione)->count();
        $presenti = $inPersona + $deleghe;

        $prima = $this->statoConvocazione((string) $riunione->regolaQuorum(), $aventi, $presenti);
        $seconda = $riunione->seconda_convocazione_at !== null
            ? $this->statoConvocazione((string) $riunione->regolaQuorum(seconda: true), $aventi, $presenti)
                // Il quorum ridotto vale solo dall'ora della seconda convocazione:
                // prima di allora una prima convocazione andata deserta non
                // costituisce l'assemblea
                + ['iniziata' => now()->greaterThanOrEqualTo($riunione->seconda_convocazione_at)]
            : null;

        return [
            'aventi_diritto' => $aventi,
            'presenti' => $presenti,
            'presenti_in_persona' => $inPersona,
            'deleghe' => $deleghe,
            'presenti_totali' => (int) ($conteggi->presenti_totali ?? 0),
            'prima' => $prima,
            'seconda' => $seconda,
            'costituita_in' => match (true) {
                $prima['raggiunto'] => 'prima',
                $seconda !== null && $seconda['iniziata'] && $seconda['raggiunto'] => 'seconda',
                default => null,
            },
        ];
    }

    /**
     * Partecipante dell'assemblea dal codice letto: QR della tessera (URL di
     * verifica o id), numero tessera dell'anno più recente o numero socio.
     */
    public function partecipanteDaCodice(Riunione $riunione, string $codice): ?RiunionePartecipante
    {
        $socioId = $this->socioIdDaCodice($codice);

        if ($socioId === null) {
            return null;
        }

        return RiunionePartecipante::query()
            ->with(['socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
            ->where('riunione_id', $riunione->id)
            ->where('socio_id', $socioId)
            ->first();
    }

    /**
     * Socio dell'associazione corrente identificato da QR della tessera,
     * numero tessera o numero socio.
     */
    public function socioIdDaCodice(string $codice): ?string
    {
        $codice = trim($codice);

        if ($codice === '') {
            return null;
        }

        $socioId = null;

        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $codice, $uuid) === 1) {
            $socioId = Tessera::withoutGlobalScope('sede')
                ->whereKey(strtolower($uuid[0]))
                ->value('socio_id');
        }

        $socioId ??= Tessera::withoutGlobalScope('sede')
            ->where('numero_tessera', $codice)
            ->orderByDesc('anno_validita')
            ->value('socio_id');

        return $socioId ?? Socio::query()
            ->withoutGlobalScope('sede')
            ->where('numero_socio', $codice)
            ->value('id');
    }

    /**
     * Presenti minimi richiesti dalla regola su un totale di aventi diritto.
     */
    public function minimoPresenti(string $regola, int $aventiDiritto): int
    {
        return match ($regola) {
            'un_terzo' => (int) ceil($aventiDiritto / 3),
            'maggioranza_assoluta' => intdiv($aventiDiritto, 2) + 1,
            'due_terzi' => (int) ceil($aventiDiritto * 2 / 3),
            'tre_quarti' => (int) ceil($aventiDiritto * 3 / 4),
            default => 1,
        };
    }

    /**
     * Esito di una votazione secondo la maggioranza richiesta, sui presenti
     * con diritto di voto:
     * - semplice: favorevoli più dei contrari;
     * - assoluta: favorevoli più della metà dei presenti;
     * - due terzi: favorevoli almeno i due terzi dei presenti.
     */
    public function esito(string $maggioranza, int $favorevoli, int $contrari, int $presenti): string
    {
        $approvato = match ($maggioranza) {
            'assoluta' => $favorevoli * 2 > $presenti,
            'due_terzi' => $presenti > 0 && $favorevoli * 3 >= $presenti * 2,
            default => $favorevoli > $contrari,
        };

        return $approvato ? VotazioneRiunione::ESITO_APPROVATO : VotazioneRiunione::ESITO_RESPINTO;
    }

    /**
     * @return array{regola: string, etichetta: string, minimo: int, raggiunto: bool, mancano: int}
     */
    private function statoConvocazione(string $regola, int $aventi, int $presenti): array
    {
        $minimo = $this->minimoPresenti($regola, $aventi);

        return [
            'regola' => $regola,
            'etichetta' => Riunione::QUORUM[$regola] ?? $regola,
            'minimo' => $minimo,
            'raggiunto' => $aventi > 0 && $presenti >= $minimo,
            'mancano' => max(0, $minimo - $presenti),
        ];
    }
}
