<?php

namespace App\Services\Comunicazione;

use App\Models\Associazione;
use App\Models\PromemoriaInviato;
use App\Models\Socio;
use App\Notifications\Comunicazione\AuguriCompleannoNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Compleanni dei soci (f.9 piano Gestione 2): elenco del mese per il
 * widget dashboard e invio degli auguri automatici nel giorno del
 * compleanno (solo tenant con auguri_automatici attivo).
 *
 * Anti-duplicazione in promemoria_inviati con tipo 'compleanno' e
 * step = anno corrente (tessera_id NULL → dedup applicativa, come
 * documentato sul model PromemoriaInviato).
 */
class CompleanniService
{
    /** Tipo riga in promemoria_inviati (la colonna è una stringa libera). */
    public const TIPO_COMPLEANNO = 'compleanno';

    /**
     * Soci attivi del tenant che compiono gli anni nel mese corrente,
     * ordinati per giorno del mese. Chiamato dalla vista
     * comunicazione/dashboard/_compleanni.
     *
     * @api
     *
     * @return Collection<int, Socio>
     */
    public function compleanniDelMese(string $associazioneId): Collection
    {
        return Socio::forAssociazione($associazioneId)
            ->where('stato', Socio::STATO_ATTIVO)
            ->whereNotNull('data_nascita')
            ->whereMonth('data_nascita', now()->month)
            ->get()
            ->sortBy(fn (Socio $socio) => (int) $socio->data_nascita?->format('d'))
            ->values();
    }

    /**
     * Invia gli auguri ai soci attivi che compiono gli anni OGGI
     * (email presente, no opt-out, non già inviati quest'anno).
     * Ritorna il numero di auguri inviati.
     */
    public function inviaAuguri(Associazione $associazione): int
    {
        if (! $associazione->auguri_automatici) {
            return 0;
        }

        $anno = (int) now()->format('Y');
        $inviati = 0;

        Socio::allTenants()
            ->where('soci.associazione_id', $associazione->id)
            ->where('soci.stato', Socio::STATO_ATTIVO)
            ->whereNotNull('soci.data_nascita')
            ->whereMonth('soci.data_nascita', now()->month)
            ->whereDay('soci.data_nascita', now()->day)
            ->whereNotNull('soci.email')
            ->where('soci.email', '!=', '')
            ->where(function (Builder $q) {
                $q->where('soci.email_opt_out', false)->orWhereNull('soci.email_opt_out');
            })
            ->orderBy('soci.id')
            ->chunkById(200, function ($soci) use ($associazione, $anno, &$inviati): void {
                foreach ($soci as $socio) {
                    if ($this->invia($associazione, $socio, $anno)) {
                        $inviati++;
                    }
                }
            }, 'soci.id', 'id');

        return $inviati;
    }

    /**
     * Auguri al singolo socio, una sola volta per anno (dedup applicativa
     * su promemoria_inviati: tipo 'compleanno', step = anno).
     */
    private function invia(Associazione $associazione, Socio $socio, int $anno): bool
    {
        $giaInviato = PromemoriaInviato::allTenants()
            ->where('socio_id', $socio->id)
            ->whereNull('tessera_id')
            ->where('tipo', self::TIPO_COMPLEANNO)
            ->where('step', $anno)
            ->exists();

        if ($giaInviato) {
            return false;
        }

        PromemoriaInviato::create([
            'associazione_id' => $associazione->id,
            'socio_id' => $socio->id,
            'tessera_id' => null,
            'tipo' => self::TIPO_COMPLEANNO,
            'step' => $anno,
            'inviato_at' => now(),
        ]);

        try {
            $socio->notify(new AuguriCompleannoNotification($associazione));
        } catch (\Throwable $e) {
            Log::warning('CompleanniService: invio auguri fallito', [
                'socio_id' => $socio->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
