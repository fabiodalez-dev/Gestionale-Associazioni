<?php

namespace App\Services\Soci;

use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Tessere\TesseraIssuer;

/**
 * Creazione automatica della tessera per l'anno corrente
 * (alla creazione del socio e alla conferma del pagamento — spec soci §1.6).
 *
 * Non genera il PDF (responsabilità del modulo tessere): crea il record
 * tessera tramite TesseraIssuer (numerazione unificata {anno}{NNNN},
 * scadenza solare/annuale, etichetta template e URL di verifica QR firmato).
 */
class TesseraAutomaticaService
{
    public function __construct(
        private readonly TesseraIssuer $issuer,
        private readonly SocioActivityLogger $activityLogger,
    ) {}

    /**
     * Crea la tessera per l'anno corrente se non esiste già per (associazione, socio, anno).
     */
    public function creaPerAnnoCorrente(Socio $socio): ?Tessera
    {
        $anno = (int) now()->year;

        $esiste = Tessera::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('anno_validita', $anno)
            ->exists();

        if ($esiste) {
            return null;
        }

        // Numerazione unificata {anno}{NNNN}, etichetta template e QR firmato
        $tessera = $this->issuer->emetti($socio, ['anno_validita' => $anno]);

        $this->activityLogger->log(
            $socio,
            SocioActivityLogger::TIPO_GENERAZIONE_TESSERA,
            "Generata tessera n. {$tessera->numero_tessera} per l'anno {$anno}.",
            [
                'tessera_id' => $tessera->id,
                'numero_tessera' => $tessera->numero_tessera,
                'anno_validita' => $anno,
            ]
        );

        return $tessera;
    }
}
