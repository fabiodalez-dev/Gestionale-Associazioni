@extends('layouts.app')

@section('title', 'Galleria — '.$evento->titolo)

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-images me-2"></i>Galleria foto</h1>
            <p class="text-muted mb-0">{{ $evento->titolo }} — {{ $evento->data_evento?->format('d/m/Y') }}</p>
        </div>
        <a href="{{ route('eventi.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Eventi</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @error('foto')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    <div class="row g-4">
        <div class="col-lg-4">
            {{-- Upload --}}
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-cloud-upload me-2"></i>Carica foto</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('eventi.galleria.store', $evento) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-2">
                            <input type="file" class="form-control @error('foto.*') is-invalid @enderror"
                                   name="foto[]" accept="image/jpeg,image/png,image/webp" multiple required>
                            @error('foto.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <p class="small text-muted">JPEG, PNG o WEBP. Max {{ $maxFoto }} foto totali ({{ $foto->count() }} caricate), 5MB ciascuna.</p>
                        <button type="submit" class="btn btn-primary w-100" {{ $foto->count() >= $maxFoto ? 'disabled' : '' }}>
                            <i class="bi bi-upload me-1"></i>Carica
                        </button>
                    </form>
                </div>
            </div>

            {{-- Visibilità pubblica --}}
            <div class="card">
                <div class="card-header"><i class="bi bi-globe me-2"></i>Visibilità</div>
                <div class="card-body">
                    <p class="small mb-2">
                        @if ($evento->galleria_pubblica)
                            <span class="badge bg-success">Pubblica</span> La galleria è mostrata sulla pagina pubblica dell'evento.
                        @else
                            <span class="badge bg-secondary">Privata</span> La galleria non è visibile al pubblico.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('eventi.galleria.visibilita', $evento) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm {{ $evento->galleria_pubblica ? 'btn-outline-secondary' : 'btn-outline-success' }} w-100">
                            {{ $evento->galleria_pubblica ? 'Nascondi dal pubblico' : 'Rendi pubblica' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            @if ($foto->isEmpty())
                <div class="alert alert-light border text-center my-4">
                    <i class="bi bi-image me-1"></i>Nessuna foto nella galleria. Carica le prime immagini.
                </div>
            @else
                <div class="row g-2">
                    @foreach ($foto as $immagine)
                        <div class="col-6 col-md-4">
                            <div class="card h-100">
                                <img src="{{ $immagine->hasGeneratedConversion('thumb') ? $immagine->getUrl('thumb') : $immagine->getUrl() }}"
                                     alt="Foto galleria" class="card-img-top" style="aspect-ratio: 4/3; object-fit: cover;">
                                <div class="card-body p-2 text-end">
                                    <form method="POST" action="{{ route('eventi.galleria.destroy', ['evento' => $evento, 'media' => $immagine->id]) }}"
                                          onsubmit="return confirm('Eliminare questa foto?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
