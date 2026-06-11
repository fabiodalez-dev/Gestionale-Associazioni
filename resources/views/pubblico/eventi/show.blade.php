@extends('pubblico.layouts.app')

@section('title', $evento->titolo.' — '.$associazione->nome)

@section('content')
    @php
        $esaurito = $postiDisponibili !== null && $postiDisponibili <= 0;
        $aPagamento = (float) $evento->prezzo > 0;

        // Motivo specifico per la CTA disabilitata (evento non prenotabile)
        $motivoChiusura = null;
        if (! $evento->isPrenotabile()) {
            if ($evento->data_evento->isPast()) {
                $motivoChiusura = 'L\'evento si è già svolto.';
            } elseif (! $evento->iscrizioni_aperte) {
                $motivoChiusura = 'Le iscrizioni a questo evento sono chiuse.';
            } elseif ($evento->iscrizioni_dal !== null && $evento->iscrizioni_dal->isFuture()) {
                $motivoChiusura = 'Le iscrizioni aprono il '.$evento->iscrizioni_dal->format('d/m/Y').' alle '.$evento->iscrizioni_dal->format('H:i').'.';
            } elseif ($evento->iscrizioni_al !== null && $evento->iscrizioni_al->isPast()) {
                $motivoChiusura = 'Le iscrizioni si sono chiuse il '.$evento->iscrizioni_al->format('d/m/Y').' alle '.$evento->iscrizioni_al->format('H:i').'.';
            } else {
                $motivoChiusura = 'Le prenotazioni per questo evento non sono aperte.';
            }
        }
    @endphp

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('pubblico.home.show', $associazione->slug) }}">Home</a></li>
            <li class="breadcrumb-item"><a href="{{ route('pubblico.eventi.index', $associazione->slug) }}">Eventi</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $evento->titolo }}</li>
        </ol>
    </nav>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card evento-card shadow-sm">
                @if (filled($evento->immagine_url))
                    <img src="{{ $evento->immagine_url }}" class="evento-card-img" style="height: 280px;" alt="{{ $evento->titolo }}">
                @else
                    <div class="evento-card-placeholder" style="height: 200px;">
                        <i class="bi bi-calendar-event display-4 text-primary"></i>
                    </div>
                @endif
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h1 class="h3 mb-0">{{ $evento->titolo }}</h1>
                        @if ($esaurito)
                            @if ($evento->lista_attesa_attiva)
                                <span class="badge text-bg-warning">Lista d'attesa</span>
                            @else
                                <span class="badge text-bg-danger">Esaurito</span>
                            @endif
                        @endif
                    </div>

                    <ul class="list-unstyled text-muted mb-4">
                        <li class="mb-1">
                            <i class="bi bi-calendar3 me-2"></i>{{ $evento->data_evento->format('d/m/Y \o\r\e H:i') }}
                            @if ($evento->data_fine !== null)
                                &rarr; {{ $evento->data_fine->format('d/m/Y \o\r\e H:i') }}
                            @endif
                        </li>
                        @if (filled($evento->luogo))
                            <li class="mb-1"><i class="bi bi-geo-alt me-2"></i>{{ $evento->luogo }}</li>
                        @endif
                        <li class="mb-1">
                            <i class="bi bi-tag me-2"></i>
                            @if ($aPagamento)
                                &euro; {{ number_format((float) $evento->prezzo, 2, ',', '.') }} a persona
                            @else
                                Gratuito
                            @endif
                        </li>
                        @if ($postiDisponibili !== null && ! $esaurito)
                            <li class="mb-1">
                                <i class="bi bi-people me-2"></i>{{ $postiDisponibili }} {{ $postiDisponibili === 1 ? 'posto disponibile' : 'posti disponibili' }}
                            </li>
                        @endif
                    </ul>

                    @if (filled($evento->descrizione))
                        <h2 class="h5">Descrizione</h2>
                        <p class="mb-0" style="white-space: pre-line;">{{ $evento->descrizione }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm" style="border-radius: var(--ds-radius-lg);">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Prenota il tuo posto</h2>

                    @if (! $evento->isPrenotabile())
                        <p class="text-muted mb-2">
                            <i class="bi bi-info-circle me-1"></i>
                            {{ $motivoChiusura }}
                        </p>
                        <button type="button" class="btn btn-primary w-100" disabled>
                            <i class="bi bi-ticket-perforated me-1"></i> Prenota
                        </button>
                    @elseif (! auth('account')->check())
                        <p class="text-muted">Per prenotare devi accedere con il tuo account.</p>
                        <a href="{{ route('pubblico.login', $associazione->slug) }}" class="btn btn-primary w-100 mb-2">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Accedi per prenotare
                        </a>
                        <a href="{{ route('pubblico.registrazione', $associazione->slug) }}" class="btn btn-outline-secondary w-100">
                            Non hai un account? Registrati
                        </a>
                    @elseif (Route::has('pubblico.eventi.prenota'))
                        <form method="POST" action="{{ route('pubblico.eventi.prenota', ['associazione' => $associazione->slug, 'evento' => $evento->id]) }}">
                            @csrf
                            <div class="mb-3">
                                <label for="posti" class="form-label">Numero di posti</label>
                                <select name="posti" id="posti" class="form-select">
                                    @foreach (range(1, max(1, (int) $evento->max_posti_per_prenotazione)) as $n)
                                        <option value="{{ $n }}" @selected(old('posti') == $n)>{{ $n }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                @if ($esaurito && $evento->lista_attesa_attiva)
                                    <i class="bi bi-hourglass-split me-1"></i> Entra in lista d'attesa
                                @elseif ($evento->richiede_approvazione)
                                    <i class="bi bi-person-check me-1"></i> Richiedi la prenotazione
                                @else
                                    <i class="bi bi-ticket-perforated me-1"></i> Prenota
                                @endif
                            </button>
                        </form>
                        @if ($evento->richiede_approvazione)
                            <p class="text-muted small mt-2 mb-0">
                                Le prenotazioni richiedono l'approvazione dell'associazione:
                                riceverai un'email con l'esito.
                                @if ($aPagamento)
                                    Il pagamento sarà richiesto solo dopo l'approvazione.
                                @endif
                            </p>
                        @elseif ($aPagamento)
                            <p class="text-muted small mt-2 mb-0">
                                Il posto resta riservato per 30 minuti in attesa del pagamento.
                            </p>
                        @endif
                    @else
                        <p class="text-muted mb-0">Le prenotazioni online saranno disponibili a breve.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
