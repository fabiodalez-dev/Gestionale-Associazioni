@php
    // Contatore "porta un amico" (f.23): iscrizioni APPROVATE arrivate con
    // il codice referral del socio. Lo scope tenant garantisce l'isolamento.
    $referralApprovate = \App\Models\RichiestaIscrizione::query()
        ->where('referral_codice', $socio->codice_referral)
        ->where('stato', \App\Models\RichiestaIscrizione::STATO_APPROVATA)
        ->count();
@endphp
@if ($referralApprovate > 0)
    <a href="{{ \Illuminate\Support\Facades\Route::has('ambasciatori.index') ? route('ambasciatori.index') : '#' }}"
       class="badge bg-success text-decoration-none" title="Iscrizioni approvate portate come ambasciatore (f.23)">
        <i class="bi bi-megaphone me-1"></i>{{ $referralApprovate }} {{ $referralApprovate === 1 ? 'amico portato' : 'amici portati' }}
    </a>
@endif
