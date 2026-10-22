{{-- Pagina mobile standalone di check-in evento: nessuna sessione, parla solo con l'API REST v1 (Bearer API key). --}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Check-in Evento</title>
    <meta name="theme-color" content="#0D9488">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        :root { --brand: #0D9488; }
        body { background: #f5f5f5; min-height: 100vh; min-height: 100dvh; }
        .checkin-header { background: var(--brand); color: #fff; padding: 12px 16px; position: sticky; top: 0; z-index: 100; }
        .checkin-header h1 { font-size: 1.1rem; margin: 0; }
        .counter { font-size: 1.3rem; font-weight: 700; }
        .counter .num { font-size: 2rem; }

        /* Setup screen */
        .setup-card { max-width: 440px; margin: 60px auto; }

        /* Event selection */
        .event-card { cursor: pointer; transition: transform .15s, box-shadow .15s; }
        .event-card:hover, .event-card:focus { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .event-card.today { border-left: 4px solid var(--brand); }

        /* Scanner area */
        #qr-reader { width: 100%; max-width: 400px; margin: 0 auto; border-radius: 12px; overflow: hidden; }
        #qr-reader video { border-radius: 12px; }

        /* Result overlay */
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

        /* Recent list */
        .recent-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eee; }
        .recent-item .ri-icon { font-size: 1.2rem; }
        .recent-item .ri-name { flex: 1; font-weight: 500; }
        .recent-item .ri-time { color: #888; font-size: .85rem; }
    </style>
</head>
<body>

<!-- ============ SCHERMATA: Setup (API Key) ============ -->
<div id="screen-setup">
    <div class="setup-card card shadow-sm">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-qr-code-scan" style="font-size:3rem;color:var(--brand)"></i>
                <h2 class="mt-2">Check-in Evento</h2>
                <p class="text-muted">Inserisci la chiave API per iniziare</p>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="apiKeyInput">Chiave API</label>
                <input type="password" id="apiKeyInput" class="form-control form-control-lg" placeholder="sk_...">
            </div>
            <div id="setup-error" class="alert alert-danger d-none"></div>
            <button id="btnSetup" class="btn btn-lg w-100" style="background:var(--brand);color:#fff">
                <i class="bi bi-arrow-right-circle me-1"></i> Connetti
            </button>
        </div>
    </div>
</div>

<!-- ============ SCHERMATA: Selezione evento ============ -->
<div id="screen-events" class="d-none">
    <div class="checkin-header d-flex justify-content-between align-items-center">
        <h1><i class="bi bi-calendar-event me-2"></i>Seleziona Evento</h1>
        <button class="btn btn-sm btn-outline-light" id="btnLogout" title="Esci"><i class="bi bi-box-arrow-left"></i></button>
    </div>
    <div class="container py-3">
        <div id="events-list" class="row g-3"></div>
        <div id="events-empty" class="text-center py-5 d-none">
            <i class="bi bi-calendar-x" style="font-size:3rem;color:#ccc"></i>
            <p class="text-muted mt-2">Nessun evento trovato.</p>
        </div>
    </div>
</div>

<!-- ============ SCHERMATA: Scanner check-in ============ -->
<div id="screen-checkin" class="d-none">
    <div class="checkin-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 id="checkin-event-name">Evento</h1>
            </div>
            <button class="btn btn-sm btn-outline-light" id="btnChangeEvent"><i class="bi bi-arrow-left me-1"></i>Cambia</button>
        </div>
        <div class="counter mt-1">
            <i class="bi bi-people-fill me-1"></i>
            <span class="num" id="checkin-counter">0</span> ingressi registrati
        </div>
    </div>

    <div class="container py-3">
        <!-- Scanner QR -->
        <div id="qr-reader" class="mb-3"></div>

        <!-- Inserimento manuale -->
        <form id="manualForm" class="d-flex gap-2 mb-4" style="max-width:400px;margin:0 auto">
            <input type="text" id="manualInput" class="form-control" placeholder="Numero tessera...">
            <button type="submit" class="btn" style="background:var(--brand);color:#fff;white-space:nowrap"><i class="bi bi-check-lg"></i> OK</button>
        </form>

        <!-- Ultimi check-in -->
        <div style="max-width:400px;margin:0 auto">
            <h6 class="text-muted"><i class="bi bi-clock-history me-1"></i>Ultimi check-in</h6>
            <div id="recent-list"></div>
        </div>
    </div>
</div>

<!-- Overlay esito (mostrato dinamicamente) -->
<div id="result-overlay" class="result-overlay d-none" role="alert"></div>

{{-- html5-qrcode servito come asset locale (CSP irrigidita: nessun CDN esterno) --}}
<script src="{{ asset('assets/vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
<script>
(function() {
    'use strict';

    var API_BASE = @json($apiBaseUrl);

    // Stato
    var apiKey = localStorage.getItem('checkin_api_key') || '';
    var currentEventId = null;
    var currentEventName = '';
    var checkinCount = 0;
    var recentItems = [];
    var scanner = null;
    var scanCooldown = false;

    // Riferimenti DOM
    var screenSetup = document.getElementById('screen-setup');
    var screenEvents = document.getElementById('screen-events');
    var screenCheckin = document.getElementById('screen-checkin');
    var apiKeyInput = document.getElementById('apiKeyInput');
    var setupError = document.getElementById('setup-error');
    var eventsList = document.getElementById('events-list');
    var eventsEmpty = document.getElementById('events-empty');
    var checkinEventName = document.getElementById('checkin-event-name');
    var checkinCounter = document.getElementById('checkin-counter');
    var manualInput = document.getElementById('manualInput');
    var recentListEl = document.getElementById('recent-list');
    var overlay = document.getElementById('result-overlay');

    // ---- Helper ----
    function apiFetch(path, options) {
        options = options || {};
        options.headers = Object.assign({
            'Authorization': 'Bearer ' + apiKey,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }, options.headers || {});
        return fetch(API_BASE + path, options);
    }

    function showScreen(name) {
        screenSetup.classList.toggle('d-none', name !== 'setup');
        screenEvents.classList.toggle('d-none', name !== 'events');
        screenCheckin.classList.toggle('d-none', name !== 'checkin');
        if (name !== 'checkin' && scanner) {
            scanner.stop().catch(function() {});
            scanner = null;
        }
    }

    // ---- Setup ----
    document.getElementById('btnSetup').addEventListener('click', doSetup);
    apiKeyInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') doSetup(); });

    function doSetup() {
        var key = apiKeyInput.value.trim();
        if (!key) return;
        setupError.classList.add('d-none');

        apiFetchWithKey(key, '/')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    apiKey = key;
                    localStorage.setItem('checkin_api_key', key);
                    loadEvents();
                } else {
                    showSetupError(data.error || 'Chiave API non valida.');
                }
            })
            .catch(function() {
                showSetupError('Errore di connessione. Verifica la chiave API.');
            });
    }

    function apiFetchWithKey(key, path) {
        return fetch(API_BASE + path, {
            headers: { 'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json', 'Accept': 'application/json' }
        });
    }

    function showSetupError(msg) {
        setupError.textContent = msg;
        setupError.classList.remove('d-none');
    }

    // Auto-connessione se la chiave è salvata
    if (apiKey) {
        apiFetchWithKey(apiKey, '/').then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) { loadEvents(); }
            else { showScreen('setup'); }
        }).catch(function() { showScreen('setup'); });
    } else {
        showScreen('setup');
    }

    // ---- Logout ----
    document.getElementById('btnLogout').addEventListener('click', function() {
        localStorage.removeItem('checkin_api_key');
        apiKey = '';
        apiKeyInput.value = '';
        showScreen('setup');
    });

    // ---- Eventi ----
    function loadEvents() {
        showScreen('events');
        apiFetch('/eventi?limit=100')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.eventi || data.eventi.length === 0) {
                    eventsList.textContent = '';
                    eventsEmpty.classList.remove('d-none');
                    return;
                }
                eventsEmpty.classList.add('d-none');
                var today = new Date().toISOString().slice(0, 10);

                // Card costruite con metodi DOM sicuri (anti-XSS: mai innerHTML con dati API)
                eventsList.textContent = '';
                data.eventi.forEach(function(ev) {
                    var evDate = (ev.data_evento || '').slice(0, 10);
                    var isToday = evDate === today;

                    var col = document.createElement('div');
                    col.className = 'col-sm-6 col-lg-4';

                    var card = document.createElement('div');
                    card.className = 'card event-card' + (isToday ? ' today' : '');
                    card.tabIndex = 0;
                    card.dataset.id = ev.id;
                    card.dataset.name = ev.titolo;

                    var body = document.createElement('div');
                    body.className = 'card-body';

                    var title = document.createElement('h5');
                    title.className = 'card-title';
                    title.textContent = ev.titolo;
                    body.appendChild(title);

                    var dateLine = document.createElement('p');
                    dateLine.className = 'card-text text-muted mb-1';
                    dateLine.innerHTML = '<i class="bi bi-calendar me-1"></i>';
                    dateLine.appendChild(document.createTextNode(formatDateTime(ev.data_evento)));
                    body.appendChild(dateLine);

                    if (ev.luogo) {
                        var locLine = document.createElement('p');
                        locLine.className = 'card-text text-muted mb-0';
                        locLine.innerHTML = '<i class="bi bi-geo-alt me-1"></i>';
                        locLine.appendChild(document.createTextNode(ev.luogo));
                        body.appendChild(locLine);
                    }

                    if (isToday) {
                        var badge = document.createElement('span');
                        badge.className = 'badge bg-warning text-dark mt-2';
                        badge.textContent = 'Oggi';
                        body.appendChild(badge);
                    }

                    card.appendChild(body);
                    col.appendChild(card);
                    eventsList.appendChild(col);

                    card.addEventListener('click', function() {
                        selectEvent(ev.id, ev.titolo);
                    });
                });
            })
            .catch(function() {
                eventsList.textContent = '';
                var alert = document.createElement('div');
                alert.className = 'col-12';
                var inner = document.createElement('div');
                inner.className = 'alert alert-danger';
                inner.textContent = 'Errore nel caricamento eventi.';
                alert.appendChild(inner);
                eventsList.appendChild(alert);
            });
    }

    function formatDateTime(dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var dd = String(d.getDate()).padStart(2, '0');
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var hh = String(d.getHours()).padStart(2, '0');
        var mi = String(d.getMinutes()).padStart(2, '0');
        return dd + '/' + mm + '/' + d.getFullYear() + ' ' + hh + ':' + mi;
    }

    // ---- Selezione evento ----
    function selectEvent(id, name) {
        currentEventId = id;
        currentEventName = name;
        checkinCount = parseInt(sessionStorage.getItem('checkin_count_' + id) || '0', 10);
        recentItems = JSON.parse(sessionStorage.getItem('checkin_recent_' + id) || '[]');
        checkinEventName.textContent = name;
        checkinCounter.textContent = checkinCount;
        renderRecent();
        showScreen('checkin');
        startScanner();
    }

    document.getElementById('btnChangeEvent').addEventListener('click', function() {
        currentEventId = null;
        loadEvents();
    });

    // ---- Scanner QR ----
    function startScanner() {
        if (scanner) return;
        scanner = new Html5Qrcode('qr-reader');
        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            onQrScanned,
            function() {} // ignora gli errori di scansione
        ).catch(function() {
            var el = document.getElementById('qr-reader');
            el.textContent = '';
            var warn = document.createElement('div');
            warn.className = 'alert alert-warning m-3';
            warn.innerHTML = '<i class="bi bi-camera-video-off me-1"></i>';
            warn.appendChild(document.createTextNode('Fotocamera non disponibile. Usa l\'inserimento manuale.'));
            el.appendChild(warn);
        });
    }

    function onQrScanned(text) {
        if (scanCooldown) return;
        scanCooldown = true;
        setTimeout(function() { scanCooldown = false; }, 2000);

        // Parsing QR: URL con ?t=UUID (tessera) oppure numero_tessera grezzo
        var payload = {};
        try {
            var url = new URL(text);
            var tParam = url.searchParams.get('t');
            if (tParam) {
                payload.tessera_id = tParam;
            } else {
                payload.numero_tessera = text;
            }
        } catch (e) {
            payload.numero_tessera = text;
        }

        doCheckin(payload);
    }

    // ---- Inserimento manuale ----
    document.getElementById('manualForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var val = manualInput.value.trim();
        if (!val) return;
        manualInput.value = '';
        doCheckin({ numero_tessera: val });
    });

    // ---- Chiamata API check-in ----
    function doCheckin(payload) {
        apiFetch('/eventi/' + encodeURIComponent(currentEventId) + '/checkin', {
            method: 'POST',
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var nome = (data.socio ? data.socio.cognome + ' ' + data.socio.nome : '');
                if (data.already_checked_in) {
                    showResult('dup', nome, 'Già registrato');
                    addRecent(nome, 'dup');
                } else {
                    checkinCount++;
                    checkinCounter.textContent = checkinCount;
                    sessionStorage.setItem('checkin_count_' + currentEventId, String(checkinCount));
                    showResult('ok', nome, 'Check-in OK');
                    addRecent(nome, 'ok');
                }
            } else {
                showResult('err', '', data.error || 'Errore sconosciuto');
            }
        })
        .catch(function() {
            showResult('err', '', 'Errore di connessione');
        });
    }

    // ---- Overlay esito (costruito via DOM per sicurezza XSS) ----
    function showResult(type, name, msg) {
        var cls = type === 'ok' ? 'result-ok' : (type === 'dup' ? 'result-dup' : 'result-err');
        var icon = type === 'ok' ? 'bi-check-circle-fill' : (type === 'dup' ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill');

        overlay.textContent = '';
        var card = document.createElement('div');
        card.className = 'result-card ' + cls;

        var iconDiv = document.createElement('div');
        iconDiv.className = 'icon';
        var iconEl = document.createElement('i');
        iconEl.className = 'bi ' + icon;
        iconDiv.appendChild(iconEl);
        card.appendChild(iconDiv);

        if (name) {
            var nameDiv = document.createElement('div');
            nameDiv.className = 'name';
            nameDiv.textContent = name;
            card.appendChild(nameDiv);
        }

        var msgDiv = document.createElement('div');
        msgDiv.className = 'msg';
        msgDiv.textContent = msg;
        card.appendChild(msgDiv);

        overlay.appendChild(card);
        overlay.classList.remove('d-none');

        var timer = setTimeout(dismissOverlay, 3000);
        overlay.addEventListener('click', function handler() {
            clearTimeout(timer);
            overlay.removeEventListener('click', handler);
            dismissOverlay();
        });
    }

    function dismissOverlay() {
        overlay.classList.add('d-none');
    }

    // ---- Lista recenti (costruita via DOM per sicurezza XSS) ----
    function addRecent(name, type) {
        var now = new Date();
        var time = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        recentItems.unshift({ name: name, type: type, time: time });
        if (recentItems.length > 10) recentItems.length = 10;
        sessionStorage.setItem('checkin_recent_' + currentEventId, JSON.stringify(recentItems));
        renderRecent();
    }

    function renderRecent() {
        recentListEl.textContent = '';
        if (!recentItems.length) {
            var p = document.createElement('p');
            p.className = 'text-muted';
            p.textContent = 'Nessun check-in ancora.';
            recentListEl.appendChild(p);
            return;
        }
        recentItems.forEach(function(item) {
            var row = document.createElement('div');
            row.className = 'recent-item';

            var iconSpan = document.createElement('span');
            iconSpan.className = 'ri-icon';
            var iconI = document.createElement('i');
            iconI.className = 'bi ' + (item.type === 'ok' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-warning');
            iconSpan.appendChild(iconI);

            var nameSpan = document.createElement('span');
            nameSpan.className = 'ri-name';
            nameSpan.textContent = item.name;

            var timeSpan = document.createElement('span');
            timeSpan.className = 'ri-time';
            timeSpan.textContent = item.time;

            row.appendChild(iconSpan);
            row.appendChild(nameSpan);
            row.appendChild(timeSpan);
            recentListEl.appendChild(row);
        });
    }

})();
</script>
</body>
</html>
