{{-- Azioni in blocco sulla lista soci (f.2 piano "Gestione 2"): barra azioni
     che compare quando ci sono soci selezionati + modale di conferma con
     riepilogo. Partial autonomo (carica da sé tipi/categorie/sedi/tag) per
     non toccare il SocioController; logica in assets/js/soci-bulk.js. --}}
@if (\Illuminate\Support\Facades\Route::has('soci.azioni-blocco'))
    @php
        $bulkTipi = \App\Models\TipoSocio::query()->orderBy('nome')->get(['id', 'nome']);
        $bulkCategorie = \App\Models\CategoriaSocio::query()->orderBy('nome')->get(['id', 'nome']);
        $bulkSedi = \App\Models\Sede::query()->orderBy('nome')->get(['id', 'nome']);
        $bulkTags = \App\Models\Tag::query()->orderBy('nome_tag')->get(['id', 'nome_tag']);
    @endphp

    <div class="card mb-3 border-primary d-none" id="bulkBar" aria-live="polite">
        <div class="card-body py-2">
            <form method="POST" action="{{ route('soci.azioni-blocco') }}" id="bulkForm">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-2">
                        <span class="fw-semibold"><i class="bi bi-check2-square me-1"></i><span id="bulkCount">0</span> selezionati</span>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1" for="bulkAzione">Azione in blocco</label>
                        <select class="form-select form-select-sm" id="bulkAzione" name="azione">
                            <option value="stato">Cambia stato</option>
                            <option value="tipo_socio">Cambia tipo socio</option>
                            <option value="categoria">Cambia categoria</option>
                            <option value="sede">Cambia sede</option>
                            <option value="tag_assegna">Assegna tag</option>
                            <option value="tag_rimuovi">Rimuovi tag</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1" for="bulkValore">Valore</label>
                        <select class="form-select form-select-sm" id="bulkValore" name="valore" data-azione="stato">
                            @foreach (\App\Models\Socio::STATI as $stato)
                                <option value="{{ $stato }}">{{ $stato }}</option>
                            @endforeach
                        </select>
                        {{-- Opzioni per le altre azioni (lette dal JS) --}}
                        <template id="bulkOpzioni-stato">
                            @foreach (\App\Models\Socio::STATI as $stato)
                                <option value="{{ $stato }}">{{ $stato }}</option>
                            @endforeach
                        </template>
                        <template id="bulkOpzioni-tipo_socio">
                            @foreach ($bulkTipi as $tipo)
                                <option value="{{ $tipo->id }}">{{ $tipo->nome }}</option>
                            @endforeach
                        </template>
                        <template id="bulkOpzioni-categoria">
                            @foreach ($bulkCategorie as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->nome }}</option>
                            @endforeach
                        </template>
                        <template id="bulkOpzioni-sede">
                            @foreach ($bulkSedi as $sede)
                                <option value="{{ $sede->id }}">{{ $sede->nome }}</option>
                            @endforeach
                        </template>
                        <template id="bulkOpzioni-tag_assegna">
                            @foreach ($bulkTags as $tag)
                                <option value="{{ $tag->id }}">{{ $tag->nome_tag }}</option>
                            @endforeach
                        </template>
                        <template id="bulkOpzioni-tag_rimuovi">
                            @foreach ($bulkTags as $tag)
                                <option value="{{ $tag->id }}">{{ $tag->nome_tag }}</option>
                            @endforeach
                        </template>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="button" class="btn btn-sm btn-primary w-100" id="bulkApplica"
                                data-bs-toggle="modal" data-bs-target="#bulkConfermaModal">
                            <i class="bi bi-lightning-charge me-1"></i>Applica
                        </button>
                    </div>
                    <div class="col-6 col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100" id="bulkExport"
                                formaction="{{ route('soci.azioni-blocco.export') }}">
                            <i class="bi bi-file-earmark-excel me-1"></i>Esporta
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkAnnulla" title="Annulla selezione">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div id="bulkIdsContainer"></div>
            </form>
        </div>
    </div>

    {{-- Modale di conferma con riepilogo (spec f.2) --}}
    <div class="modal fade" id="bulkConfermaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Conferma azione in blocco</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Stai per applicare:</p>
                    <ul class="mb-2">
                        <li>Azione: <strong id="bulkRiepilogoAzione"></strong></li>
                        <li>Valore: <strong id="bulkRiepilogoValore"></strong></li>
                        <li>Soci interessati: <strong id="bulkRiepilogoCount"></strong></li>
                    </ul>
                    <p class="text-muted small mb-0">
                        L'operazione viene registrata nello storico attività di ogni socio.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" id="bulkConfermaSubmit">
                        <i class="bi bi-check-lg me-1"></i>Conferma
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/js/soci-bulk.js') }}" defer></script>
@endif
