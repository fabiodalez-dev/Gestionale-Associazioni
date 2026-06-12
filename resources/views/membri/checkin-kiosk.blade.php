{{-- Check-in kiosk fullscreen per evento (f.21 — piano "Gestione 2").
     Layout dedicato senza sidebar, pensato per un totem all'ingresso:
     scanner html5-qrcode continuo, esito grande verde/rosso con
     auto-reset 3s, contatore presenze live (polling). --}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Kiosk Check-in — {{ $evento->titolo }}</title>
    <meta name="theme-color" content="#FF7B11">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        :root { --brand: #FF7B11; }
        html, body { height: 100%; }
        body {
            background: #111;
            color: #fff;
            min-height: 100vh; min-height: 100dvh;
            display: flex; flex-direction: column;
            overflow: hidden;
        }
        .kiosk-header {
            background: var(--brand); color: #fff;
            padding: 14px 22px;
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
        }
        .kiosk-header .titolo { font-size: 1.3rem; font-weight: 700; margin: 0; }
        .kiosk-header .sotto { font-size: .9rem; opacity: .9; }
        .kiosk-counter { text-align: right; line-height: 1.1; }
        .kiosk-counter .num { font-size: 2.4rem; font-weight: 800; }
        .kiosk-counter .lbl { font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; opacity: .9; }

        .kiosk-main {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 18px; padding: 18px;
        }
        #qr-reader { width: 100%; max-width: 480px; border-radius: 16px; overflow: hidden; background: #000; }
        #qr-reader video { border-radius: 16px; }
        .kiosk-hint { color: #aaa; font-size: 1.05rem; text-align: center; }

        .kiosk-footer { padding: 10px 22px; display: flex; justify-content: space-between; align-items: center; }
        .kiosk-footer a, .kiosk-footer button { color: #888; font-size: .85rem; text-decoration: none; background: none; border: 0; }

        /* Esito fullscreen verde/rosso con auto-reset */
        .kiosk-result {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; padding: 32px;
            animation: kioskFade .15s ease;
        }
        .kiosk-result .icon { font-size: 7rem; }
        .kiosk-result .name { font-size: 2.6rem; font-weight: 800; margin-top: 10px; }
        .kiosk-result .msg { font-size: 1.4rem; margin-top: 8px; opacity: .95; }
        .kiosk-result.ok { background: #198754; color: #fff; }
        .kiosk-result.dup { background: #ffc107; color: #222; }
        .kiosk-result.err { background: #dc3545; color: #fff; }
        @keyframes kioskFade { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>

<header class="kiosk-header">
    <div>
        <h1 class="titolo"><i class="bi bi-qr-code-scan me-2"></i>{{ $evento->titolo }}</h1>
        <div class="sotto">
            {{ $evento->data_evento?->format('d/m/Y H:i') }}
            @if ($evento->luogo) · {{ $evento->luogo }} @endif
        </div>
    </div>
    <div class="kiosk-counter">
        <div class="num" id="kioskCounter">{{ $presenze }}</div>
        <div class="lbl">presenze</div>
    </div>
</header>

<main class="kiosk-main">
    <div id="qr-reader"></div>
    <div class="kiosk-hint">
        <i class="bi bi-camera me-1"></i>Inquadra il QR della tessera o del biglietto:
        il check-in è automatico.
    </div>
</main>

<footer class="kiosk-footer">
    <a href="{{ route('eventi.index') }}"><i class="bi bi-box-arrow-left me-1"></i>Esci dal kiosk</a>
    <button type="button" id="btnFullscreen"><i class="bi bi-arrows-fullscreen me-1"></i>Schermo intero</button>
</footer>

<div id="kioskResult" class="kiosk-result d-none" role="alert" aria-live="assertive"></div>

<script src="{{ asset('assets/vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
<script>
(function () {
    'use strict';

    var EVENTO_ID = @json($evento->id);
    var CHECKIN_URL = @json(route('tessere.checkin.store'));
    var BIGLIETTO_CHECKIN_URL = @json(route('biglietti.checkin'));
    var CONTATORE_URL = @json(route('checkin-kiosk.contatore', $evento));
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var RESET_MS = 3000;

    var counterEl = document.getElementById('kioskCounter');
    var resultEl = document.getElementById('kioskResult');
    var scanCooldown = false;
    var resetTimer = null;

    // ---- Scanner continuo ----
    var scanner = new Html5Qrcode('qr-reader');
    scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 280, height: 280 } },
        onQrScanned,
        function () {}
    ).catch(function () {
        var el = document.getElementById('qr-reader');
        el.textContent = '';
        var warn = document.createElement('div');
        warn.className = 'alert alert-warning m-3';
        warn.textContent = 'Fotocamera non disponibile su questo dispositivo.';
        el.appendChild(warn);
    });

    function onQrScanned(text) {
        if (scanCooldown) return;
        scanCooldown = true;

        // Auto-riconoscimento payload (stessa logica della pagina check-in):
        // URL verifica-biglietto/{64 hex} → biglietto; URL verifica tessera →
        // uuid tessera; altrimenti token/codice PRN/numero tessera grezzi.
        var raw = '';
        try {
            var url = new URL(text);
            var mb = url.pathname.match(/verifica-biglietto\/([a-f0-9]{64})/i);
            if (mb) { doCheckinBiglietto({ qr_token: mb[1].toLowerCase() }); return; }
            raw = url.searchParams.get('t') || '';
            if (!raw) {
                var m = url.pathname.match(/verifica-tessera\/([a-f0-9-]{36})/i);
                if (m) raw = m[1];
            }
        } catch (e) {
            raw = text;
        }

        if (!raw) { scanCooldown = false; return; }

        if (/^[a-f0-9]{64}$/i.test(raw)) {
            doCheckinBiglietto({ qr_token: raw.toLowerCase() });
        } else if (/^PRN-/i.test(raw)) {
            doCheckinBiglietto({ codice: raw.toUpperCase() });
        } else if (/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(raw)) {
            doCheckin({ tessera_id: raw });
        } else {
            doCheckin({ numero_tessera: raw });
        }
    }

    // ---- Check-in tessera socio ----
    function doCheckin(identifier) {
        post(CHECKIN_URL, identifier, function (data) {
            if (data.success && !data.already_checked_in) {
                bumpCounter();
                showResult('ok', 'bi-check-circle-fill', nomeSocio(data), 'Benvenuto! Check-in registrato.');
            } else if (data.success && data.already_checked_in) {
                showResult('dup', 'bi-exclamation-circle-fill', nomeSocio(data), 'Già registrato per questo evento.');
            } else {
                showResult('err', 'bi-x-circle-fill', 'Non valido', errore(data));
            }
        });
    }

    // ---- Check-in biglietto (prenotazione frontend pubblico) ----
    function doCheckinBiglietto(identifier) {
        post(BIGLIETTO_CHECKIN_URL, identifier, function (data) {
            var b = data.biglietto || null;
            var nome = b ? ((b.account_nome || '') + ' ' + (b.account_cognome || '')).trim() : '';
            var posti = b && b.posti ? (b.posti === 1 ? '1 posto' : b.posti + ' posti') : '';

            if (data.success && !data.already_checked_in) {
                bumpCounter(b && b.posti ? b.posti : 1);
                showResult('ok', 'bi-ticket-perforated-fill', nome || 'Biglietto valido', (posti ? posti + ' · ' : '') + 'Check-in registrato.');
            } else if (data.success && data.already_checked_in) {
                showResult('dup', 'bi-exclamation-circle-fill', nome || 'Biglietto', 'Biglietto già utilizzato.');
            } else {
                showResult('err', 'bi-x-circle-fill', nome || 'Non valido', errore(data));
            }
        });
    }

    function post(url, identifier, onData) {
        var payload = Object.assign({ evento_id: EVENTO_ID }, identifier);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF
            },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(onData)
            .catch(function () {
                showResult('err', 'bi-wifi-off', 'Errore di rete', 'Verifica la connessione e riprova.');
            });
    }

    function nomeSocio(data) {
        return data.socio ? (data.socio.nome + ' ' + data.socio.cognome) : '';
    }

    function errore(data) {
        return data.error || (data.errors ? Object.values(data.errors)[0][0] : 'Errore durante il check-in.');
    }

    // ---- Esito fullscreen verde/rosso con auto-reset 3s ----
    function showResult(tipo, iconCls, nameText, msgText) {
        clearTimeout(resetTimer);
        resultEl.textContent = '';
        resultEl.className = 'kiosk-result ' + tipo;

        var icon = document.createElement('i');
        icon.className = 'bi ' + iconCls + ' icon';
        resultEl.appendChild(icon);

        var name = document.createElement('div');
        name.className = 'name';
        name.textContent = nameText || '';
        resultEl.appendChild(name);

        var msg = document.createElement('div');
        msg.className = 'msg';
        msg.textContent = msgText || '';
        resultEl.appendChild(msg);

        resetTimer = setTimeout(function () {
            resultEl.classList.add('d-none');
            scanCooldown = false;
        }, RESET_MS);
    }

    // ---- Contatore presenze live ----
    function bumpCounter(delta) {
        counterEl.textContent = String(parseInt(counterEl.textContent, 10) + (delta || 1));
    }

    function pollCounter() {
        fetch(CONTATORE_URL, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (typeof data.presenze === 'number') {
                    counterEl.textContent = String(data.presenze);
                }
            })
            .catch(function () {});
    }
    setInterval(pollCounter, 10000);

    // ---- Schermo intero (totem) ----
    document.getElementById('btnFullscreen').addEventListener('click', function () {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(function () {});
        } else {
            document.exitFullscreen().catch(function () {});
        }
    });
})();
</script>
</body>
</html>
