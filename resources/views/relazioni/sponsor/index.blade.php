@extends('layouts.app')

@section('title', 'Sponsor - '.session('app_name', config('app.name')))

@php
    use App\Models\Sponsor;
    $livelloBadge = [
        Sponsor::LIVELLO_ORO => ['text-bg-warning', 'Oro'],
        Sponsor::LIVELLO_ARGENTO => ['text-bg-secondary', 'Argento'],
        Sponsor::LIVELLO_BRONZO => ['text-bg-danger', 'Bronzo'],
        Sponsor::LIVELLO_PARTNER => ['text-bg-info', 'Partner'],
    ];
@endphp

@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-award me-2"></i>Sponsor & partner</h1>
            <p class="text-muted mb-0">I sostenitori dell'associazione, mostrabili sul mini-sito pubblico.</p>
        </div>
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#nuovoSponsor"
                aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="nuovoSponsor">
            <i class="bi bi-plus-lg me-1"></i>Nuovo sponsor
        </button>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    <div class="collapse {{ $errors->any() ? 'show' : '' }}" id="nuovoSponsor">
        <div class="card mb-3">
            <div class="card-body">
                <form method="POST" action="{{ route('sponsor.store') }}">
                    @csrf
                    @include('relazioni.sponsor._form', ['sponsorCorrente' => null, 'contatti' => $contatti])
                    <div class="text-end mt-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea sponsor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if ($sponsor->isEmpty())
        <div class="alert alert-light border text-center my-4">
            <i class="bi bi-award me-1"></i>Nessuno sponsor registrato.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Sponsor</th>
                        <th>Livello</th>
                        <th>Periodo</th>
                        <th class="text-center">Sul sito</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sponsor as $s)
                        @php $badge = $livelloBadge[$s->livello] ?? ['text-bg-light', $s->livello]; @endphp
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $s->nome }}</span>
                                @if ($s->contattoEsterno)
                                    <div class="small text-muted"><i class="bi bi-person-rolodex me-1"></i>{{ $s->contattoEsterno->nome }}</div>
                                @endif
                                @if ($s->sito_url)
                                    <div class="small"><a href="{{ $s->sito_url }}" target="_blank" rel="noopener">{{ $s->sito_url }}</a></div>
                                @endif
                            </td>
                            <td><span class="badge {{ $badge[0] }}">{{ $badge[1] }}</span></td>
                            <td class="small">
                                {{ $s->dal?->format('d/m/Y') ?? '—' }} &rarr; {{ $s->al?->format('d/m/Y') ?? '—' }}
                                @unless ($s->isNelPeriodo())
                                    <span class="badge bg-secondary">Fuori periodo</span>
                                @endunless
                            </td>
                            <td class="text-center">
                                @if ($s->visibile_sul_sito && $s->isNelPeriodo())
                                    <span class="badge bg-success" title="Visibile in 'I nostri sostenitori'"><i class="bi bi-eye"></i></span>
                                @elseif ($s->visibile_sul_sito)
                                    <span class="badge bg-warning text-dark" title="Spuntato ma fuori periodo: non mostrato"><i class="bi bi-eye-slash"></i></span>
                                @else
                                    <span class="badge bg-light text-muted border"><i class="bi bi-eye-slash"></i></span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#editSponsor-{{ $s->id }}" aria-expanded="false" title="Modifica">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="{{ route('sponsor.destroy', $s) }}" class="d-inline"
                                      onsubmit="return confirm('Eliminare questo sponsor?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <tr class="collapse" id="editSponsor-{{ $s->id }}">
                            <td colspan="5" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('sponsor.update', $s) }}">
                                    @csrf
                                    @method('PUT')
                                    @include('relazioni.sponsor._form', ['sponsorCorrente' => $s, 'contatti' => $contatti])
                                    <div class="text-end mt-2">
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Salva modifiche</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $sponsor->links() }}
    @endif
@endsection
