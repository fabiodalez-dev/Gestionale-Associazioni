<?php

namespace App\Console\Commands;

use App\Models\PromemoriaInviato;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Notifications\VolontariTurni\PromemoriaTurnoNotification;
use Illuminate\Console\Command;

/**
 * Promemoria turno (f.6 del piano "Organizzazione 3"): il giorno prima
 * invia una email brandizzata ai volontari CONFERMATI di ogni turno aperto
 * in programma domani.
 *
 * Anti-duplicazione: un record in promemoria_inviati per (socio, turno,
 * tipo 'turno'), con il turno_id memorizzato direttamente in
 * 'riferimento_id'. La chiave non usa più hash troncati (niente collisioni
 * tra turni diversi). Con tessera_id NULL l'unique a DB non scatta, quindi
 * la deduplica è applicativa (riferimento_id + tipo + socio_id).
 *
 * NON registrato nello scheduler: la pianificazione giornaliera in
 * routes/console.php è demandata alla fase Rifiniture.
 */
class TurniPromemoria extends Command
{
    public const TIPO = PromemoriaInviato::TIPO_TURNO;

    protected $signature = 'turni:promemoria';

    protected $description = 'Invia il promemoria email ai volontari confermati dei turni in programma domani';

    public function handle(): int
    {
        $inviati = 0;
        $domani = today()->addDay();

        $turni = Turno::allTenants()
            ->where('stato', Turno::STATO_APERTO)
            ->whereDate('data', $domani)
            ->where('titolo', 'not like', 'Ore manuali%')
            ->with(['spazio', 'assegnazioni' => fn ($q) => $q->where('stato', TurnoAssegnazione::STATO_CONFERMATO)->with('socio')])
            ->get();

        foreach ($turni as $turno) {
            foreach ($turno->assegnazioni as $assegnazione) {
                $socio = $assegnazione->socio;

                if ($socio === null || blank($socio->email) || $socio->email_opt_out) {
                    continue;
                }

                if ($this->giaInviato($turno, $socio->id)) {
                    continue;
                }

                $socio->notify(new PromemoriaTurnoNotification($turno));

                PromemoriaInviato::create([
                    'associazione_id' => $turno->associazione_id,
                    'socio_id' => $socio->id,
                    'tessera_id' => null,
                    'riferimento_id' => $turno->id,
                    'tipo' => self::TIPO,
                    'step' => 0,
                    'inviato_at' => now(),
                ]);

                $inviati++;
            }
        }

        $this->info("Promemoria turni inviati: {$inviati}");

        return self::SUCCESS;
    }

    private function giaInviato(Turno $turno, string $socioId): bool
    {
        return PromemoriaInviato::query()
            ->where('associazione_id', $turno->associazione_id)
            ->where('socio_id', $socioId)
            ->where('tipo', self::TIPO)
            ->where('riferimento_id', $turno->id)
            ->whereNull('tessera_id')
            ->exists();
    }
}
