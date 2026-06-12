{{-- Bottone + modale "Invia email" sulla scheda socio (f.12 piano Gestione 2).
     Variabile: $socio. Visibile solo con permesso di invio (email.create). --}}
@can('send', \App\Models\EmailQueue::class)
    @if (filled($socio->email) && ! $socio->email_opt_out)
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal"
                data-bs-target="#inviaEmailSocioModal">
            <i class="bi bi-envelope-plus me-1"></i>Invia email
        </button>

        <div class="modal fade" id="inviaEmailSocioModal" tabindex="-1"
             aria-labelledby="inviaEmailSocioLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST" action="{{ route('soci.invia-email', $socio) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="inviaEmailSocioLabel">
                                <i class="bi bi-envelope-plus me-1"></i>Invia email a {{ $socio->nome_completo }}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">
                                Destinatario: <strong>{{ $socio->email }}</strong> — l'email parte dalla coda
                                di invio con il layout della tua associazione.
                            </p>
                            <div class="mb-3">
                                <label class="form-label" for="invia-email-oggetto">Oggetto *</label>
                                <input type="text" class="form-control" id="invia-email-oggetto"
                                       name="oggetto" maxlength="255" required value="{{ old('oggetto') }}">
                            </div>
                            <div class="mb-1">
                                <label class="form-label" for="invia-email-corpo">Messaggio *</label>
                                <textarea class="form-control" id="invia-email-corpo" name="corpo"
                                          rows="8" required>{{ old('corpo') }}</textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Accoda invio</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endcan
