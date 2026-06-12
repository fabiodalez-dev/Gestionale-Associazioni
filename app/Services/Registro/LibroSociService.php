<?php

namespace App\Services\Registro;

use App\Models\RichiestaIscrizione;
use App\Models\Socio;

/**
 * Libro soci ufficiale (f.13 del piano vita sociale): snapshot read-only
 * del registro cronologico dei soci ammessi, con numero progressivo di
 * iscrizione PER ANNO (ordinamento per data di ammissione), delibera di
 * ammissione (f.14, dalla richiesta di iscrizione approvata) e data/motivo
 * di cessazione (f.15, dall'ultima cessazione registrata).
 *
 * Esclude le pre-iscrizioni non ancora ammesse (In Attesa / In Attesa di
 * Pagamento): non fanno ancora parte del libro soci.
 *
 * @phpstan-type RigaLibroSoci array{progressivo: int, anno: int, socio: Socio, delibera_numero: string|null, delibera_data: \Carbon\Carbon|null, cessazione: \App\Models\Cessazione|null}
 */
class LibroSociService
{
    /** Stati esclusi dal libro: non ancora ammessi. */
    public const STATI_ESCLUSI = [
        Socio::STATO_IN_ATTESA,
        Socio::STATO_IN_ATTESA_DI_PAGAMENTO,
    ];

    /**
     * Righe del libro soci (eventualmente filtrate per anno di ammissione).
     * Il progressivo è calcolato SEMPRE sull'intero registro, così la
     * numerazione resta stabile anche con il filtro attivo.
     *
     * @return list<RigaLibroSoci>
     */
    public function righe(?int $anno = null): array
    {
        $soci = Socio::query()
            ->whereNotIn('stato', self::STATI_ESCLUSI)
            ->whereNotNull('data_iscrizione')
            ->with([
                'tipoSocio',
                'cessazioni' => fn ($q) => $q->orderByDesc('data')->orderByDesc('created_at'),
            ])
            ->orderBy('data_iscrizione')
            ->orderBy('created_at')
            ->get();

        // Delibere di ammissione (f.14): dalla richiesta approvata del socio
        $delibere = RichiestaIscrizione::query()
            ->where('stato', RichiestaIscrizione::STATO_APPROVATA)
            ->whereIn('socio_id', $soci->pluck('id'))
            ->whereNotNull('delibera_numero')
            ->orderBy('gestita_at')
            ->get()
            ->keyBy('socio_id');

        /** @var array<int, int> $contatori */
        $contatori = [];

        $righe = $soci->map(function (Socio $socio) use (&$contatori, $delibere): array {
            $annoAmmissione = (int) $socio->data_iscrizione->year;
            $contatori[$annoAmmissione] = (int) ($contatori[$annoAmmissione] ?? 0) + 1;

            /** @var RichiestaIscrizione|null $richiesta */
            $richiesta = $delibere->get($socio->id);

            return [
                'progressivo' => $contatori[$annoAmmissione],
                'anno' => $annoAmmissione,
                'socio' => $socio,
                'delibera_numero' => $richiesta?->delibera_numero,
                'delibera_data' => $richiesta?->delibera_data,
                'cessazione' => $socio->cessazioni->first(),
            ];
        });

        if ($anno !== null) {
            $righe = $righe->where('anno', $anno);
        }

        return array_values($righe->all());
    }

    /**
     * Anni di ammissione presenti nel registro (decrescenti), per il filtro.
     *
     * @return list<int>
     */
    public function anni(): array
    {
        return Socio::query()
            ->whereNotIn('stato', self::STATI_ESCLUSI)
            ->whereNotNull('data_iscrizione')
            ->get(['data_iscrizione'])
            ->map(fn (Socio $socio): int => (int) $socio->data_iscrizione->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }
}
