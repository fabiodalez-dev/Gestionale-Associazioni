{{-- Command palette ⌘K (f.1 piano "Gestione 2"): modale di ricerca globale
     dell'admin. Inclusa dal layout solo per gli utenti della guard web;
     la logica è in assets/js/global-search.js (esterno, CSP-safe). --}}
@if (auth()->user() instanceof \App\Models\Utente && \Illuminate\Support\Facades\Route::has('ricerca-globale'))
    <div class="modal fade" id="globalSearchModal" tabindex="-1" aria-hidden="true" aria-label="Ricerca globale"
         data-endpoint="{{ route('ricerca-globale') }}">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control border-0 shadow-none" id="globalSearchInput"
                               placeholder="Cerca soci, eventi, prenotazioni, documenti, pagine..."
                               autocomplete="off" aria-label="Cerca">
                        <button type="button" class="btn-close ms-2 align-self-center" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                </div>
                <div class="modal-body p-0" id="globalSearchResults" role="listbox" aria-label="Risultati ricerca">
                    <p class="text-muted small text-center my-3" id="globalSearchHint">
                        Digita almeno 2 caratteri. Usa <kbd>↑</kbd> <kbd>↓</kbd> e <kbd>Invio</kbd> per navigare.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <script src="{{ asset('assets/js/global-search.js') }}" defer></script>
@endif
