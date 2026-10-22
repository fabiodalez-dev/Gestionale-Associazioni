@php($gc = $associazione->impostazioni(\App\Settings\GoogleCalendarSettings::class))
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-week me-2"></i>Sincronizzazione con Google Calendar</span>
        @if ($gc->attivaEConfigurata())
            <span class="badge bg-success">Attiva</span>
        @else
            <span class="badge bg-secondary">Spenta</span>
        @endif
    </div>
    <div class="card-body">
        <p class="text-muted">
            Gli eventi pubblicati vengono creati, aggiornati e rimossi automaticamente su un Google Calendar dell'associazione.
            Le bozze non vengono pubblicate; l'esportazione ICS resta disponibile.
        </p>
        <details class="mb-4">
            <summary class="fw-medium">Come si configura</summary>
            <ol class="small mt-2 mb-0">
                <li>Nella Google Cloud Console crea un progetto, abilita la <em>Google Calendar API</em> e crea un <em>service account</em>.</li>
                <li>Dal service account genera una chiave di tipo JSON e caricala qui sotto.</li>
                <li>In Google Calendar, nelle impostazioni del calendario, condividilo con l'email del service account con il permesso <em>Apportare modifiche agli eventi</em>.</li>
                <li>Copia l'<em>ID calendario</em> (sezione "Integra calendario"), incollalo qui, salva e premi "Verifica connessione".</li>
            </ol>
        </details>

        @if ($gc->ultimo_errore)
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>Ultimo errore di sincronizzazione
                @if ($gc->ultimo_errore_at)({{ \Illuminate\Support\Carbon::parse($gc->ultimo_errore_at)->format('d/m/Y H:i') }})@endif:
                {{ $gc->ultimo_errore }}
            </div>
        @endif

        <form method="POST" action="{{ route('configurazioni.google-calendar') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-check form-switch mb-3">
                <input type="hidden" name="attiva" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="gc_attiva" name="attiva" value="1" @checked(old('attiva', $gc->attiva))>
                <label class="form-check-label" for="gc_attiva">Sincronizza gli eventi con Google Calendar</label>
            </div>
            <div class="mb-3">
                <label class="form-label" for="gc_calendar_id">ID calendario</label>
                <input type="text" class="form-control @error('calendar_id') is-invalid @enderror" id="gc_calendar_id" name="calendar_id"
                       value="{{ old('calendar_id', $gc->calendar_id) }}" placeholder="abc123@group.calendar.google.com" autocomplete="off">
                @error('calendar_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label" for="gc_file">Chiave JSON del service account</label>
                @if ($gc->service_account_email)
                    <div class="small mb-2">
                        <i class="bi bi-key me-1"></i>Credenziali salvate per <code>{{ $gc->service_account_email }}</code>: condividi il calendario con questo indirizzo.
                    </div>
                @endif
                <input type="file" class="form-control @error('credenziali_file') is-invalid @enderror" id="gc_file" name="credenziali_file" accept="application/json,.json">
                @error('credenziali_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <details class="mt-2">
                    <summary class="small">Oppure incolla il contenuto del file</summary>
                    <textarea class="form-control font-monospace small mt-2 @error('credenziali_json') is-invalid @enderror" name="credenziali_json" rows="4" autocomplete="off" spellcheck="false"></textarea>
                </details>
                @error('credenziali_json')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="form-text">Le credenziali vengono cifrate e non sono più visualizzabili. Lasciare vuoto per mantenere quelle attuali.</div>
                @if ($gc->configurata())
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="gc_rimuovi" name="rimuovi_credenziali" value="1">
                        <label class="form-check-label small" for="gc_rimuovi">Rimuovi le credenziali salvate (spegne la sincronizzazione)</label>
                    </div>
                @endif
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva</button>
        </form>

        @if ($gc->configurata())
            <hr>
            <div class="d-flex flex-wrap gap-2">
                <form method="POST" action="{{ route('configurazioni.google-calendar.verifica') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-plug me-1"></i>Verifica connessione</button>
                </form>
                @if ($gc->attivaEConfigurata())
                    <form method="POST" action="{{ route('configurazioni.google-calendar.sincronizza') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Sincronizza gli eventi futuri</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</div>
