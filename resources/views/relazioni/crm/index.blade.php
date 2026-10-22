@extends('layouts.app')

@section('title', 'CRM - '.session('app_name', config('app.name')))

@section('content')
    @php
        $euro = fn ($v) => '€ '.number_format((float) $v, 0, ',', '.');
        $coloriFase = ['tone-4', 'tone-2', 'tone-1', 'tone-3', 'tone-ok'];
        $puntiFase = ['var(--ds-text-subtle)', 'var(--ds-info)', 'var(--ds-warning)', 'var(--ds-pink-ink)', 'var(--ds-success)'];
        $coloriOrigine = ['modulo_web' => 'tone-0', 'passaparola' => 'tone-5', 'open_day' => 'tone-1', 'evento' => 'tone-2', 'social' => 'tone-3', 'contatto_diretto' => 'tone-4', 'altro' => 'tone-4'];
        $iniziali = fn (?string $t) => mb_strtoupper(collect(preg_split('/\s+/', trim((string) $t)) ?: [])->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('')) ?: '?';
        $puoModificare = auth()->user()->can('create', \App\Models\OpportunitaCrm::class);
        $infoPipeline = \App\Models\OpportunitaCrm::PIPELINE[$pipeline];
    @endphp

    <div class="page-head">
        <div>
            <div class="crumb">Relazioni / <span>CRM</span></div>
            <h1>{{ $infoPipeline['titolo'] }}</h1>
        </div>
        <div class="actions">
            <nav class="segmented" aria-label="Pipeline">
                @foreach (\App\Models\OpportunitaCrm::PIPELINE as $chiave => $p)
                    <a href="{{ route('crm.index', ['pipeline' => $chiave]) }}" class="{{ $pipeline === $chiave ? 'active' : '' }}">{{ $p['nome'] }}</a>
                @endforeach
            </nav>
            @if ($puoModificare)
                <button type="button" class="btn btn-primary" data-crm-nuovo="{{ array_key_first($fasi) }}"><i class="bi bi-plus-lg"></i>Nuovo contatto</button>
            @endif
        </div>
    </div>

    <div class="metrics-strip mb-4">
        <div class="metric-cell">
            <div class="metric-head"><span class="metric-chip"><i class="bi bi-currency-euro"></i></span><span class="metric-label">Valore in corso</span></div>
            <div class="metric-value">{{ $euro($kpi['valore']) }}</div>
        </div>
        <div class="metric-cell metric-info">
            <div class="metric-head"><span class="metric-chip"><i class="bi bi-people"></i></span><span class="metric-label">Contatti aperti</span></div>
            <div class="metric-value">{{ $kpi['aperte'] }}</div>
        </div>
        <div class="metric-cell metric-ok">
            <div class="metric-head"><span class="metric-chip"><i class="bi bi-bullseye"></i></span><span class="metric-label">Conversione 90 giorni</span></div>
            <div class="metric-value">{{ $kpi['conversione'] === null ? '—' : $kpi['conversione'].'%' }}</div>
        </div>
        <div class="metric-cell {{ $kpi['ritardo'] > 0 ? 'metric-danger' : '' }}">
            <div class="metric-head"><span class="metric-chip"><i class="bi bi-alarm"></i></span><span class="metric-label">Azioni in ritardo</span></div>
            <div class="metric-value">{{ $kpi['ritardo'] }}</div>
        </div>
    </div>

    <div class="kanban" id="kanban" data-sposta-url="{{ url('/crm') }}" data-pipeline="{{ $pipeline }}" data-modificabile="{{ $puoModificare ? '1' : '0' }}">
        @foreach ($fasi as $chiaveFase => $nomeFase)
            @php
                $schede = $colonne->get($chiaveFase, collect());
                $indice = $loop->index;
            @endphp
            <section class="kanban-col" aria-label="{{ $nomeFase }}">
                <header class="kanban-head">
                    <span class="kanban-dot" style="background: {{ $puntiFase[min($indice, 4)] }}"></span>
                    <h2>{{ $nomeFase }}</h2>
                    <span class="badge-count" data-conta>{{ $schede->count() }}</span>
                    <span class="kanban-sum" data-somma>{{ $euro($schede->sum('valore')) }}</span>
                </header>
                <div class="kanban-list" data-fase="{{ $chiaveFase }}">
                    @foreach ($schede as $o)
                        @php
                            $datiScheda = [
                                'id' => $o->id, 'fase' => $o->fase, 'titolo' => $o->titolo, 'descrizione' => $o->descrizione,
                                'origine' => $o->origine, 'valore' => $o->valore, 'prossima_azione_il' => $o->prossima_azione_il?->toDateString(),
                                'email' => $o->email, 'telefono' => $o->telefono, 'responsabile_id' => $o->responsabile_id, 'esito' => $o->esito,
                            ];
                        @endphp
                        <article class="kanban-card {{ $o->esito === 'vinta' ? 'is-won' : '' }}" data-id="{{ $o->id }}" data-valore="{{ (float) $o->valore }}" data-scheda='@json($datiScheda)' tabindex="0" role="button" aria-label="Apri {{ $o->titolo }}">
                            @if ($o->origine)
                                <span class="badge {{ $coloriOrigine[$o->origine] ?? 'tone-4' }}">{{ \App\Models\OpportunitaCrm::ORIGINI[$o->origine] ?? $o->origine }}</span>
                            @endif
                            <div class="kanban-title">{{ $o->titolo }}</div>
                            @if ($o->descrizione)
                                <div class="kanban-desc">{{ \Illuminate\Support\Str::limit($o->descrizione, 90) }}</div>
                            @endif
                            <div class="kanban-foot">
                                @if ($o->esito === 'vinta')
                                    <span class="text-success"><i class="bi bi-check2-circle"></i>{{ $o->chiusa_at?->format('d/m') }}</span>
                                @elseif ($o->prossima_azione_il)
                                    <span class="{{ $o->inRitardo() ? 'text-danger fw-medium' : '' }}" title="Prossima azione"><i class="bi bi-clock"></i>{{ $o->prossima_azione_il->isToday() ? 'Oggi' : ($o->prossima_azione_il->isTomorrow() ? 'Domani' : $o->prossima_azione_il->locale('it')->translatedFormat('j M')) }}</span>
                                @endif
                                @if ($o->valore !== null)
                                    <span class="fw-semibold text-body">{{ $euro($o->valore) }}</span>
                                @endif
                                @if ($o->responsabile)
                                    <span class="avatar avatar-sm ms-auto tone-{{ abs(crc32((string) $o->responsabile_id)) % 6 }}" title="{{ $o->responsabile->nome_completo }}">{{ $iniziali($o->responsabile->nome_completo) }}</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($puoModificare)
                    <button type="button" class="kanban-add" data-crm-nuovo="{{ $chiaveFase }}"><i class="bi bi-plus"></i>Aggiungi</button>
                @endif
            </section>
        @endforeach
    </div>

    @if ($perse->isNotEmpty())
        <details class="card mt-4">
            <summary class="card-body py-3 fw-semibold text-body">Non andati a buon fine ({{ $perse->count() }})</summary>
            <ul class="panel-list">
                @foreach ($perse as $o)
                    <li>
                        <span><span class="text-body fw-medium">{{ $o->titolo }}</span> <span class="who-sub">· chiuso il {{ $o->chiusa_at?->format('d/m/Y') }}</span></span>
                        @if ($puoModificare)
                            <form method="POST" action="{{ route('crm.riapri', $o) }}" class="m-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Riapri</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    @if ($puoModificare)
        <div class="modal fade" id="crmModal" tabindex="-1" aria-hidden="true" aria-labelledby="crmModalTitolo">
            <div class="modal-dialog modal-lg">
                <form method="POST" action="{{ route('crm.store') }}" class="modal-content" id="crmForm" data-store="{{ route('crm.store') }}" data-base="{{ url('/crm') }}">
                    @csrf
                    <input type="hidden" name="_method" value="POST" id="crmMetodo">
                    <input type="hidden" name="pipeline" value="{{ $pipeline }}" id="crmPipeline">
                    <div class="modal-header">
                        <h5 class="modal-title" id="crmModalTitolo">Nuovo contatto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        @if ($errors->any())
                            <div class="alert alert-danger">@foreach ($errors->all() as $m)<div>{{ $m }}</div>@endforeach</div>
                        @endif
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label" for="crm_titolo">Nome *</label>
                                <input type="text" class="form-control" id="crm_titolo" name="titolo" maxlength="255" required value="{{ old('titolo') }}" placeholder="{{ $pipeline === 'sponsor' ? 'Es. Ottica Visione' : 'Es. Roberto Fabbri' }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="crm_fase">Fase</label>
                                <select class="form-select" id="crm_fase" name="fase">
                                    @foreach ($fasi as $k => $n)
                                        <option value="{{ $k }}" @selected(old('fase') === $k)>{{ $n }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="crm_descrizione">Note</label>
                                <input type="text" class="form-control" id="crm_descrizione" name="descrizione" maxlength="500" value="{{ old('descrizione') }}" placeholder="Es. Interessato a pallavolo amatori">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="crm_origine">Origine</label>
                                <select class="form-select" id="crm_origine" name="origine">
                                    <option value="">—</option>
                                    @foreach (\App\Models\OpportunitaCrm::ORIGINI as $k => $n)
                                        <option value="{{ $k }}" @selected(old('origine') === $k)>{{ $n }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="crm_valore">Valore (€)</label>
                                <input type="number" class="form-control" id="crm_valore" name="valore" min="0" step="0.01" value="{{ old('valore') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="crm_azione">Prossima azione</label>
                                <input type="date" class="form-control" id="crm_azione" name="prossima_azione_il" value="{{ old('prossima_azione_il') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="crm_email">Email</label>
                                <input type="email" class="form-control" id="crm_email" name="email" value="{{ old('email') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="crm_telefono">Telefono</label>
                                <input type="tel" class="form-control" id="crm_telefono" name="telefono" maxlength="30" value="{{ old('telefono') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="crm_responsabile">Responsabile</label>
                                <select class="form-select" id="crm_responsabile" name="responsabile_id">
                                    <option value="">—</option>
                                    @foreach ($responsabili as $u)
                                        <option value="{{ $u->id }}" @selected(old('responsabile_id') === $u->id)>{{ $u->nome_completo }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <div class="d-flex gap-2" id="crmAzioniScheda" hidden>
                            @if ($pipeline === 'adesioni')
                                <a href="#" class="btn btn-soft" id="crmCreaSocio" data-base="{{ route('soci.create') }}"><i class="bi bi-person-plus"></i>Iscrivi come socio</a>
                            @endif
                            <button type="submit" form="crmPersa" class="btn btn-outline-secondary">Non andato a buon fine</button>
                            <button type="submit" form="crmElimina" class="btn btn-outline-danger" onclick="return confirm('Eliminare questo contatto?')"><i class="bi bi-trash"></i></button>
                        </div>
                        <div class="d-flex gap-2 ms-auto">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                            <button type="submit" class="btn btn-primary">Salva</button>
                        </div>
                    </div>
                </form>
                <form method="POST" id="crmPersa" class="d-none">@csrf</form>
                <form method="POST" id="crmElimina" class="d-none">@csrf @method('DELETE')</form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('assets/vendor/sortablejs/Sortable.min.js') }}"></script>
    <script src="{{ asset('assets/js/crm.js') }}"></script>
    @if ($errors->any())
        <script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('crmModal')).show(); });</script>
    @endif
@endpush
