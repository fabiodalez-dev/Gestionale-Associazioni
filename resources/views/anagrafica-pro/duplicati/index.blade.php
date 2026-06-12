@extends('layouts.app')

@section('title', 'Possibili duplicati - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-people me-2"></i>Possibili duplicati</h1>
        <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Anagrafica soci</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('warning'))
        <div class="alert alert-warning alert-dismissible fade show">{{ session('warning') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="text-muted">
        Gruppi di soci con la stessa email, lo stesso codice fiscale oppure stessi nome, cognome e data di nascita
        (valori normalizzati). Per ogni gruppo scegli il socio <strong>principale</strong> e il <strong>duplicato</strong> da unire:
        tessere, quote, prenotazioni, documenti e storico passano al principale, il duplicato finisce nel cestino.
    </p>

    @forelse ($gruppi as $indice => $gruppo)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-intersect me-2"></i>{{ $criteri[$gruppo['criterio']] ?? $gruppo['criterio'] }}:
                    <code>{{ $gruppo['valore'] }}</code>
                </span>
                <span class="badge bg-secondary">{{ $gruppo['soci']->count() }} soci</span>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('soci-duplicati.unisci') }}">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead>
                                <tr>
                                    <th class="text-center">Principale</th>
                                    <th class="text-center">Duplicato</th>
                                    <th>N. socio</th><th>Nome</th><th>Email</th><th>Codice fiscale</th>
                                    <th>Nascita</th><th>Iscritto il</th><th>Stato</th><th>Ultima tessera</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($gruppo['soci'] as $socio)
                                    <tr>
                                        <td class="text-center">
                                            <input class="form-check-input" type="radio" name="principale_id" value="{{ $socio->id }}"
                                                   aria-label="Principale: {{ $socio->nome_completo }}" @checked($loop->first)>
                                        </td>
                                        <td class="text-center">
                                            <input class="form-check-input" type="radio" name="duplicato_id" value="{{ $socio->id }}"
                                                   aria-label="Duplicato: {{ $socio->nome_completo }}" @checked($loop->iteration === 2)>
                                        </td>
                                        <td>{{ $socio->numero_socio }}</td>
                                        <td>{{ $socio->nome_completo }}</td>
                                        <td>{{ $socio->email ?: '—' }}</td>
                                        <td>{{ $socio->codice_fiscale ?: '—' }}</td>
                                        <td>{{ $socio->data_nascita?->format('d/m/Y') ?? '—' }}</td>
                                        <td>{{ $socio->data_iscrizione?->format('d/m/Y') ?? '—' }}</td>
                                        <td><span class="badge bg-{{ $socio->stato === \App\Models\Socio::STATO_ATTIVO ? 'success' : 'secondary' }}">{{ $socio->stato }}</span></td>
                                        <td>{{ $socio->ultimaTessera?->numero_tessera ?? '—' }}</td>
                                        <td><a href="{{ route('soci.show', $socio) }}" class="btn btn-sm btn-outline-secondary" title="Apri scheda"><i class="bi bi-eye"></i></a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('Unire il duplicato nel socio principale? Il duplicato finirà nel cestino.');">
                        <i class="bi bi-union me-1"></i>Unisci nel principale
                    </button>
                </form>
            </div>
        </div>
    @empty
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Nessun possibile duplicato rilevato.</div>
    @endforelse
@endsection
