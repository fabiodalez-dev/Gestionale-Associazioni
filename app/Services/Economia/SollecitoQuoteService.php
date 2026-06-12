<?php

namespace App\Services\Economia;

use App\Models\PromemoriaInviato;
use App\Models\Quota;
use App\Models\Socio;
use App\Notifications\Economia\SollecitoQuotaNotification;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Solleciti quote non pagate (f.16 del piano "Gestione 2"): email
 * brandizzata col link di pagamento, singola o in blocco, con tracking
 * in promemoria_inviati (tipo 'sollecito_quota', step = anno della
 * quota) per conteggio solleciti e data ultimo invio per quota.
 */
class SollecitoQuoteService
{
    /** Tipo del tracking in promemoria_inviati (f.16). */
    public const TIPO_SOLLECITO_QUOTA = 'sollecito_quota';

    public function __construct(protected PaymentService $payments) {}

    /**
     * Invia il sollecito per una quota non pagata.
     *
     * @return bool true se il sollecito è stato inviato, false se la
     *              quota non è sollecitabile (pagata, socio senza email
     *              o con opt-out).
     */
    public function invia(Quota $quota): bool
    {
        if ($quota->data_pagamento !== null) {
            return false;
        }

        /** @var Socio|null $socio */
        $socio = $quota->socio()->first();

        if ($socio === null || blank($socio->email) || $socio->email_opt_out) {
            return false;
        }

        // Link di pagamento: riusa il token valido o ne genera uno nuovo
        $paymentUrl = $this->paymentUrl($quota);

        $socio->notify(new SollecitoQuotaNotification($quota->fresh() ?? $quota, $paymentUrl));

        $this->traccia($quota, $socio);

        return true;
    }

    /**
     * Sollecito in blocco: ritorna [inviati, saltati].
     *
     * @param  iterable<Quota>  $quote
     * @return array{0: int, 1: int}
     */
    public function inviaBulk(iterable $quote): array
    {
        $inviati = 0;
        $saltati = 0;

        foreach ($quote as $quota) {
            try {
                $this->invia($quota) ? $inviati++ : $saltati++;
            } catch (\Throwable $e) {
                $saltati++;
                Log::warning('SollecitoQuoteService::inviaBulk', [
                    'quota_id' => $quota->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$inviati, $saltati];
    }

    /**
     * Conteggio solleciti e data ultimo invio per quota: chiave logica
     * (socio, tipo sollecito_quota, step = anno della quota).
     *
     * @param  iterable<Quota>  $quote
     * @return array<string, array{conteggio: int, ultimo: Carbon|null}>
     */
    public function statoSolleciti(iterable $quote): array
    {
        $quote = collect($quote);

        if ($quote->isEmpty()) {
            return [];
        }

        $righe = PromemoriaInviato::query()
            ->where('tipo', self::TIPO_SOLLECITO_QUOTA)
            ->whereIn('socio_id', $quote->pluck('socio_id')->filter()->unique())
            ->whereIn('step', $quote->pluck('anno')->unique())
            ->get()
            ->groupBy(fn (PromemoriaInviato $p) => $p->socio_id.'|'.$p->step);

        $stato = [];

        foreach ($quote as $quota) {
            $gruppo = $righe->get($quota->socio_id.'|'.$quota->anno, collect());

            $stato[$quota->id] = [
                'conteggio' => $gruppo->count(),
                'ultimo' => $gruppo->max('inviato_at'),
            ];
        }

        return $stato;
    }

    /**
     * URL pubblico di pagamento: riusa il token ancora valido o ne
     * genera uno nuovo (best-effort: senza URL il sollecito parte
     * comunque con le istruzioni alternative).
     */
    protected function paymentUrl(Quota $quota): ?string
    {
        try {
            $tokenValido = filled($quota->payment_token)
                && $quota->payment_token_expires !== null
                && $quota->payment_token_expires->isFuture();

            if (! $tokenValido) {
                $this->payments->generatePaymentToken($quota);
            }

            return $this->payments->paymentUrl($quota->fresh() ?? $quota);
        } catch (\Throwable $e) {
            Log::warning('SollecitoQuoteService::paymentUrl', [
                'quota_id' => $quota->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Tracking del sollecito in promemoria_inviati (f.16).
     */
    protected function traccia(Quota $quota, Socio $socio): void
    {
        PromemoriaInviato::create([
            'associazione_id' => $quota->associazione_id,
            'socio_id' => $socio->id,
            'tessera_id' => null,
            'tipo' => self::TIPO_SOLLECITO_QUOTA,
            'step' => (int) $quota->anno,
            'inviato_at' => now(),
        ]);
    }
}
