@extends('layouts.app')

@section('title', 'Impostazioni Pagamenti')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-gear me-2"></i>Impostazioni Pagamenti</h1>
    <a href="{{ route('quote.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Torna alle quote</a>
</div>

<form method="POST" action="{{ route('quote.impostazioni.update') }}">
    @csrf
    @method('PUT')

    <div class="row">
        {{-- Stripe --}}
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-credit-card me-2"></i><strong>Stripe</strong></span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="stripe_enabled" value="1" id="stripeEnabled"
                               @checked(old('stripe_enabled', $settings?->stripe_enabled))>
                        <label class="form-check-label" for="stripeEnabled">Abilitato</label>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="stripePk">Publishable key</label>
                        <input type="text" class="form-control" id="stripePk" name="stripe_publishable_key"
                               value="{{ old('stripe_publishable_key', $settings?->stripe_publishable_key) }}" placeholder="pk_live_...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="stripeSk">Secret key</label>
                        <input type="password" class="form-control" id="stripeSk" name="stripe_secret_key"
                               placeholder="{{ filled($settings?->stripe_secret_key) ? '••••••••  (salvata — lascia vuoto per non modificare)' : 'sk_live_...' }}" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="stripeWh">Webhook signing secret</label>
                        <input type="password" class="form-control" id="stripeWh" name="stripe_webhook_secret"
                               placeholder="{{ filled($settings?->stripe_webhook_secret) ? '••••••••  (salvata — lascia vuoto per non modificare)' : 'whsec_...' }}" autocomplete="new-password">
                    </div>
                    <p class="small text-muted mb-0">
                        Endpoint webhook da configurare su Stripe:<br>
                        <code>{{ $stripeWebhookUrl }}</code> (evento <code>checkout.session.completed</code>)
                    </p>
                </div>
            </div>
        </div>

        {{-- PayPal --}}
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-paypal me-2"></i><strong>PayPal</strong></span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="paypal_enabled" value="1" id="paypalEnabled"
                               @checked(old('paypal_enabled', $settings?->paypal_enabled))>
                        <label class="form-check-label" for="paypalEnabled">Abilitato</label>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="paypalClientId">Client ID</label>
                        <input type="text" class="form-control" id="paypalClientId" name="paypal_client_id"
                               value="{{ old('paypal_client_id', $settings?->paypal_client_id) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="paypalSecret">Client secret</label>
                        <input type="password" class="form-control" id="paypalSecret" name="paypal_client_secret"
                               placeholder="{{ filled($settings?->paypal_client_secret) ? '••••••••  (salvata — lascia vuoto per non modificare)' : '' }}" autocomplete="new-password">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label" for="paypalMode">Ambiente</label>
                            <select class="form-select" id="paypalMode" name="paypal_mode">
                                <option value="sandbox" @selected(old('paypal_mode', $settings?->paypal_mode ?? 'sandbox') === 'sandbox')>Sandbox</option>
                                <option value="live" @selected(old('paypal_mode', $settings?->paypal_mode) === 'live')>Live</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label" for="paypalWebhookId">Webhook ID</label>
                            <input type="text" class="form-control" id="paypalWebhookId" name="paypal_webhook_id"
                                   value="{{ old('paypal_webhook_id', $settings?->paypal_webhook_id) }}">
                            <div class="form-text">Necessario per la verifica firma dei webhook.</div>
                        </div>
                    </div>
                    <p class="small text-muted mb-0">
                        Endpoint webhook da configurare su PayPal:<br>
                        <code>{{ $paypalWebhookUrl }}</code> (evento <code>CHECKOUT.ORDER.APPROVED</code>)
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Opzioni generali</strong></div>
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="valuta">Valuta (ISO 4217)</label>
                    <input type="text" class="form-control" id="valuta" name="valuta" maxlength="3"
                           value="{{ old('valuta', $settings?->valuta ?? 'EUR') }}">
                    @error('valuta')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-9 mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="auto_attivazione_pagamento" value="1" id="autoAttivazione"
                               @checked(old('auto_attivazione_pagamento', $settings?->auto_attivazione_pagamento ?? true))>
                        <label class="form-check-label" for="autoAttivazione">
                            Attiva automaticamente il socio "In Attesa di Pagamento" dopo il pagamento online (con generazione tessera)
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva impostazioni</button>
</form>
@endsection
