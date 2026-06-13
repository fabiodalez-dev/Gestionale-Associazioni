{{-- Galleria foto pubblica dell'evento (f.24): mostrata solo se galleria_pubblica
     è attiva e ci sono foto. "Com'è andata" anche per gli eventi passati. --}}
@if (!empty($galleriaPubblica) && $galleriaPubblica->isNotEmpty())
    <section class="mt-5">
        <h2 class="h4 mb-3"><i class="bi bi-images me-2"></i>Galleria foto</h2>
        <div class="row g-2">
            @foreach ($galleriaPubblica as $foto)
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="{{ $foto->getUrl() }}" target="_blank" rel="noopener" class="d-block">
                        <img src="{{ $foto->hasGeneratedConversion('thumb') ? $foto->getUrl('thumb') : $foto->getUrl() }}"
                             alt="Foto evento" class="img-fluid rounded shadow-sm w-100"
                             style="aspect-ratio: 4/3; object-fit: cover;" loading="lazy">
                    </a>
                </div>
            @endforeach
        </div>
    </section>
@endif
