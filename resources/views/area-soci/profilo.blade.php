@extends('area-soci.layouts.app')

@section('title', 'Il Mio Profilo - '.$socio->nome_completo)

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Il Mio Profilo</h1>
        @if (\Illuminate\Support\Facades\Route::has('area-soci.modifica-dati'))
            <a href="{{ route('area-soci.modifica-dati') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil-square me-1"></i>Richiedi modifica dati
            </a>
        @endif
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="bi bi-person me-2"></i>I miei dati</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('area-soci.profilo.update') }}">
                        @csrf
                        @method('PATCH')

                        {{-- Campi in sola lettura --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome</label>
                                <input type="text" class="form-control" value="{{ $socio->nome }}" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cognome</label>
                                <input type="text" class="form-control" value="{{ $socio->cognome }}" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" value="{{ $socio->email }}" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Numero socio</label>
                                <input type="text" class="form-control" value="{{ $socio->numero_socio }}" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data di nascita</label>
                                <input type="text" class="form-control" value="{{ $socio->data_nascita?->format('d/m/Y') }}" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data iscrizione</label>
                                <input type="text" class="form-control" value="{{ $socio->data_iscrizione?->format('d/m/Y') }}" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Stato</label>
                                <input type="text" class="form-control" value="{{ $socio->stato }}" disabled>
                            </div>
                        </div>

                        <hr>

                        {{-- Campi modificabili --}}
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="telefono" class="form-label">Telefono</label>
                                <input type="tel" class="form-control @error('telefono') is-invalid @enderror" id="telefono" name="telefono" value="{{ old('telefono', $socio->telefono) }}" maxlength="20">
                                @error('telefono')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="indirizzo" class="form-label">Indirizzo</label>
                                <input type="text" class="form-control @error('indirizzo') is-invalid @enderror" id="indirizzo" name="indirizzo" value="{{ old('indirizzo', $socio->indirizzo) }}" maxlength="255">
                                @error('indirizzo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-8">
                                <label for="citta" class="form-label">Citt&agrave;</label>
                                <input type="text" class="form-control @error('citta') is-invalid @enderror" id="citta" name="citta" value="{{ old('citta', $socio->citta) }}" maxlength="100">
                                @error('citta')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="cap" class="form-label">CAP</label>
                                <input type="text" class="form-control @error('cap') is-invalid @enderror" id="cap" name="cap" value="{{ old('cap', $socio->cap) }}" maxlength="5" inputmode="numeric">
                                @error('cap')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="email_opt_out" value="0">
                                    <input class="form-check-input" type="checkbox" id="email_opt_out" name="email_opt_out" value="1" @checked(old('email_opt_out', $socio->email_opt_out))>
                                    <label class="form-check-label" for="email_opt_out">
                                        Non desidero ricevere comunicazioni email dall'associazione
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Salva modifiche
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        @if ($valoriCampi->isNotEmpty() || $tags->isNotEmpty())
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><i class="bi bi-card-list me-2"></i>Dati personalizzati e tag</div>
                    <div class="card-body">
                        @if ($valoriCampi->isNotEmpty())
                            <dl class="mb-3">
                                @foreach ($valoriCampi as $valore)
                                    <dt class="small text-muted">{{ $valore->campo?->nome_campo }}</dt>
                                    <dd>{{ $valore->valore }}</dd>
                                @endforeach
                            </dl>
                        @endif
                        @if ($tags->isNotEmpty())
                            <div>
                                @foreach ($tags as $tag)
                                    <span class="badge me-1 mb-1" style="background-color: {{ $tag->colore ?: '#6c757d' }};">{{ $tag->nome_tag }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
