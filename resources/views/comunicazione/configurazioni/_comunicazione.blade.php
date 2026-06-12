{{-- Sezione "Comunicazione" delle configurazioni (f.9 piano Gestione 2):
     toggle per tenant degli auguri di compleanno automatici.
     Variabile: $associazione. --}}
<div class="card">
    <div class="card-header"><i class="bi bi-megaphone me-2"></i>Comunicazione</div>
    <div class="card-body">
        <form method="POST" action="{{ route('configurazioni.comunicazione') }}">
            @csrf
            <div class="form-check form-switch mb-2">
                <input type="hidden" name="auguri_automatici" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="auguri_automatici"
                       name="auguri_automatici" value="1" @checked(old('auguri_automatici', $associazione->auguri_automatici))>
                <label class="form-check-label" for="auguri_automatici">
                    <strong>Auguri di compleanno automatici</strong>
                    <span class="text-muted small d-block">
                        Ogni giorno il sistema invia una email di auguri ai soci attivi che compiono
                        gli anni (solo a chi ha un'email e non ha disattivato le comunicazioni).
                        Un solo invio per socio all'anno.
                    </span>
                </label>
            </div>
            <button class="btn btn-primary mt-2" type="submit">Salva comunicazione</button>
        </form>
    </div>
</div>
