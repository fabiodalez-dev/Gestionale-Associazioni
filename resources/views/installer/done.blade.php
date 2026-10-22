@extends('installer.layout')

@section('title', 'Completata')
@section('step-label', '5 di 5')

@section('content')
    <div class="text-center py-2">
        <i class="bi bi-check-circle-fill check-ok" style="font-size: 2.5rem;"></i>
        <h2 class="mt-3">Installazione completata</h2>
        <p class="subtitle mb-4">
            L'applicazione è pronta. L'installer è stato disattivato:
            per ripeterlo elimina <code>storage/app/installed.lock</code>.
        </p>
        @if (! ($storageCollegato ?? true))
            <div class="alert alert-warning text-start mb-3" style="font-size: 0.8125rem;">
                Non sono riuscito a creare il collegamento <code>public/storage</code> (serve per le immagini caricate).
                Crealo con <code>php artisan storage:link</code> oppure chiedi al tuo hosting di abilitare i link simbolici.
            </div>
        @endif
        <a href="{{ route('login') }}" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i>Vai al login
        </a>
        <div class="text-start text-muted mt-3 mb-0" style="font-size: 0.78125rem;">
            <p class="mb-1"><strong>Ultimo passo: le attività in background.</strong> Nel pannello del tuo hosting (cPanel → Cron Jobs) aggiungi un'attività <strong>ogni minuto</strong> con questo comando, sostituendo il percorso dell'app:</p>
            <pre class="mb-2" style="white-space: pre-wrap; font-size: 0.75rem;">{{ 'cd /home/utente/public_html && php artisan schedule:run >> /dev/null 2>&1 && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1' }}</pre>
            <p class="mb-0">Spedisce le email in coda, i promemoria e i backup. Se hai un server tuo, usa invece un worker permanente (<code>php artisan queue:work</code>) più il cron di <code>schedule:run</code>.</p>
        </div>
    </div>
@endsection
