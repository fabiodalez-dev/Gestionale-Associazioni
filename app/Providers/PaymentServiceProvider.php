<?php

namespace App\Providers;

use App\Services\Pagamenti\Gateways\PayPalGateway;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGateway;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo quote-pagamenti: binding dei gateway di pagamento
 * (sostituibili con mock nei test).
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeGatewayInterface::class, StripeGateway::class);
        $this->app->bind(PayPalGatewayInterface::class, PayPalGateway::class);
    }

    public function boot(): void
    {
        // La UI del progetto è Bootstrap 5 (vedi layouts/app.blade.php)
        Paginator::useBootstrapFive();
    }
}
