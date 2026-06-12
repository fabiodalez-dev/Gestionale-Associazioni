{{-- Form pubblico di feedback post-evento (f.11): pagina standalone fuori dal mini-sito --}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback — {{ $evento?->titolo ?? 'Evento' }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/design-system.css') }}" rel="stylesheet">
    <style>
        body { background: var(--ds-bg, #f6f6f6); }
        .feedback-card { max-width: 560px; margin: 6vh auto; border-radius: var(--ds-radius-lg, 12px); }
        .stelle input { display: none; }
        .stelle label { font-size: 2rem; color: #ccc; cursor: pointer; padding: 0 .15rem; }
        .stelle input:checked ~ label, .stelle label:hover, .stelle label:hover ~ label { color: #f5a623; }
        .stelle { display: flex; flex-direction: row-reverse; justify-content: center; }
    </style>
</head>
<body>
    <div class="card shadow-sm feedback-card">
        <div class="card-body p-4">
            <div class="text-center mb-3">
                <div class="small text-muted">{{ $associazione?->nome }}</div>
                <h1 class="h4 mb-1">{{ $evento?->titolo ?? 'Evento' }}</h1>
                @if ($evento?->data_evento !== null)
                    <div class="text-muted small">{{ $evento->data_evento->format('d/m/Y') }}</div>
                @endif
            </div>

            @if ($inviato)
                <div class="alert alert-success text-center mb-0">
                    <i class="bi bi-check-circle me-1"></i>
                    Grazie! Il tuo feedback è stato registrato.
                </div>
            @elseif ($feedback->isCompilato())
                <div class="alert alert-info text-center mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Hai già inviato il feedback per questo evento. Grazie!
                </div>
            @else
                <p class="text-center text-muted">Com'è andata? Lascia un voto da 1 a 5 e, se vuoi, un commento.</p>

                @if ($errors->any())
                    <div class="alert alert-danger">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('feedback-eventi.store', $feedback->token) }}">
                    @csrf
                    <div class="stelle mb-3" role="radiogroup" aria-label="Voto da 1 a 5">
                        @for ($i = \App\Models\FeedbackEvento::VOTO_MAX; $i >= \App\Models\FeedbackEvento::VOTO_MIN; $i--)
                            <input type="radio" name="voto" id="voto-{{ $i }}" value="{{ $i }}" @checked((int) old('voto') === $i)>
                            <label for="voto-{{ $i }}" title="{{ $i }} su 5"><i class="bi bi-star-fill"></i></label>
                        @endfor
                    </div>
                    <div class="mb-3">
                        <label for="commento" class="form-label">Commento (facoltativo)</label>
                        <textarea class="form-control @error('commento') is-invalid @enderror" id="commento"
                                  name="commento" rows="4" maxlength="2000"
                                  placeholder="Cosa ti è piaciuto? Cosa possiamo migliorare?">{{ old('commento') }}</textarea>
                        @error('commento')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send me-1"></i>Invia il feedback
                    </button>
                </form>
            @endif
        </div>
    </div>
</body>
</html>
