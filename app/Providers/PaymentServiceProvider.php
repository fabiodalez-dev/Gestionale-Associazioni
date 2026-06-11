<?php

namespace App\Providers;

use App\Services\Pagamenti\Gateways\PayPalGateway;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGateway;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use App\Services\Prenotazioni\Contracts\GestiscePagamentoPrenotazione;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo quote-pagamenti: binding dei gateway di pagamento
 * (sostituibili con mock nei test) e dell'handler post-pagamento
 * prenotazioni (PrenotazioneService quando disponibile, no-op altrimenti).
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeGatewayInterface::class, StripeGateway::class);
        $this->app->bind(PayPalGatewayInterface::class, PayPalGateway::class);

        // Dispatch webhook tipo=prenotazione → PrenotazioneService (modulo
        // prenotazioni). Il service implementa il contratto, quindi il
        // binding è diretto; PagamentoPrenotazioneNoOp resta disponibile
        // come fallback sostituibile nei test o se il modulo viene rimosso.
        $this->app->bind(GestiscePagamentoPrenotazione::class, PrenotazioneService::class);
    }

    public function boot(): void
    {
        // La UI del progetto è Bootstrap 5 (vedi layouts/app.blade.php)
        Paginator::useBootstrapFive();
    }
}
