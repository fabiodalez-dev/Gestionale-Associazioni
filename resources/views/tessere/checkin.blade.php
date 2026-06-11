<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Check-in Evento</title>
    <meta name="theme-color" content="#FF7B11">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        :root { --brand: #FF7B11; }
        body { background: #f5f5f5; min-height: 100vh; min-height: 100dvh; }
        .checkin-header { background: var(--brand); color: #fff; padding: 12px 16px; position: sticky; top: 0; z-index: 100; }
        .checkin-header h1 { font-size: 1.1rem; margin: 0; }
        .counter { font-size: 1.1rem; font-weight: 700; }
        .counter .num { font-size: 1.6rem; }

        .event-card { cursor: pointer; transition: transform .15s, box-shadow .15s; }
        .event-card:hover, .event-card:focus { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .event-card.today { border-left: 4px solid var(--brand); }

        #qr-reader { width: 100%; max-width: 400px; margin: 0 auto; border-radius: 12px; overflow: hidden; }
        #qr-reader video { border-radius: 12px; }

        .result-overlay {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            animation: fadeIn .2s ease;
        }
        .result-overlay .result-card {
            text-align: center; padding: 40px 32px; border-radius: 20px;
            color: #fff; min-width: 280px; max-width: 90vw;
            box-shadow: 0 8px 32px rgba(0,0,0,.3);
        }
        .result-overlay .result-card .icon { font-size: 3.5rem; }
        .result-overlay .result-card .name { font-size: 1.5rem; font-weight: 700; margin-top: 12px; }
        .result-overlay .result-card .msg { font-size: 1rem; margin-top: 8px; opacity: .9; }
        .result-ok { background: rgba(25,135,84,.97); }
        .result-dup { background: rgba(255,193,7,.97); color: #333 !important; }
        .result-dup .msg { color: #333 !important; }
        .result-err { background: rgba(220,53,69,.97); }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .recent-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eee; }
        .recent-item .ri-icon { font-size: 1.2rem; }
        .recent-item .ri-name { flex: 1; font-weight: 500; }
        .recent-item .ri-time { color: #888; font-size: .85rem; }
    </style>
</head>
<body>

<!-- ============ SCHERMATA: Selezione evento ============ -->
<div id="screen-events">
    <div class="checkin-header d-flex justify-content-between align-items-center">
        <h1><i class="bi bi-calendar-event me-2"></i>Seleziona Evento</h1>
        <a class="btn btn-sm btn-outline-light" href="{{ route('tessere.index') }}"><i class="bi bi-box-arrow-left"></i></a>
    </div>
    <div class="container py-3">
        @if ($eventi->isEmpty())
            <div class="text-center py-5">
                <i class="bi bi-calendar-x" style="font-size:3rem;color:#ccc"></i>
                <p class="text-muted mt-2">Nessun evento trovato.</p>
            </div>
        @else
            <div class="row g-3">
                @foreach ($eventi as $evento)
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card event-card {{ $evento->data_evento?->isToday() ? 'today' : '' }}"
                             role="button" tabindex="0"
                             data-evento-id="{{ $evento->id }}"
                             data-evento-titolo="{{ $evento->titolo }}">
                            <div class="card-body">
                                <h6 class="mb-1">{{ $evento->titolo }}</h6>
                                <div class="small text-muted">
                                    <i class="bi bi-clock me-1"></i>{{ $evento->data_evento?->format('d/m/Y H:i') }}
                                    @if ($evento->luogo)
                                        &middot; <i class="bi bi-geo-alt me-1"></i>{{ $evento->luogo }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<!-- ============ SCHERMATA: Scanner check-in ============ -->
<div id="screen-checkin" class="d-none">
    <div class="checkin-header">
        <div class="d-flex justify-content-between align-items-center">
            <h1 id="checkin-event-name">Evento</h1>
            <button class="btn btn-sm btn-outline-light" id="btnChangeEvent"><i class="bi bi-arrow-left me-1"></i>Cambia</button>
        </div>
        <div class="counter mt-1">
            <i class="bi bi-people-fill me-1"></i>
            <span class="num" id="checkin-counter">0</span> ingressi registrati
        </div>
    </div>

    <div class="container py-3">
        <!-- QR Scanner -->
        <div id="qr-reader" class="mb-3"></div>

        <!-- Input manuale -->
        <form id="manualForm" class="d-flex gap-2 mb-4" style="max-width:400px;margin:0 auto">
            <input type="text" id="manualInput" class="form-control" placeholder="Numero tessera o codice PRN-..." style="min-height:44px;font-size:16px">
            <button type="submit" class="btn" style="background:var(--brand);color:#fff;white-space:nowrap;min-height:44px"><i class="bi bi-check-lg"></i> OK</button>
        </form>

        <!-- Ultimi check-in -->
        <div style="max-width:400px;margin:0 auto">
            <h6 class="text-muted"><i class="bi bi-clock-history me-1"></i>Ultimi check-in</h6>
            <div id="recent-list"></div>
        </div>
    </div>
</div>

<!-- Overlay risultato -->
<div id="result-overlay" class="result-overlay d-none" role="alert"></div>

<script src="{{ asset('assets/vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
<script>
(function() {
    'use strict';

    var CHECKIN_URL = @json(route('tessere.checkin.store'));
    var BIGLIETTO_CHECKIN_URL = @json(route('biglietti.checkin'));
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var currentEventId = null;
    var checkinCount = 0;
    var scanner = null;
    var scanCooldown = false;

    var screenEvents = document.getElementById('screen-events');
    var screenCheckin = document.getElementById('screen-checkin');
    var checkinEventName = document.getElementById('checkin-event-name');
    var checkinCounter = document.getElementById('checkin-counter');
    var manualInput = document.getElementById('manualInput');
    var recentListEl = document.getElementById('recent-list');
    var overlay = document.getElementById('result-overlay');

    // ---- Selezione evento ----
    document.querySelectorAll('.event-card').forEach(function(card) {
        function select() {
            currentEventId = card.dataset.eventoId;
            checkinEventName.textContent = card.dataset.eventoTitolo;
            checkinCount = 0;
            checkinCounter.textContent = '0';
            recentListEl.textContent = '';
            screenEvents.classList.add('d-none');
            screenCheckin.classList.remove('d-none');
            startScanner();
        }
        card.addEventListener('click', select);
        card.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(); } });
    });

    document.getElementById('btnChangeEvent').addEventListener('click', function() {
        stopScanner();
        screenCheckin.classList.add('d-none');
        screenEvents.classList.remove('d-none');
        currentEventId = null;
    });

    // ---- Scanner ----
    function startScanner() {
        if (scanner) return;
        scanner = new Html5Qrcode('qr-reader');
        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            onQrScanned,
            function() {}
        ).catch(function() {
            scanner = null;
            var el = document.getElementById('qr-reader');
            el.textContent = '';
            var warn = document.createElement('div');
            warn.className = 'alert alert-warning m-3';
            warn.textContent = 'Fotocamera non disponibile. Usa l\'inserimento manuale.';
            el.appendChild(warn);
        });
    }

    function stopScanner() {
        if (!scanner) return;
        try { scanner.stop().catch(function() {}); } catch (e) {}
        scanner = null;
        document.getElementById('qr-reader').textContent = '';
    }

    function onQrScanned(text) {
        if (scanCooldown) return;
        scanCooldown = true;
        setTimeout(function() { scanCooldown = false; }, 3000);

        // Auto-riconoscimento payload:
        // - URL con /verifica-biglietto/{64 hex} → flusso biglietto;
        // - URL di verifica tessera → uuid tessera;
        // - altrimenti testo grezzo (token biglietto, codice PRN o numero tessera).
        var uuid = '';
        try {
            var url = new URL(text);
            var mb = url.pathname.match(/verifica-biglietto\/([a-f0-9]{64})/i);
            if (mb) {
                doCheckinBiglietto({ qr_token: mb[1].toLowerCase() });
                return;
            }
            uuid = url.searchParams.get('t') || '';
            if (!uuid) {
                var m = url.pathname.match(/verifica-tessera\/([a-f0-9-]{36})/i);
                if (m) uuid = m[1];
            }
        } catch (e) {
            uuid = text;
        }

        if (!uuid) return;

        if (/^[a-f0-9]{64}$/i.test(uuid)) {
            doCheckinBiglietto({ qr_token: uuid.toLowerCase() });
        } else if (/^PRN-/i.test(uuid)) {
            doCheckinBiglietto({ codice: uuid.toUpperCase() });
        } else if (/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(uuid)) {
            doCheckin({ tessera_id: uuid });
        } else {
            doCheckin({ numero_tessera: uuid });
        }
    }

    // ---- Input manuale (numero tessera, numero socio o codice biglietto PRN) ----
    document.getElementById('manualForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var val = manualInput.value.trim();
        if (!val) return;
        if (/^PRN-/i.test(val)) {
            doCheckinBiglietto({ codice: val.toUpperCase() });
        } else if (/^[a-f0-9]{64}$/i.test(val)) {
            doCheckinBiglietto({ qr_token: val.toLowerCase() });
        } else {
            doCheckin({ numero_tessera: val });
        }
    });

    // ---- Check-in ----
    function doCheckin(identifier) {
        if (!currentEventId) return;

        var payload = Object.assign({ evento_id: currentEventId }, identifier);

        fetch(CHECKIN_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF
            },
            body: JSON.stringify(payload)
        })
            .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, data: data }; }); })
            .then(function(res) { showResult(res.data); })
            .catch(function() {
                showOverlay('result-err', 'bi-wifi-off', 'Errore di rete', 'Verifica la connessione e riprova.');
            });
    }

    // ---- Check-in biglietto (prenotazione frontend pubblico) ----
    function doCheckinBiglietto(identifier) {
        if (!currentEventId) return;

        var payload = Object.assign({ evento_id: currentEventId }, identifier);

        fetch(BIGLIETTO_CHECKIN_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF
            },
            body: JSON.stringify(payload)
        })
            .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, data: data }; }); })
            .then(function(res) { showResultBiglietto(res.data); })
            .catch(function() {
                showOverlay('result-err', 'bi-wifi-off', 'Errore di rete', 'Verifica la connessione e riprova.');
            });
    }

    function showResultBiglietto(data) {
        var b = data.biglietto || null;
        var nome = b ? ((b.account_nome || '') + ' ' + (b.account_cognome || '')).trim() : '';
        var posti = b && b.posti ? (b.posti === 1 ? '1 posto' : b.posti + ' posti') : '';

        if (data.success && !data.already_checked_in) {
            checkinCount++;
            checkinCounter.textContent = String(checkinCount);
            addRecent('bi-ticket-perforated-fill text-success', nome + (posti ? ' · ' + posti : ''));
            showOverlay('result-ok', 'bi-ticket-perforated-fill', nome, (posti ? posti + ' · ' : '') + (data.message || 'Check-in registrato.'));
        } else if (data.success && data.already_checked_in) {
            addRecent('bi-exclamation-circle text-warning', nome + ' (già utilizzato)');
            showOverlay('result-dup', 'bi-exclamation-circle-fill', nome, data.message || 'Biglietto già utilizzato.');
        } else {
            var errMsg = data.error || (data.errors ? Object.values(data.errors)[0][0] : 'Errore durante il check-in.');
            showOverlay('result-err', 'bi-x-circle-fill', nome || 'Biglietto non valido', errMsg);
        }
        manualInput.value = '';
    }

    function showResult(data) {
        if (data.success && !data.already_checked_in) {
            checkinCount++;
            checkinCounter.textContent = String(checkinCount);
            var nome = (data.socio ? (data.socio.nome + ' ' + data.socio.cognome) : '');
            addRecent('bi-check-circle-fill text-success', nome);
            showOverlay('result-ok', 'bi-check-circle-fill', nome, data.message || 'Check-in effettuato.');
        } else if (data.success && data.already_checked_in) {
            var nomeDup = (data.socio ? (data.socio.nome + ' ' + data.socio.cognome) : '');
            addRecent('bi-exclamation-circle text-warning', nomeDup + ' (già registrato)');
            showOverlay('result-dup', 'bi-exclamation-circle-fill', nomeDup, data.message || 'Già registrato.');
        } else {
            var errMsg = data.error || (data.errors ? Object.values(data.errors)[0][0] : 'Errore durante il check-in.');
            showOverlay('result-err', 'bi-x-circle-fill', 'Errore', errMsg);
        }
        manualInput.value = '';
    }

    function showOverlay(cls, iconCls, nameText, msgText) {
        overlay.textContent = '';
        var card = document.createElement('div');
        card.className = 'result-card ' + cls;

        var icon = document.createElement('i');
        icon.className = 'bi ' + iconCls + ' icon';
        card.appendChild(icon);

        var name = document.createElement('div');
        name.className = 'name';
        name.textContent = nameText || '';
        card.appendChild(name);

        var msg = document.createElement('div');
        msg.className = 'msg';
        msg.textContent = msgText || '';
        card.appendChild(msg);

        overlay.appendChild(card);
        overlay.classList.remove('d-none');
        setTimeout(function() { overlay.classList.add('d-none'); }, 2000);
    }

    function addRecent(iconCls, text) {
        var item = document.createElement('div');
        item.className = 'recent-item';

        var icon = document.createElement('i');
        icon.className = 'bi ' + iconCls + ' ri-icon';
        item.appendChild(icon);

        var name = document.createElement('span');
        name.className = 'ri-name';
        name.textContent = text;
        item.appendChild(name);

        var time = document.createElement('span');
        time.className = 'ri-time';
        var d = new Date();
        time.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
        item.appendChild(time);

        recentListEl.insertBefore(item, recentListEl.firstChild);
        while (recentListEl.children.length > 10) {
            recentListEl.removeChild(recentListEl.lastChild);
        }
    }
})();
</script>
</body>
</html>
