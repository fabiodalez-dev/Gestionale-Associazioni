<?php

namespace App\Services\Soci;

use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Support\Facades\Route;

/**
 * Creazione automatica della tessera per l'anno corrente
 * (alla creazione del socio e alla conferma del pagamento — spec soci §1.6).
 *
 * Non genera il PDF (responsabilità del modulo tessere): crea il record
 * tessera con numero progressivo, scadenza solare/annuale e URL di verifica QR.
 */
class TesseraAutomaticaService
{
    public function __construct(
        private readonly NumeroProgressivoService $progressivi,
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

        $associazione = $socio->associazione;
        $tipoScadenza = $associazione?->tesseramento()->tipo_scadenza_default ?? Tessera::TIPO_SCADENZA_SOLARE;

        if (! in_array($tipoScadenza, [Tessera::TIPO_SCADENZA_SOLARE, Tessera::TIPO_SCADENZA_ANNUALE], true)) {
            $tipoScadenza = Tessera::TIPO_SCADENZA_SOLARE;
        }

        $dataEmissione = now()->startOfDay();
        $dataScadenza = $tipoScadenza === Tessera::TIPO_SCADENZA_ANNUALE
            ? $dataEmissione->copy()->addYear()
            : $dataEmissione->copy()->endOfYear()->startOfDay();

        $tessera = new Tessera([
            'socio_id' => $socio->id,
            'numero_tessera' => $this->progressivi->prossimoNumeroTessera($socio->associazione_id),
            'anno_validita' => $anno,
            'data_emissione' => $dataEmissione->toDateString(),
            'data_scadenza' => $dataScadenza->toDateString(),
            'tipo_scadenza' => $tipoScadenza,
            'stato' => Tessera::STATO_ATTIVA,
        ]);
        $tessera->associazione_id = $socio->associazione_id;
        $tessera->save();

        // URL pubblico di verifica per il QR code (rotta del modulo tessere se presente)
        $tessera->qr_code_url = Route::has('tessere.verifica')
            ? route('tessere.verifica', $tessera->id)
            : url('/tessere/verifica/'.$tessera->id);
        $tessera->save();

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
