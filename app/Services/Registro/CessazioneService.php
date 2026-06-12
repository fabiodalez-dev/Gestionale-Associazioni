<?php

namespace App\Services\Registro;

use App\Models\Cessazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\Soci\SocioActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Workflow delle cessazioni formali (f.15 del piano vita sociale).
 *
 * registra(): in transazione
 *  - scrive la riga nel registro cessazioni (tipo, data, motivo, delibera);
 *  - porta il socio allo stato coerente (Cessazione::STATO_SOCIO_PER_TIPO);
 *  - annulla le tessere ancora attive del socio;
 * poi (fail-safe, fuori transazione) traccia l'operazione nello storico
 * attività del socio. La cessazione alimenta il libro soci (f.13).
 */
class CessazioneService
{
    public const TIPO_ATTIVITA_STORICO = 'Cessazione';

    /**
     * Stati da cui è ammessa una cessazione formale: il socio non deve
     * essere una pre-iscrizione né già cessato.
     */
    public const STATI_AMMESSI = [
        Socio::STATO_ATTIVO,
        Socio::STATO_SOSPESO,
        Socio::STATO_TRASFERITO,
    ];

    public function __construct(private readonly SocioActivityLogger $activityLogger) {}

    /**
     * @param  array{tipo: string, data: string, motivo?: string|null, delibera_numero?: string|null, delibera_data?: string|null}  $dati
     *
     * @throws CessazioneNonAmmessaException
     */
    public function registra(Socio $socio, Utente $utente, array $dati): Cessazione
    {
        if (! in_array($socio->stato, self::STATI_AMMESSI, true)) {
            throw CessazioneNonAmmessaException::perStato($socio);
        }

        $nuovoStato = Cessazione::STATO_SOCIO_PER_TIPO[$dati['tipo']];

        $cessazione = DB::transaction(function () use ($socio, $utente, $dati, $nuovoStato): Cessazione {
            $cessazione = Cessazione::create([
                'associazione_id' => $socio->associazione_id,
                'socio_id' => $socio->id,
                'tipo' => $dati['tipo'],
                'data' => $dati['data'],
                'motivo' => $dati['motivo'] ?? null,
                'delibera_numero' => $dati['delibera_numero'] ?? null,
                'delibera_data' => $dati['delibera_data'] ?? null,
                'registrata_da' => $utente->id,
            ]);

            // Tessere attive → annullate (f.15)
            $socio->tessere()
                ->where('stato', Tessera::STATO_ATTIVA)
                ->update(['stato' => Tessera::STATO_ANNULLATA]);

            // Stato socio coerente con il tipo di cessazione
            $socio->forceFill(['stato' => $nuovoStato])->save();

            return $cessazione;
        });

        $this->activityLogger->log(
            $socio,
            self::TIPO_ATTIVITA_STORICO,
            sprintf(
                'Cessazione per %s registrata con decorrenza %s%s. Stato aggiornato a "%s", tessere attive annullate.',
                $dati['tipo'],
                Carbon::parse($dati['data'])->format('d/m/Y'),
                filled($dati['delibera_numero'] ?? null)
                    ? sprintf(' (delibera n. %s%s)', $dati['delibera_numero'], filled($dati['delibera_data'] ?? null) ? ' del '.Carbon::parse($dati['delibera_data'])->format('d/m/Y') : '')
                    : '',
                $nuovoStato
            ),
            [
                'cessazione_id' => $cessazione->id,
                'tipo' => $dati['tipo'],
                'motivo' => $dati['motivo'] ?? null,
            ]
        );

        return $cessazione;
    }
}
