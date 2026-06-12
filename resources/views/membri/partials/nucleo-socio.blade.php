{{-- Nucleo familiare nella scheda socio (f.20 — piano "Gestione 2").
     Variabile: $socio. Partial autonomo: i lookup (nuclei del tenant)
     sono calcolati qui per non toccare il controller condiviso. --}}
@php
    $nucleoSocio = $socio->nucleo_id !== null
        ? \App\Models\Nucleo::query()->with([
            'capofamiglia' => fn ($q) => $q->withTrashed(),
            'membri' => fn ($q) => $q->orderBy('cognome')->orderBy('nome'),
        ])->find($socio->nucleo_id)
        : null;
    $nucleiDisponibili = \App\Models\Nucleo::query()->orderBy('nome')->get(['id', 'nome']);
    $puoGestire = auth()->user()?->can('create', \App\Models\Nucleo::class) ?? false;
@endphp

<div class="card mt-4" id="nucleo-familiare">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-house-heart me-1"></i>Nucleo familiare</span>
        @if (\Illuminate\Support\Facades\Route::has('nuclei.index'))
            <a href="{{ route('nuclei.index') }}" class="small text-decoration-none">Vista per nuclei <i class="bi bi-arrow-right"></i></a>
        @endif
    </div>
    <div class="card-body">
        @if ($nucleoSocio !== null)
            <p class="mb-2">
                <strong>{{ $nucleoSocio->nome }}</strong>
                @if ($nucleoSocio->capofamiglia_id === $socio->id)
                    <span class="badge bg-primary"><i class="bi bi-star-fill me-1"></i>Capofamiglia</span>
                @elseif ($nucleoSocio->capofamiglia)
                    <span class="text-muted small">— capofamiglia:
                        <a href="{{ route('soci.show', $nucleoSocio->capofamiglia) }}" class="text-decoration-none">{{ $nucleoSocio->capofamiglia->nome_completo }}</a>
                    </span>
                @endif
            </p>

            <ul class="list-unstyled mb-3">
                @foreach ($nucleoSocio->membri as $membro)
                    <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span>
                            @if ($membro->id === $socio->id)
                                {{ $membro->cognome }} {{ $membro->nome }} <span class="text-muted small">(questo socio)</span>
                            @else
                                <a href="{{ route('soci.show', $membro) }}" class="text-decoration-none">{{ $membro->cognome }} {{ $membro->nome }}</a>
                            @endif
                            @if ($membro->isMinorenne())
                                <span class="badge bg-info text-dark">Minorenne</span>
                            @endif
                            @if ($nucleoSocio->capofamiglia_id === $membro->id)
                                <i class="bi bi-star-fill text-primary small" title="Capofamiglia"></i>
                            @endif
                        </span>
                        @if ($puoGestire)
                            <form method="POST" action="{{ route('nuclei.membri.destroy', [$nucleoSocio, $membro]) }}" class="d-inline"
                                  onsubmit="return confirm('Rimuovere {{ $membro->nome_completo }} dal nucleo?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="Rimuovi dal nucleo">
                                    <i class="bi bi-x"></i>
                                </button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($puoGestire)
                {{-- Aggiungi un altro socio al nucleo --}}
                <form method="POST" action="{{ route('nuclei.membri.store', $nucleoSocio) }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-md-8">
                        <label class="form-label small mb-1" for="nucleo-aggiungi-socio">Aggiungi un socio al nucleo</label>
                        <select class="form-select form-select-sm" id="nucleo-aggiungi-socio" name="socio_id" required>
                            <option value="">— Seleziona socio —</option>
                            @foreach (\App\Models\Socio::query()->where('id', '!=', $socio->id)->whereNull('nucleo_id')->orderBy('cognome')->orderBy('nome')->get(['id', 'nome', 'cognome', 'numero_socio']) as $candidato)
                                <option value="{{ $candidato->id }}">{{ $candidato->cognome }} {{ $candidato->nome }} (n. {{ $candidato->numero_socio }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-person-plus me-1"></i>Aggiungi</button>
                    </div>
                </form>

                {{-- Rinomina / cambia capofamiglia --}}
                <form method="POST" action="{{ route('nuclei.update', $nucleoSocio) }}" class="row g-2 align-items-end">
                    @csrf
                    @method('PUT')
                    <div class="col-md-5">
                        <label class="form-label small mb-1" for="nucleo-nome">Nome nucleo</label>
                        <input type="text" class="form-control form-control-sm" id="nucleo-nome" name="nome"
                               maxlength="150" required value="{{ old('nome', $nucleoSocio->nome) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="nucleo-capofamiglia">Capofamiglia</label>
                        <select class="form-select form-select-sm" id="nucleo-capofamiglia" name="capofamiglia_id">
                            <option value="">— Nessuno —</option>
                            @foreach ($nucleoSocio->membri as $membro)
                                <option value="{{ $membro->id }}" @selected($nucleoSocio->capofamiglia_id === $membro->id)>{{ $membro->cognome }} {{ $membro->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check-lg me-1"></i>Aggiorna</button>
                    </div>
                </form>
            @endif
        @else
            <p class="text-muted mb-3">Questo socio non appartiene a nessun nucleo familiare.</p>

            @if ($puoGestire)
                <div class="row g-3">
                    {{-- Crea un nuovo nucleo con questo socio come capofamiglia --}}
                    <div class="col-md-6">
                        <form method="POST" action="{{ route('nuclei.store') }}" class="row g-2 align-items-end">
                            @csrf
                            <input type="hidden" name="capofamiglia_id" value="{{ $socio->id }}">
                            <div class="col-8">
                                <label class="form-label small mb-1" for="nuovo-nucleo-nome">Crea nucleo (capofamiglia: questo socio)</label>
                                <input type="text" class="form-control form-control-sm" id="nuovo-nucleo-nome" name="nome"
                                       maxlength="150" required placeholder="Es. Famiglia {{ $socio->cognome }}" value="{{ old('nome') }}">
                            </div>
                            <div class="col-4">
                                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Crea</button>
                            </div>
                        </form>
                    </div>

                    {{-- Oppure aggiungi il socio a un nucleo esistente --}}
                    @if ($nucleiDisponibili->isNotEmpty())
                        <div class="col-md-6" id="aggiungi-a-nucleo">
                            <label class="form-label small mb-1" for="nucleo-esistente">Oppure aggiungi a un nucleo esistente</label>
                            <div class="d-flex gap-2">
                                <select class="form-select form-select-sm" id="nucleo-esistente"
                                        onchange="document.getElementById('formAggiungiANucleo').action = this.options[this.selectedIndex].dataset.action || '';">
                                    <option value="">— Seleziona nucleo —</option>
                                    @foreach ($nucleiDisponibili as $nucleoOpzione)
                                        <option value="{{ $nucleoOpzione->id }}" data-action="{{ route('nuclei.membri.store', $nucleoOpzione) }}">{{ $nucleoOpzione->nome }}</option>
                                    @endforeach
                                </select>
                                <form method="POST" action="" id="formAggiungiANucleo"
                                      onsubmit="return this.action !== '' && this.action !== window.location.href;">
                                    @csrf
                                    <input type="hidden" name="socio_id" value="{{ $socio->id }}">
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-person-plus me-1"></i>Aggiungi</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
