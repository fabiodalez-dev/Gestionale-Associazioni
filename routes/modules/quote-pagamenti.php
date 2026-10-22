<?php

use App\Http\Controllers\Quote\ImpostazioniPagamentiController;
use App\Http\Controllers\Quote\PagamentoQuotaController;
use App\Http\Controllers\Quote\PaginaPagamentoController;
use App\Http\Controllers\Quote\QuotaController;
use App\Http\Controllers\Quote\RicevutaController;
use App\Http\Controllers\Quote\Webhooks\PayPalWebhookController;
use App\Http\Controllers\Quote\Webhooks\StripeWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: quote-pagamenti
|--------------------------------------------------------------------------
|
| Area amministrativa (auth + tenant), pagina pubblica di pagamento via
| token (nessuna auth: l'autorizzazione è il token stesso) e webhook
| Stripe/PayPal (pubblici, esclusi dal CSRF, verificati via firma).
|
*/

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/quote', [QuotaController::class, 'index'])->name('quote.index');
    Route::post('/quote', [QuotaController::class, 'store'])->name('quote.store');
    Route::put('/quote/{quota}', [QuotaController::class, 'update'])->name('quote.update');
    Route::delete('/quote/{quota}', [QuotaController::class, 'destroy'])->name('quote.destroy');

    Route::get('/quote/soci-search', [QuotaController::class, 'sociSearch'])->name('quote.soci-search');
    Route::get('/quote/export', [QuotaController::class, 'export'])->name('quote.export');

    Route::post('/quote/{quota}/pagamento', [PagamentoQuotaController::class, 'store'])->name('quote.pagamento');
    Route::post('/quote/{quota}/payment-link', [PagamentoQuotaController::class, 'paymentLink'])->name('quote.payment-link');

    Route::get('/quote/{quota}/ricevuta', [RicevutaController::class, 'show'])->name('quote.ricevuta');

    Route::get('/impostazioni-pagamenti', [ImpostazioniPagamentiController::class, 'edit'])->name('quote.impostazioni.edit');
    Route::put('/impostazioni-pagamenti', [ImpostazioniPagamentiController::class, 'update'])->name('quote.impostazioni.update');
});

// Pagina pubblica di pagamento: token 64 char esadecimali
Route::get('/pagamento/{token}', [PaginaPagamentoController::class, 'show'])
    ->where('token', '[a-fA-F0-9]{64}')
    ->name('pagamento.show');

Route::post('/pagamento/{token}/checkout', [PaginaPagamentoController::class, 'checkout'])
    ->where('token', '[a-fA-F0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('pagamento.checkout');

Route::get('/pagamento/{token}/ricevuta', [PaginaPagamentoController::class, 'ricevuta'])
    ->where('token', '[a-fA-F0-9]{64}')
    ->name('pagamento.ricevuta');

// Webhook gateway: pubblici, senza CSRF (sicurezza = verifica firma / capture server-side)
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.stripe');

Route::post('/webhooks/paypal', PayPalWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.paypal');
