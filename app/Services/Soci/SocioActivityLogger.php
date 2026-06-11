<?php

namespace App\Services\Soci;

use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Utente;

/**
 * Scrittura fail-safe dello storico attività socio (tabella storico_attivita_socio).
 * Come nel legacy, un errore di log non deve mai interrompere il flusso applicativo.
 */
class SocioActivityLogger
{
    public const TIPO_ISCRIZIONE = 'Iscrizione';

    public const TIPO_MODIFICA_ANAGRAFICA = 'Modifica Anagrafica';

    public const TIPO_APPROVAZIONE = 'Approvazione pre-iscrizione';

    public const TIPO_PAGAMENTO_CONFERMATO = 'Pagamento confermato';

    public const TIPO_GENERAZIONE_TESSERA = 'Generazione Tessera';

    public const TIPO_CARICAMENTO_DOCUMENTO = 'Caricamento Documento';

    public function log(Socio $socio, string $tipo, string $descrizione, ?array $dettagli = null): ?StoricoAttivitaSocio
    {
        try {
            $utente = auth()->user();

            return StoricoAttivitaSocio::create([
                'associazione_id' => $socio->associazione_id,
                'socio_id' => $socio->id,
                'utente_id' => $utente instanceof Utente ? $utente->id : null,
                'tipo_attivita' => $tipo,
                'descrizione' => $descrizione,
                'dettagli_json' => $dettagli,
                'data_attivita' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
