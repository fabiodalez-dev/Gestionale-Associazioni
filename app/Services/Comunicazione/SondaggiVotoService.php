<?php

namespace App\Services\Comunicazione;

use App\Models\Account;
use App\Models\RispostaSondaggio;
use App\Models\Socio;
use App\Models\Sondaggio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Voto ai sondaggi (f.11 piano Gestione 2) dalle aree riservate.
 *
 * - votante polimorfico: Socio (area soci) o Account (frontend pubblico);
 * - un account COLLEGATO a un socio vota come quel Socio, così lo stesso
 *   tesserato non può votare due volte passando da aree diverse;
 * - un solo voto per (sondaggio, votante): check applicativo + unique a DB
 *   come rete di sicurezza contro i submit concorrenti.
 */
class SondaggiVotoService
{
    public const ESITO_OK = 'ok';

    public const ESITO_CHIUSO = 'chiuso';

    public const ESITO_OPZIONE_NON_VALIDA = 'opzione_non_valida';

    public const ESITO_GIA_VOTATO = 'gia_votato';

    public const ESITO_NON_AMMESSO = 'non_ammesso';

    /**
     * Registra il voto. Ritorna uno degli ESITO_*.
     */
    public function vota(Sondaggio $sondaggio, Model $votante, int $opzione): string
    {
        $votante = $this->risolviVotante($votante);

        // I sondaggi solo-soci non sono votabili dagli account non collegati
        if ($sondaggio->solo_soci && ! $votante instanceof Socio) {
            return self::ESITO_NON_AMMESSO;
        }

        if (! $sondaggio->isAperto()) {
            return self::ESITO_CHIUSO;
        }

        if (! array_key_exists($opzione, array_values((array) $sondaggio->opzioni))) {
            return self::ESITO_OPZIONE_NON_VALIDA;
        }

        if ($this->haVotato($sondaggio, $votante)) {
            return self::ESITO_GIA_VOTATO;
        }

        try {
            RispostaSondaggio::create([
                'associazione_id' => $sondaggio->associazione_id,
                'sondaggio_id' => $sondaggio->id,
                'votante_type' => $votante->getMorphClass(),
                'votante_id' => $votante->getKey(),
                'opzione' => $opzione,
            ]);
        } catch (QueryException) {
            // unique (sondaggio, votante) violato da un submit concorrente
            return self::ESITO_GIA_VOTATO;
        }

        return self::ESITO_OK;
    }

    /**
     * Il votante ha già espresso il proprio voto?
     */
    public function haVotato(Sondaggio $sondaggio, Model $votante): bool
    {
        $votante = $this->risolviVotante($votante);

        return $this->rispostaDi($sondaggio, $votante) !== null;
    }

    /**
     * La risposta già registrata dal votante, se esiste.
     */
    public function rispostaDi(Sondaggio $sondaggio, Model $votante): ?RispostaSondaggio
    {
        $votante = $this->risolviVotante($votante);

        return RispostaSondaggio::withoutGlobalScopes()
            ->where('sondaggio_id', $sondaggio->id)
            ->where('votante_type', $votante->getMorphClass())
            ->where('votante_id', $votante->getKey())
            ->first();
    }

    /**
     * Account collegato a un socio → vota come Socio (identità unica).
     */
    private function risolviVotante(Model $votante): Model
    {
        if ($votante instanceof Account && $votante->socio_id !== null) {
            $socio = Socio::withoutGlobalScopes()->find($votante->socio_id);

            if ($socio !== null) {
                return $socio;
            }
        }

        return $votante;
    }
}
