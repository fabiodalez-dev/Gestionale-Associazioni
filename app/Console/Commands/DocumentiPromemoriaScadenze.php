<?php

namespace App\Console\Commands;

use App\Models\DocumentoSocio;
use App\Models\PromemoriaInviato;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\AnagraficaPro\DocumentiInScadenzaNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Promemoria documenti socio in scadenza (f.23 PIANO-VITA-SOCIALE):
 * per ogni socio con documenti la cui finestra promemoria è aperta
 * (oggi >= data_scadenza - 30 giorni) invia UNA notifica digest agli
 * admin del tenant (database+mail) e al socio se ha email (solo mail).
 *
 * Anti-duplicazione via tabella promemoria_inviati (tipo documento_scadenza,
 * tessera_id NULL, step = giorni preavviso): un documento è considerato già
 * notificato se esiste un promemoria del socio inviato DOPO sia l'apertura
 * della sua finestra sia il caricamento del documento (il digest inviato in
 * quel momento lo conteneva di sicuro).
 *
 * NB: comando NON registrato nello scheduler (lo farà la fase Rifiniture).
 */
class DocumentiPromemoriaScadenze extends Command
{
    protected $signature = 'documenti:promemoria-scadenze';

    protected $description = 'Invia i promemoria per i documenti socio in scadenza (digest per socio, dedup su promemoria_inviati)';

    public function handle(): int
    {
        $oggi = Carbon::today();
        $giorni = DocumentoSocio::GIORNI_PREAVVISO_SCADENZA;
        $inviati = 0;

        DocumentoSocio::allTenants()
            ->whereNotNull('data_scadenza')
            // finestra aperta: data_scadenza <= oggi + giorni preavviso (inclusi gli scaduti)
            ->whereDate('data_scadenza', '<=', $oggi->copy()->addDays($giorni))
            ->with('socio')
            ->orderBy('data_scadenza')
            ->get()
            ->filter(fn (DocumentoSocio $d) => $d->socio !== null)
            ->groupBy('socio_id')
            ->each(function ($documenti) use (&$inviati, $oggi, $giorni) {
                /** @var Socio $socio */
                $socio = $documenti->first()->socio;

                $daNotificare = $documenti->filter(
                    fn (DocumentoSocio $d) => ! $this->giaNotificato($d, $oggi, $giorni)
                )->values();

                if ($daNotificare->isEmpty()) {
                    return;
                }

                $admins = Utente::query()
                    ->where('associazione_id', $socio->associazione_id)
                    ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                    ->where('attivo', true)
                    ->get();

                $notifica = new DocumentiInScadenzaNotification($socio, $daNotificare);

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, $notifica);
                }

                // Promemoria anche al socio, se raggiungibile via email (f.23)
                if (filled($socio->email) && ! $socio->email_opt_out) {
                    $socio->notify($notifica);
                }

                PromemoriaInviato::create([
                    'associazione_id' => $socio->associazione_id,
                    'socio_id' => $socio->id,
                    'tessera_id' => null,
                    'tipo' => PromemoriaInviato::TIPO_DOCUMENTO_SCADENZA,
                    'step' => $giorni,
                    'inviato_at' => now(),
                ]);

                $inviati++;
            });

        $this->info("Promemoria documenti inviati: {$inviati} (digest per socio)");

        return self::SUCCESS;
    }

    /**
     * Un documento è già stato notificato se esiste un promemoria
     * documento_scadenza del socio inviato dopo l'apertura della finestra
     * del documento E dopo il suo caricamento.
     */
    private function giaNotificato(DocumentoSocio $documento, Carbon $oggi, int $giorni): bool
    {
        $aperturaFinestra = $documento->data_scadenza->copy()->startOfDay()->subDays($giorni);
        $soglia = $documento->created_at !== null && $documento->created_at->gt($aperturaFinestra)
            ? $documento->created_at
            : $aperturaFinestra;

        return PromemoriaInviato::allTenants()
            ->where('socio_id', $documento->socio_id)
            ->where('tipo', PromemoriaInviato::TIPO_DOCUMENTO_SCADENZA)
            ->where('step', $giorni)
            ->whereNull('tessera_id')
            ->where('inviato_at', '>=', $soglia)
            ->exists();
    }
}
