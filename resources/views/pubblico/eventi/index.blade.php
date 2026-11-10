@extends('pubblico.layouts.app')

@section('title', 'Eventi — '.$associazione->nome)


@section('content')
    <div class="d-flex justify-content-between flex-wrap align-items-center mb-4 gap-2">
        <h1 class="h3 mb-0">Eventi in programma</h1>
        @if (Route::has('pubblico.calendario'))
            <a href="{{ route('pubblico.calendario', $associazione->slug) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-calendar3 me-1"></i>Calendario
            </a>
        @endif
    </div>

    <form method="GET" action="{{ route('pubblico.eventi.index', $associazione->slug) }}" id="filtri-eventi" class="row g-2 align-items-end mb-4" role="search">
        <div class="col-12 col-md-4">
            <label for="filtro-q" class="form-label small mb-1">Cerca</label>
            <input type="search" id="filtro-q" name="q" value="{{ $filtri['q'] }}" class="form-control" placeholder="Titolo, luogo…" maxlength="100" style="font-size:16px">
        </div>
        <div class="col-6 col-md-3">
            <label for="filtro-periodo" class="form-label small mb-1">Quando</label>
            <select id="filtro-periodo" name="periodo" class="form-select" style="font-size:16px">
                <option value="">Sempre</option>
                <option value="settimana" @selected($filtri['periodo'] === 'settimana')>Questa settimana</option>
                <option value="mese" @selected($filtri['periodo'] === 'mese')>Questo mese</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="filtro-prezzo" class="form-label small mb-1">Prezzo</label>
            <select id="filtro-prezzo" name="prezzo" class="form-select" style="font-size:16px">
                <option value="">Tutti</option>
                <option value="gratuiti" @selected($filtri['prezzo'] === 'gratuiti')>Gratuiti</option>
                <option value="a_pagamento" @selected($filtri['prezzo'] === 'a_pagamento')>A pagamento</option>
            </select>
        </div>
        <div class="col-12 col-md-2">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="filtro-posti" name="posti" value="1" @checked($filtri['posti'])>
                <label class="form-check-label small" for="filtro-posti">Con posti liberi</label>
            </div>
            <noscript><button type="submit" class="btn btn-primary btn-sm w-100">Filtra</button></noscript>
        </div>
    </form>

    <div id="elenco-eventi" aria-live="polite">
        @include('pubblico.eventi._elenco')
    </div>

    <script>
        (function () {
            var form = document.getElementById('filtri-eventi');
            var elenco = document.getElementById('elenco-eventi');
            if (!form || !window.fetch) return;
            var timer = null;
            function aggiorna() {
                var params = new URLSearchParams(new FormData(form));
                Array.from(params.keys()).forEach(function (k) { if (!params.get(k)) params.delete(k); });
                var url = form.action + (params.toString() ? '?' + params.toString() : '');
                fetch(url, { headers: { 'X-Elenco-Eventi': '1', 'Accept': 'text/html' } })
                    .then(function (r) { return r.ok ? r.text() : null; })
                    .then(function (html) {
                        if (html === null) return;
                        elenco.innerHTML = html;
                        history.replaceState(null, '', url);
                    })
                    .catch(function () { form.submit(); });
            }
            form.addEventListener('submit', function (e) { e.preventDefault(); aggiorna(); });
            form.addEventListener('change', aggiorna);
            document.getElementById('filtro-q').addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(aggiorna, 300);
            });
            elenco.addEventListener('click', function (e) {
                if (e.target.closest('[data-azzera-filtri]')) {
                    e.preventDefault();
                    form.reset();
                    form.querySelectorAll('input[type=search]').forEach(function (i) { i.value = ''; });
                    form.querySelectorAll('select').forEach(function (s) { s.value = ''; });
                    form.querySelector('#filtro-posti').checked = false;
                    aggiorna();
                }
            });
        })();
    </script>
@endsection
