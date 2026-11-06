<?php

namespace App\Services\Tessere;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Vincolo "una sola tessera non annullata per socio e anno" (issue #21,
 * indice uq_tessere_socio_anno su socio_id + anno_non_annullata).
 *
 * La migration 2026_11_05_120100 non aggiunge l'indice se trova tessere
 * doppie già presenti (dati reali, non vengono toccati). Questo servizio lo
 * aggiunge più tardi, senza terminale, appena i doppioni sono stati sistemati
 * dal backoffice: dopo il salvataggio o l'eliminazione di una tessera, ogni
 * notte (tessere:applica-vincolo-anno) e dalla pagina Tessere, che intanto
 * elenca i doppioni da sistemare.
 */
class VincoloTessereAnnoService
{
    public const INDICE = 'uq_tessere_socio_anno';

    /** Indice già visto in questo processo: evita di ricontrollarlo a ogni salvataggio. */
    private static bool $attivoInCache = false;

    /**
     * Vero se l'indice unique esiste.
     */
    public function attivo(): bool
    {
        if (self::$attivoInCache) {
            return true;
        }

        if (! Schema::hasColumn('tessere', 'anno_non_annullata')) {
            return false;
        }

        return self::$attivoInCache = Schema::hasIndex('tessere', self::INDICE);
    }

    /**
     * Coppie socio/anno con più tessere non annullate, con i numeri tessera
     * e il nominativo del socio; facoltativamente di una sola associazione.
     *
     * @return Collection<int, object{socio_id: string, associazione_id: string, anno: int, totale: int, numeri: string, nominativo: string}>
     */
    public function doppioni(?string $associazioneId = null): Collection
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        return DB::table('tessere')
            ->join('soci', 'soci.id', '=', 'tessere.socio_id')
            ->where('tessere.stato', '<>', 'Annullata')
            ->when($associazioneId !== null, fn ($q) => $q->where('tessere.associazione_id', $associazioneId))
            ->groupBy('tessere.socio_id', 'tessere.associazione_id', 'tessere.anno_validita', 'soci.cognome', 'soci.nome')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->orderBy('tessere.anno_validita')
            ->select([
                'tessere.socio_id',
                'tessere.associazione_id',
                'tessere.anno_validita as anno',
                DB::raw('COUNT(*) as totale'),
                DB::raw($sqlite
                    ? "GROUP_CONCAT(tessere.numero_tessera, ', ') as numeri"
                    : "GROUP_CONCAT(tessere.numero_tessera ORDER BY tessere.numero_tessera SEPARATOR ', ') as numeri"),
                // || su MariaDB è un OR logico: CONCAT dove c'è
                DB::raw($sqlite
                    ? "TRIM(COALESCE(soci.cognome, '') || ' ' || COALESCE(soci.nome, '')) as nominativo"
                    : "TRIM(CONCAT(COALESCE(soci.cognome, ''), ' ', COALESCE(soci.nome, ''))) as nominativo"),
            ])
            ->get()
            ->map(function (object $riga): object {
                $riga->anno = (int) $riga->anno;
                $riga->totale = (int) $riga->totale;

                return $riga;
            });
    }

    /**
     * Aggiunge l'indice se non ci sono più doppioni. Allinea prima la colonna
     * del vincolo (stesso calcolo del modello Tessera), perché le tessere
     * scritte fuori da Eloquent (import legacy) possono averla vuota.
     * Ritorna true se a fine chiamata l'indice esiste. Non lancia eccezioni:
     * un errore viene registrato nel log e ritenta alla prossima occasione.
     */
    public function applicaSePossibile(): bool
    {
        if ($this->attivo()) {
            return true;
        }

        if (! Schema::hasColumn('tessere', 'anno_non_annullata')) {
            return false;
        }

        try {
            if ($this->doppioni()->isNotEmpty()) {
                return false;
            }

            DB::table('tessere')->where('stato', '<>', 'Annullata')->update(['anno_non_annullata' => DB::raw('anno_validita')]);
            DB::table('tessere')->where('stato', 'Annullata')->update(['anno_non_annullata' => null]);

            Schema::table('tessere', function (Blueprint $table) {
                $table->unique(['socio_id', 'anno_non_annullata'], self::INDICE);
            });
        } catch (QueryException $e) {
            // Un doppione creato nel frattempo, o un altro processo che ha
            // appena aggiunto l'indice: si riprova alla prossima occasione
            Log::warning('Vincolo tessere socio/anno non applicato', ['error' => $e->getMessage()]);

            return $this->attivoSenzaCache();
        }

        Log::info('Vincolo tessere socio/anno applicato: nessuna tessera doppia rimasta.');
        self::$attivoInCache = true;

        return true;
    }

    /**
     * Dimentica l'indice visto in questo processo (test, schema cambiato).
     */
    public static function dimenticaStato(): void
    {
        self::$attivoInCache = false;
    }

    private function attivoSenzaCache(): bool
    {
        self::$attivoInCache = false;

        return $this->attivo();
    }
}
