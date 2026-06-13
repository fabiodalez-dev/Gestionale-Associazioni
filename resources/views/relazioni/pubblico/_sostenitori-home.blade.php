{{-- I nostri sostenitori (f.22): solo sponsor visibili e nel periodo. --}}
@if (!empty($sostenitori) && $sostenitori->isNotEmpty())
    <section class="my-5">
        <div class="text-center mb-4">
            <h2 class="h4 mb-1">I nostri sostenitori</h2>
            <p class="text-muted small mb-0">Grazie a chi ci sostiene e rende possibile la nostra attività.</p>
        </div>
        <div class="row g-3 justify-content-center align-items-stretch">
            @foreach ($sostenitori as $sponsor)
                @php
                    $badge = match ($sponsor->livello) {
                        \App\Models\Sponsor::LIVELLO_ORO => ['text-bg-warning', 'Oro'],
                        \App\Models\Sponsor::LIVELLO_ARGENTO => ['text-bg-secondary', 'Argento'],
                        \App\Models\Sponsor::LIVELLO_BRONZO => ['text-bg-danger', 'Bronzo'],
                        default => ['text-bg-info', 'Partner'],
                    };
                @endphp
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="card h-100 text-center shadow-sm">
                        <div class="card-body d-flex flex-column justify-content-center">
                            @if (filled($sponsor->logo_url))
                                <img src="{{ $sponsor->logo_url }}" alt="{{ $sponsor->nome }}"
                                     class="img-fluid mb-2 mx-auto" style="max-height: 80px; object-fit: contain;">
                            @else
                                <i class="bi bi-building display-6 text-primary mb-2"></i>
                            @endif
                            <h3 class="h6 mb-1">
                                @if (filled($sponsor->sito_url))
                                    <a href="{{ $sponsor->sito_url }}" target="_blank" rel="noopener nofollow" class="text-decoration-none">{{ $sponsor->nome }}</a>
                                @else
                                    {{ $sponsor->nome }}
                                @endif
                            </h3>
                            <span class="badge {{ $badge[0] }} align-self-center">{{ $badge[1] }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
