@extends('installer.layout')

@section('title', 'Amministratore')
@section('step-label', '3 di 5')

@section('content')
    <h2>Super amministratore</h2>
    <p class="subtitle">Crea la prima associazione e l'account che governa la piattaforma.</p>

    <form method="POST" action="{{ route('installer.admin.store') }}">
        @csrf
        <h6 class="text-uppercase text-muted mb-2" style="font-size: 0.71875rem; letter-spacing: 0.05em;">Associazione</h6>
        @php($form = new \App\Forms\InstallerAdminForm(\App\Models\Utente::class))
        @include('formello.campi', ['form' => $form, 'solo' => ['associazione_nome', 'associazione_email']])

        <h6 class="text-uppercase text-muted mb-2 mt-2" style="font-size: 0.71875rem; letter-spacing: 0.05em;">Account super admin</h6>
        @include('formello.campi', ['form' => $form, 'solo' => ['nome', 'cognome', 'email', 'password', 'password_confirmation']])

        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="dati_demo" name="dati_demo" value="1" @checked(old('dati_demo'))>
            <label class="form-check-label" for="dati_demo">
                Installa dati dimostrativi
                <span class="text-muted">(associazione di esempio con 3 soci, tessere, quote e un evento — nessun account utente)</span>
            </label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-4">
            <i class="bi bi-person-plus me-2"></i>Crea account e continua
        </button>
    </form>
@endsection
