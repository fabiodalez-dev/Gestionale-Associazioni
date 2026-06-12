<?php

namespace App\Services\Economia;

use App\Models\Donazione;
use App\Notifications\Economia\RicevutaDonazioneNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Donazioni online (f.15 del piano "Gestione 2"): post-pagamento dal
 * webhook Stripe/PayPal con dispatch tipo 'donazione'. Idempotente sul
 * replay (già pagata o transazione registrata → ack senza effetti);
 * la donazione pagata genera il movimento automatico in prima nota e
 * la ricevuta email al donatore (entrambi best-effort).
 */
class DonazioneService
{
    public function __construct(protected PrimaNotaService $primaNota) {}

    /**
     * Marca la donazione come pagata (webhook). Ack SEMPRE al gateway
     * (true) anche per donazione inesistente: il retry non servirebbe.
     */
    public function onPagamentoCompletato(string $donazioneId, string $associazioneId, string $gateway, string $transactionId): bool
    {
        $pagataOra = DB::transaction(function () use ($donazioneId, $associazioneId, $gateway, $transactionId): bool {
            /** @var Donazione|null $donazione */
            $donazione = Donazione::allTenants()
                ->where('id', $donazioneId)
                ->where('associazione_id', $associazioneId)
                ->lockForUpdate()
                ->first();

            if ($donazione === null) {
                Log::warning('DonazioneService: pagamento per donazione inesistente', [
                    'donazione_id' => $donazioneId,
                    'associazione_id' => $associazioneId,
                    'gateway' => $gateway,
                ]);

                return false;
            }

            // Idempotenza: già pagata o transazione registrata → ack senza ripetere
            if ($donazione->isPagata() || filled($donazione->transaction_id)) {
                return false;
            }

            $donazione->forceFill([
                'stato' => Donazione::STATO_PAGATA,
                'gateway' => $gateway,
                'transaction_id' => $transactionId,
                'pagata_at' => now(),
            ])->save();

            return true;
        });

        if ($pagataOra) {
            /** @var Donazione|null $donazione */
            $donazione = Donazione::allTenants()->find($donazioneId);

            if ($donazione !== null) {
                // Movimento automatico in prima nota (f.13/f.15)
                $this->primaNota->registraDonazioneBestEffort($donazione);

                // Ricevuta email al donatore (best-effort)
                $this->inviaRicevutaBestEffort($donazione);
            }
        }

        return true;
    }

    /**
     * Ricevuta email on-demand al donatore (se ha lasciato un'email).
     * Best-effort: mai bloccante per il flusso webhook.
     */
    public function inviaRicevutaBestEffort(Donazione $donazione): void
    {
        if (blank($donazione->donatore_email)) {
            return;
        }

        try {
            Notification::route('mail', (string) $donazione->donatore_email)
                ->notify(new RicevutaDonazioneNotification($donazione));
        } catch (\Throwable $e) {
            Log::warning('DonazioneService::inviaRicevutaBestEffort', [
                'donazione_id' => $donazione->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
