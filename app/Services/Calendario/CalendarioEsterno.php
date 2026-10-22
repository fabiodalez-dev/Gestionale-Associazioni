<?php

namespace App\Services\Calendario;

use App\Settings\GoogleCalendarSettings;
use Carbon\CarbonInterface;

/**
 * Calendario esterno su cui pubblicare gli eventi dell'associazione.
 * Implementazione reale: GoogleCalendarSpatie; nei test un finto client.
 */
interface CalendarioEsterno
{
    /**
     * Crea o aggiorna l'evento e ne restituisce l'id sul calendario esterno.
     *
     * @param  array{titolo: string, descrizione: string, luogo: ?string, inizio: CarbonInterface, fine: CarbonInterface}  $dati
     */
    public function salva(GoogleCalendarSettings $impostazioni, array $dati, ?string $idEsterno): string;

    /** Elimina l'evento; nessun errore se non esiste più. */
    public function elimina(GoogleCalendarSettings $impostazioni, string $idEsterno): void;

    /** Verifica credenziali e calendario; restituisce il nome del calendario. */
    public function verifica(GoogleCalendarSettings $impostazioni): string;
}
