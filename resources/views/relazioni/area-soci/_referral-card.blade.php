{{-- Card "porta un amico" (f.23): link di invito personale del socio.
     $socioReferral è iniettato dal RelazioniPublicComposer; $socio è il socio
     della dashboard. Si usa il referral del socio loggato. --}}
@php
    $rsocio = ($socioReferral ?? null) ?? (isset($socio) ? $socio : null);
    $slug = $rsocio?->associazione?->slug;
    $codice = $rsocio?->codice_referral;
    $linkInvito = ($slug && $codice && \Illuminate\Support\Facades\Route::has('pubblico.iscriviti'))
        ? route('pubblico.iscriviti', ['associazione' => $slug]).'?ref='.$codice
        : null;
@endphp
@if ($linkInvito !== null)
    <div class="col-12">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white"><i class="bi bi-megaphone me-2"></i>Porta un amico</div>
            <div class="card-body">
                <p class="mb-2">Invita i tuoi amici a iscriversi! Condividi il tuo link personale: quando si iscrivono con questo link, vieni conteggiato come ambasciatore.</p>
                <div class="input-group">
                    <input type="text" class="form-control" id="referralLink" value="{{ $linkInvito }}" readonly aria-label="Link di invito">
                    <button class="btn btn-outline-primary" type="button"
                            onclick="navigator.clipboard?.writeText(document.getElementById('referralLink').value); this.innerHTML='<i class=&quot;bi bi-check-lg&quot;></i> Copiato';">
                        <i class="bi bi-clipboard me-1"></i>Copia
                    </button>
                </div>
                <div class="small text-muted mt-2">Il tuo codice: <code>{{ $codice }}</code></div>
            </div>
        </div>
    </div>
@endif
