{{-- Viste salvate (f.5 piano "Gestione 2"): barra salva/applica/elimina per
     le liste tessere/quote/prenotazioni. Riusa SavedFilter con scope per
     pagina; applicare una vista = navigare con i parametri salvati.

     Parametri: $scope (chiave di VisteSalvateController::SCOPES),
     $vsRoute (nome rotta della pagina), $vsRouteParams (parametri rotta,
     es. ['evento' => $evento] per le prenotazioni). --}}
@php
    $vsConfig = \App\Http\Controllers\Operativita\VisteSalvateController::SCOPES[$scope] ?? null;
    $vsRouteParams = $vsRouteParams ?? [];
    $visteSalvate = $vsConfig !== null
        ? \App\Models\SavedFilter::query()
            ->where('scope', $scope)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', auth()->id()))
            ->orderBy('name')
            ->get()
        : collect();
    $vistaCorrente = $visteSalvate->firstWhere('id', request('vista_id'));
@endphp

@if ($vsConfig !== null && \Illuminate\Support\Facades\Route::has('viste-salvate.store'))
    <div class="d-flex gap-2 flex-wrap align-items-center mt-2" id="visteSalvate-{{ $scope }}">
        <span class="small text-muted"><i class="bi bi-bookmark me-1"></i>Viste salvate:</span>

        @foreach ($visteSalvate as $vista)
            @php
                $vsParams = array_merge(
                    $vsRouteParams,
                    (array) $vista->params_json,
                    ['vista_id' => $vista->id]
                );
            @endphp
            <a href="{{ route($vsRoute, $vsParams) }}"
               class="btn btn-sm {{ $vistaCorrente?->id === $vista->id ? 'btn-primary' : 'btn-outline-secondary' }}">
                {{ $vista->name }}{{ $vista->isCondiviso() ? ' (condivisa)' : '' }}
            </a>
        @endforeach

        @if ($vistaCorrente)
            <form method="POST" action="{{ route('viste-salvate.destroy', $vistaCorrente) }}"
                  onsubmit="return confirm('Eliminare la vista «{{ $vistaCorrente->name }}»?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina vista">
                    <i class="bi bi-bookmark-x"></i>
                </button>
            </form>
        @endif

        <form method="POST" action="{{ route('viste-salvate.store', $scope) }}" class="d-flex gap-2 align-items-center ms-auto">
            @csrf
            @foreach ($vsConfig['params'] as $vsChiave)
                @if (request()->filled($vsChiave))
                    <input type="hidden" name="{{ $vsChiave }}" value="{{ request($vsChiave) }}">
                @endif
            @endforeach
            <input type="text" class="form-control form-control-sm" name="preset_name"
                   placeholder="Salva vista corrente..." required maxlength="255" style="width:170px"
                   aria-label="Nome della vista da salvare">
            <div class="form-check form-check-inline small m-0">
                <input class="form-check-input" type="checkbox" name="condiviso" id="vistaCondivisa-{{ $scope }}" value="1">
                <label class="form-check-label" for="vistaCondivisa-{{ $scope }}">Condivisa</label>
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-bookmark-plus"></i></button>
        </form>
    </div>
@endif
