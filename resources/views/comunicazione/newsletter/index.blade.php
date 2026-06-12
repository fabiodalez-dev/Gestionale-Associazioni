@extends('layouts.app')

@section('title', 'Newsletter')

@section('content')
<div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-envelope-paper me-2"></i>Newsletter</h1>
</div>

@unless ($isConfigured)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        SMTP non configurato: puoi comporre l'anteprima ma non inviare.
        <a href="{{ route('email-impostazioni.index') }}">Configura le impostazioni email</a>.
    </div>
@endunless

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil-square me-1"></i>Componi da annunci pubblicati</div>
            <div class="card-body">
                @can('send', \App\Models\NewsletterInviata::class)
                    @if ($annunciPubblicati->isEmpty())
                        <p class="text-muted mb-0">
                            Nessun annuncio attualmente pubblicato.
                            <a href="{{ route('annunci.index') }}">Crea o pubblica un annuncio</a> per comporre la newsletter.
                        </p>
                    @else
                        <form method="POST" action="{{ route('newsletter.store') }}" id="newsletterForm">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="titolo">Oggetto della newsletter *</label>
                                <input type="text" class="form-control @error('titolo') is-invalid @enderror"
                                       id="titolo" name="titolo" maxlength="255" required
                                       value="{{ old('titolo', 'Le novità di '.$associazione->nome) }}">
                                @error('titolo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <label class="form-label">Annunci da includere *</label>
                            @error('annunci')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                            <div class="list-group mb-3">
                                @foreach ($annunciPubblicati as $annuncio)
                                    <label class="list-group-item d-flex gap-2 align-items-start">
                                        <input class="form-check-input mt-1" type="checkbox" name="annunci[]"
                                               value="{{ $annuncio->id }}"
                                               @checked(in_array($annuncio->id, old('annunci', []), true))>
                                        <span>
                                            <strong>{{ $annuncio->titolo }}</strong>
                                            @if ($annuncio->in_evidenza)
                                                <span class="badge bg-warning text-dark ms-1">In evidenza</span>
                                            @endif
                                            @if ($annuncio->solo_soci)
                                                <span class="badge bg-primary ms-1">Solo soci</span>
                                            @endif
                                            <small class="d-block text-muted">
                                                pubblicato dal {{ $annuncio->pubblicato_dal?->format('d/m/Y') }}
                                            </small>
                                        </span>
                                    </label>
                                @endforeach
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <button type="submit" form="anteprimaForm" class="btn btn-outline-secondary">
                                    <i class="bi bi-eye me-1"></i>Anteprima
                                </button>
                                <button type="submit" class="btn btn-primary" @disabled(! $isConfigured)
                                        onclick="return confirm('Inviare la newsletter a {{ $destinatariCount }} soci attivi?');">
                                    <i class="bi bi-send me-1"></i>Invia a {{ $destinatariCount }} soci
                                </button>
                                <span class="text-muted small">
                                    Destinatari: soci attivi con email, senza opt-out.
                                </span>
                            </div>
                        </form>

                        {{-- Form gemello per l'anteprima in nuova scheda: replica i campi via JS --}}
                        <form method="POST" action="{{ route('newsletter.anteprima') }}" target="_blank"
                              id="anteprimaForm" class="d-none">
                            @csrf
                        </form>
                        <script>
                            document.getElementById('anteprimaForm').addEventListener('submit', function () {
                                this.querySelectorAll('[data-clone]').forEach(function (el) { el.remove(); });
                                var src = document.getElementById('newsletterForm');
                                var dest = this;
                                src.querySelectorAll('input[name="titolo"], input[name="annunci[]"]:checked').forEach(function (el) {
                                    var hidden = document.createElement('input');
                                    hidden.type = 'hidden';
                                    hidden.name = el.name;
                                    hidden.value = el.value;
                                    hidden.setAttribute('data-clone', '1');
                                    dest.appendChild(hidden);
                                });
                            });
                        </script>
                    @endif
                @else
                    <p class="text-muted mb-0">Non hai i permessi per inviare newsletter.</p>
                @endcan
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i>Storico invii</div>
            <div class="card-body p-0">
                @if ($storico->isEmpty())
                    <p class="text-muted small p-3 mb-0">Nessuna newsletter inviata finora.</p>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($storico as $newsletter)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <strong>{{ $newsletter->titolo }}</strong>
                                    <span class="badge bg-primary">{{ $newsletter->inviata_a }} destinatari</span>
                                </div>
                                <small class="text-muted">
                                    {{ $newsletter->inviata_at?->format('d/m/Y H:i') }}
                                    — {{ count((array) $newsletter->annunci_ids) }} {{ count((array) $newsletter->annunci_ids) === 1 ? 'annuncio' : 'annunci' }}
                                    @if ($newsletter->inviataDa)
                                        — {{ $newsletter->inviataDa->nome }}
                                    @endif
                                </small>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
        <div class="d-flex justify-content-center mt-2">
            {{ $storico->links() }}
        </div>
    </div>
</div>
@endsection
