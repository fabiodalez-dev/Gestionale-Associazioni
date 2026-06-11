@extends('installer.layout')

@section('title', 'Completata')
@section('step-label', '4 di 4')

@section('content')
    <div class="text-center py-2">
        <i class="bi bi-check-circle-fill check-ok" style="font-size: 2.5rem;"></i>
        <h2 class="mt-3">Installazione completata</h2>
        <p class="subtitle mb-4">
            L'applicazione è pronta. L'installer è stato disattivato:
            per ripeterlo elimina <code>storage/app/installed.lock</code>.
        </p>
        <a href="{{ route('login') }}" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i>Vai al login
        </a>
        <p class="text-muted mt-3 mb-0" style="font-size: 0.78125rem;">
            Ricorda di attivare il cron dello scheduler e un worker per la coda:
            <code>php artisan schedule:run</code> ogni minuto e <code>php artisan queue:work</code>.
        </p>
    </div>
@endsection
