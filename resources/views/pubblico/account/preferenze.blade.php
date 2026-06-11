@extends('pubblico.layouts.app')

@section('title', 'Preferenze — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Preferenze</h1>

    @include('pubblico.account._nav')

    <div class="card" style="max-width: 640px;">
        <div class="card-header fw-semibold"><i class="bi bi-toggles me-1"></i>Comunicazioni</div>
        <div class="card-body">
            <form method="POST" action="{{ route('pubblico.account.preferenze.aggiorna', $associazione->slug) }}">
                @csrf
                @method('PATCH')

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="newsletter"
                           name="newsletter" value="1" @checked($preferenze['newsletter'])>
                    <label class="form-check-label" for="newsletter">
                        Newsletter
                        <span class="text-muted small d-block">Novità e aggiornamenti di {{ $associazione->nome }}.</span>
                    </label>
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="promemoria_eventi"
                           name="promemoria_eventi" value="1" @checked($preferenze['promemoria_eventi'])>
                    <label class="form-check-label" for="promemoria_eventi">
                        Promemoria eventi
                        <span class="text-muted small d-block">Email di promemoria 24 ore prima degli eventi prenotati.</span>
                    </label>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="marketing"
                           name="marketing" value="1" @checked($preferenze['marketing'])>
                    <label class="form-check-label" for="marketing">
                        Comunicazioni promozionali
                        <span class="text-muted small d-block">Promozioni e iniziative dei partner dell'associazione.</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">Salva preferenze</button>
            </form>
        </div>
    </div>
@endsection
