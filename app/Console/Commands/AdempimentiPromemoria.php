<?php

namespace App\Console\Commands;

use App\Models\Adempimento;
use App\Models\PromemoriaInviato;
use App\Models\Utente;
use App\Notifications\Struttura\PromemoriaAdempimentoNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Promemoria mensile degli adempimenti ricorrenti (f.20 del piano
 * "Organizzazione 3"): seleziona gli adempimenti attivi in scadenza nel
 * mese corrente, non ancora completati per l'anno, e invia la notifica
 * brandizzata agli admin del rispettivo tenant.
 *
 * Idempotente: registra ogni invio in promemoria_inviati con chiave
 * (tenant + adempimento + anno/mese, tipo 'adempimento') e salta gli
 * adempimenti già notificati per quel mese/anno, così un re-run (retry,
 * doppio cron, --mese= manuale) non reinvia le email — come TurniPromemoria.
 *
 * Eseguibile manualmente o da scheduler: la registrazione nello scheduler
 * (routes/console.php) è a cura di Rifiniture, NON di questo modulo.
 */
class AdempimentiPromemoria extends Command
{
    protected $signature = 'adempimenti:promemoria {--mese= : Mese 1-12 da controllare (default: mese corrente)}';

    protected $description = 'Invia i promemoria mensili degli adempimenti ricorrenti in scadenza non completati';

    public function handle(): int
    {
        $mese = $this->option('mese') !== null ? (int) $this->option('mese') : (int) now()->format('n');

        if ($mese < 1 || $mese > 12) {
            $this->error('Il mese deve essere compreso tra 1 e 12.');

            return self::FAILURE;
        }

        $anno = (int) now()->format('Y');
        // Chiave di periodo (anno+mese) per la deduplica: es. 202606.
        $step = $anno * 100 + $mese;
        $inviati = 0;

        Adempimento::allTenants()
            ->where('attivo', true)
            ->where('mese_scadenza', $mese)
            ->orderBy('associazione_id')
            ->get()
            ->reject(fn (Adempimento $adempimento) => $adempimento->isCompletatoPerAnno($anno))
            ->reject(fn (Adempimento $adempimento) => $this->giaInviato($adempimento, $step))
            ->each(function (Adempimento $adempimento) use ($anno, $step, &$inviati) {
                $destinatari = Utente::query()
                    ->where('associazione_id', $adempimento->associazione_id)
                    ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                    ->where('attivo', true)
                    ->get();

                if ($destinatari->isEmpty()) {
                    return;
                }

                Notification::send($destinatari, new PromemoriaAdempimentoNotification($adempimento, $anno));

                PromemoriaInviato::create([
                    'associazione_id' => $adempimento->associazione_id,
                    'socio_id' => null,
                    'tessera_id' => null,
                    'riferimento_id' => $adempimento->id,
                    'tipo' => PromemoriaInviato::TIPO_ADEMPIMENTO,
                    'step' => $step,
                    'inviato_at' => now(),
                ]);

                $inviati++;
            });

        $this->info("Promemoria adempimenti inviati: {$inviati}");

        return self::SUCCESS;
    }

    /**
     * Il promemoria per questo adempimento e periodo (anno/mese) è già stato
     * inviato? Dedup applicativa: socio_id/tessera_id NULL non scattano
     * sull'unique a DB.
     */
    private function giaInviato(Adempimento $adempimento, int $step): bool
    {
        return PromemoriaInviato::query()
            ->where('associazione_id', $adempimento->associazione_id)
            ->where('riferimento_id', $adempimento->id)
            ->where('tipo', PromemoriaInviato::TIPO_ADEMPIMENTO)
            ->where('step', $step)
            ->whereNull('socio_id')
            ->whereNull('tessera_id')
            ->exists();
    }
}
