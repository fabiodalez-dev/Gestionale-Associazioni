@extends('pubblico.layouts.app')

@section('title', 'Privacy — '.$associazione->nome)

@section('content')
    <h1 class="h3 mb-4">Privacy e dati personali</h1>

    @include('pubblico.account._nav')

    <div class="row g-4">
        {{-- Export GDPR --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-download me-1"></i>Esporta i tuoi dati</div>
                <div class="card-body">
                    <p class="text-muted">
                        Scarica una copia completa in formato JSON dei dati del tuo account
                        e di tutte le tue prenotazioni (diritto alla portabilità, art. 20 GDPR).
                    </p>
                    <a href="{{ route('pubblico.account.privacy.export', $associazione->slug) }}" class="btn btn-outline-primary">
                        <i class="bi bi-filetype-json me-1"></i>Scarica i miei dati (JSON)
                    </a>
                    <hr>
                    <p class="text-muted small mb-2">
                        Archivio completo (JSON, riepilogo PDF e richieste di iscrizione): lo prepariamo e ti inviamo via email
                        un link valido {{ config('personal-data-export.delete_after_days') }} giorni. Una richiesta ogni 24 ore.
                    </p>
                    <form method="POST" action="{{ route('pubblico.account.privacy.archivio', $associazione->slug) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-zip me-1"></i>Ricevi l'archivio via email</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Cancellazione GDPR --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100 border-danger-subtle">
                <div class="card-header fw-semibold text-danger"><i class="bi bi-trash me-1"></i>Cancella il mio account</div>
                <div class="card-body">
                    <p class="text-muted">
                        La cancellazione è <strong>definitiva</strong>: i tuoi dati personali vengono
                        anonimizzati e non potrai più accedere. Le prenotazioni passate restano
                        registrate in forma anonima per gli obblighi contabili dell'associazione.
                    </p>

                    @error('password')
                        <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                    @enderror

                    <form method="POST" action="{{ route('pubblico.account.privacy.cancella', $associazione->slug) }}"
                          onsubmit="return confirm('Confermi la cancellazione definitiva del tuo account? L\'operazione NON è reversibile.');">
                        @csrf
                        <div class="mb-3">
                            <label for="password" class="form-label">Conferma con la tua password</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Cancella definitivamente il mio account
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <p class="text-muted small mb-0">
                Per maggiori informazioni consulta l'<a href="{{ route('privacy-policy.show', $associazione->id) }}">informativa sulla privacy</a>
                di {{ $associazione->nome }}.
            </p>
        </div>
    </div>
@endsection
