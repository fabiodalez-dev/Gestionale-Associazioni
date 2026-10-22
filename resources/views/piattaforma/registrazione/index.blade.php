@extends('layouts.app')

@section('title', 'Richieste di iscrizione delle associazioni')

@section('content')
    @php
        $etichette = ['in_attesa' => 'Da esaminare', 'da_confermare' => 'Email da confermare', 'approvata' => 'Approvate', 'rifiutata' => 'Rifiutate', 'tutte' => 'Tutte'];
        $modalitaTesto = [
            'disattivata' => 'La registrazione autonoma è disattivata: le associazioni le crei solo tu.',
            'revisione' => 'Le associazioni possono iscriversi da sole; ogni richiesta aspetta la tua approvazione.',
            'automatica' => 'Le associazioni che si iscrivono vengono create subito, senza revisione.',
        ][$modalita];
    @endphp

    <div class="page-head">
        <div>
            <div class="crumb">Piattaforma / <span>Richieste di iscrizione</span></div>
            <h1>Richieste di iscrizione</h1>
        </div>
        <div class="actions">
            <a href="{{ route('impostazioni-sistema.index', ['sezione' => 'registrazione']) }}" class="btn btn-outline-secondary"><i class="bi bi-sliders"></i>Modalità di iscrizione</a>
            <a href="{{ route('associazioni.index') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i>Crea associazione</a>
        </div>
    </div>

    <div class="alert {{ $modalita === 'disattivata' ? 'alert-secondary' : 'alert-info' }} d-flex align-items-center gap-2">
        <i class="bi bi-info-circle"></i>
        <span>{{ $modalitaTesto }}
            @if ($modalita !== 'disattivata')
                Pagina pubblica: <a href="{{ route('registrazione.create') }}" target="_blank" rel="noopener">{{ route('registrazione.create') }}</a>
            @endif
        </span>
    </div>

    <div class="list-card">
        <nav class="nav nav-tabs" aria-label="Filtra per stato">
            @foreach ($etichette as $chiave => $etichetta)
                <a class="nav-link {{ $stato === $chiave ? 'active' : '' }}" href="{{ route('richieste-registrazione.index', ['stato' => $chiave]) }}">
                    {{ $etichetta }}
                    <span class="badge-count">{{ $chiave === 'tutte' ? $conteggi->sum() : ($conteggi[$chiave] ?? 0) }}</span>
                </a>
            @endforeach
        </nav>

        @if ($richieste->isEmpty())
            <div class="empty-state">
                <span class="icon-tile tone-4"><i class="bi bi-inbox"></i></span>
                <h3>Nessuna richiesta</h3>
                <p class="mb-0">Le richieste inviate dal modulo pubblico compaiono qui.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Associazione</th>
                            <th>Amministratore</th>
                            <th>Dettagli</th>
                            <th>Ricevuta</th>
                            <th>Stato</th>
                            <th class="text-end"><span class="visually-hidden">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($richieste as $richiesta)
                            <tr>
                                <td>
                                    <div class="who-cell">
                                        <span class="avatar avatar-square tone-{{ $loop->index % 6 }}">{{ mb_strtoupper(mb_substr($richiesta->nome, 0, 2)) }}</span>
                                        <div class="min-w-0">
                                            <div class="who-name">{{ $richiesta->nome }}</div>
                                            <div class="who-sub">{{ $richiesta->email }}{{ $richiesta->citta ? ' · '.$richiesta->citta : '' }}{{ $richiesta->provincia ? ' ('.$richiesta->provincia.')' : '' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-body">{{ trim($richiesta->admin_nome.' '.$richiesta->admin_cognome) }}</div>
                                    <div class="who-sub">{{ $richiesta->admin_email }}</div>
                                </td>
                                <td>
                                    @if ($richiesta->tipo)<span class="badge bg-secondary">{{ $richiesta->tipo }}</span>@endif
                                    @if ($richiesta->numero_soci_stimato)<span class="who-sub ms-1">~{{ number_format($richiesta->numero_soci_stimato, 0, ',', '.') }} soci</span>@endif
                                    @if ($richiesta->codice_fiscale)<div class="who-sub mono">{{ $richiesta->codice_fiscale }}</div>@endif
                                    @if ($richiesta->messaggio)<div class="who-sub text-wrap" style="max-width: 280px">“{{ \Illuminate\Support\Str::limit($richiesta->messaggio, 140) }}”</div>@endif
                                </td>
                                <td class="text-nowrap">
                                    <div>{{ $richiesta->created_at->format('d/m/Y') }}</div>
                                    <div class="who-sub">{{ $richiesta->created_at->format('H:i') }}</div>
                                </td>
                                <td>
                                    @if ($richiesta->stato === 'in_attesa' && ! $richiesta->isVerificata())
                                        <span class="badge bg-secondary">Email da confermare</span>
                                    @elseif ($richiesta->stato === 'in_attesa')
                                        <span class="badge bg-warning">Da esaminare</span>
                                    @elseif ($richiesta->stato === 'approvata')
                                        <span class="badge bg-success">Approvata</span>
                                        <div class="who-sub">{{ $richiesta->gestore ? 'da '.$richiesta->gestore->nome : 'automaticamente' }}</div>
                                    @else
                                        <span class="badge bg-danger">Rifiutata</span>
                                        @if ($richiesta->motivo_rifiuto)<div class="who-sub" title="{{ $richiesta->motivo_rifiuto }}">{{ \Illuminate\Support\Str::limit($richiesta->motivo_rifiuto, 40) }}</div>@endif
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($richiesta->isInAttesa() && ! $richiesta->isVerificata())
                                        <span class="who-sub">In attesa che {{ $richiesta->admin_email }} confermi</span>
                                    @elseif ($richiesta->isInAttesa())
                                        <form method="POST" action="{{ route('richieste-registrazione.approva', $richiesta) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i>Approva</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rifiuta-{{ $richiesta->id }}">Rifiuta</button>
                                        <div class="modal fade" id="rifiuta-{{ $richiesta->id }}" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <form method="POST" action="{{ route('richieste-registrazione.rifiuta', $richiesta) }}" class="modal-content text-start">
                                                    @csrf
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Rifiuta la richiesta di {{ $richiesta->nome }}</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <label class="form-label" for="motivo-{{ $richiesta->id }}">Motivo (viene inviato via email a {{ $richiesta->admin_email }})</label>
                                                        <textarea class="form-control" id="motivo-{{ $richiesta->id }}" name="motivo_rifiuto" rows="3" maxlength="1000"></textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                                                        <button type="submit" class="btn btn-danger">Rifiuta</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    @elseif ($richiesta->associazione)
                                        <form method="POST" action="{{ route('tenant.switch') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="associazione_id" value="{{ $richiesta->associazione->id }}">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Apri</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($richieste->hasPages())
                <div class="list-foot">{{ $richieste->links() }}</div>
            @endif
        @endif
    </div>
@endsection
