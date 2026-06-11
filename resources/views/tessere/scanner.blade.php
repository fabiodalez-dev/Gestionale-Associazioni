<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Scanner Tessera</title>
    <meta name="theme-color" content="#FF7B11">
    <link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        :root { --brand: #FF7B11; }
        body { background: #f5f5f5; min-height: 100vh; min-height: 100dvh; }
        .scanner-header { background: var(--brand); color: #fff; padding: 12px 16px; position: sticky; top: 0; z-index: 100; }
        .scanner-header h1 { font-size: 1.1rem; margin: 0; }
        #qr-reader { width: 100%; max-width: 400px; margin: 0 auto; border-radius: 12px; overflow: hidden; }
        #qr-reader video { border-radius: 12px; }

        .result-card {
            max-width: 400px;
            margin: 1rem auto;
            border-radius: 12px;
            overflow: hidden;
            animation: slideUp .3s ease;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .result-valid { border-left: 4px solid #198754; }
        .result-expired { border-left: 4px solid #dc3545; }
        .result-notfound { border-left: 4px solid #ffc107; }
    </style>
</head>
<body>
    <div class="scanner-header">
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-qr-code-scan me-2"></i>Scanner Tessera</h1>
            <a href="{{ route('tessere.verifica') }}" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i>Indietro
            </a>
        </div>
    </div>

    <div class="container py-3">
        <!-- QR Scanner -->
        <div id="qr-reader" class="mb-3"></div>

        <!-- Input manuale -->
        <form id="manualForm" class="d-flex gap-2 mb-3" style="max-width:400px;margin:0 auto">
            <input type="text" id="manualInput" class="form-control" placeholder="UUID tessera..." style="min-height:44px;font-size:16px">
            <button type="submit" class="btn" style="background:var(--brand);color:#fff;white-space:nowrap;min-height:44px">
                <i class="bi bi-search"></i> Verifica
            </button>
        </form>

        <!-- Area risultato -->
        <div id="result-area"></div>
    </div>

<script src="{{ asset('assets/vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
<script>
(function() {
    'use strict';

    // Base dell'endpoint JSON di verifica: VERIFY_URL_BASE + '/' + uuid
    var VERIFY_URL_BASE = @json(url('/api/verifica-tessera'));
    var scanner = null;
    var scanCooldown = false;
    var resultArea = document.getElementById('result-area');

    /** Crea un'icona Bootstrap in modo sicuro */
    function makeIcon(iconClass, extraClass) {
        var i = document.createElement('i');
        i.className = 'bi ' + iconClass + (extraClass ? ' ' + extraClass : '');
        return i;
    }

    // Avvio scanner
    function startScanner() {
        if (scanner) return;
        scanner = new Html5Qrcode('qr-reader');
        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            onQrScanned,
            function() {}
        ).catch(function() {
            var el = document.getElementById('qr-reader');
            el.textContent = '';
            var warn = document.createElement('div');
            warn.className = 'alert alert-warning m-3';
            warn.appendChild(makeIcon('bi-camera-video-off', 'me-1'));
            warn.appendChild(document.createTextNode('Fotocamera non disponibile. Usa l\'inserimento manuale.'));
            el.appendChild(warn);
        });
    }

    function onQrScanned(text) {
        if (scanCooldown) return;
        scanCooldown = true;
        setTimeout(function() { scanCooldown = false; }, 3000);

        // Estrae l'UUID dall'URL (path /verifica-tessera/{uuid} o query ?t=) o usa il testo grezzo
        var uuid = '';
        try {
            var url = new URL(text);
            uuid = url.searchParams.get('t') || '';
            if (!uuid) {
                var m = url.pathname.match(/verifica-tessera\/([a-f0-9-]{36})/i);
                if (m) uuid = m[1];
            }
        } catch (e) {
            uuid = text;
        }

        if (uuid) verifyTessera(uuid);
    }

    // Input manuale
    document.getElementById('manualForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var val = document.getElementById('manualInput').value.trim();
        if (val) verifyTessera(val);
    });

    // Verifica tramite endpoint JSON
    function verifyTessera(uuid) {
        resultArea.textContent = '';
        var loading = document.createElement('div');
        loading.className = 'text-center py-3';
        var spinner = document.createElement('div');
        spinner.className = 'spinner-border';
        spinner.style.color = 'var(--brand)';
        spinner.setAttribute('role', 'status');
        loading.appendChild(spinner);
        resultArea.appendChild(loading);

        fetch(VERIFY_URL_BASE + '/' + encodeURIComponent(uuid), { headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.json(); })
            .then(function(data) { showResult(data); })
            .catch(function() {
                resultArea.textContent = '';
                var err = document.createElement('div');
                err.className = 'alert alert-danger';
                err.textContent = 'Errore di connessione. Riprova.';
                resultArea.appendChild(err);
            });
    }

    function showResult(data) {
        resultArea.textContent = '';

        var card = document.createElement('div');
        card.className = 'card result-card';

        if (data.stato === 'valida') {
            card.classList.add('result-valid');
            var body = document.createElement('div');
            body.className = 'card-body';

            var header = document.createElement('div');
            header.className = 'text-center mb-3';
            var icon = makeIcon('bi-check-circle-fill', 'text-success');
            icon.style.fontSize = '3rem';
            header.appendChild(icon);

            var title = document.createElement('h5');
            title.className = 'text-success text-center';
            title.textContent = 'Tessera Valida';

            body.appendChild(header);
            body.appendChild(title);
            body.appendChild(document.createElement('hr'));
            body.appendChild(buildDetails(data.tessera));
            card.appendChild(body);

        } else if (data.stato === 'scaduta') {
            card.classList.add('result-expired');
            var body2 = document.createElement('div');
            body2.className = 'card-body';

            var header2 = document.createElement('div');
            header2.className = 'text-center mb-3';
            var icon2 = makeIcon('bi-x-circle-fill', 'text-danger');
            icon2.style.fontSize = '3rem';
            header2.appendChild(icon2);

            var title2 = document.createElement('h5');
            title2.className = 'text-danger text-center';
            title2.textContent = 'Tessera Scaduta / Non Attiva';

            body2.appendChild(header2);
            body2.appendChild(title2);
            body2.appendChild(document.createElement('hr'));
            body2.appendChild(buildDetails(data.tessera));
            card.appendChild(body2);

        } else {
            card.classList.add('result-notfound');
            var body3 = document.createElement('div');
            body3.className = 'card-body text-center';

            var header3 = document.createElement('div');
            header3.className = 'mb-3';
            var icon3 = makeIcon('bi-exclamation-triangle', 'text-warning');
            icon3.style.fontSize = '3rem';
            header3.appendChild(icon3);

            var title3 = document.createElement('h5');
            title3.textContent = 'Tessera Non Trovata';

            var msg = document.createElement('p');
            msg.className = 'text-muted';
            msg.textContent = 'Il codice scansionato non corrisponde a nessuna tessera registrata.';

            body3.appendChild(header3);
            body3.appendChild(title3);
            body3.appendChild(msg);
            card.appendChild(body3);
        }

        resultArea.appendChild(card);

        // Bottone "Scansiona altra tessera"
        var btnWrap = document.createElement('div');
        btnWrap.className = 'text-center mt-3';
        var btn = document.createElement('button');
        btn.className = 'btn btn-outline-secondary';
        btn.style.minHeight = '44px';
        btn.appendChild(makeIcon('bi-arrow-repeat', 'me-1'));
        btn.appendChild(document.createTextNode('Scansiona altra tessera'));
        btn.addEventListener('click', function() {
            resultArea.textContent = '';
            document.getElementById('manualInput').value = '';
        });
        btnWrap.appendChild(btn);
        resultArea.appendChild(btnWrap);
    }

    function buildDetails(t) {
        if (!t) return document.createDocumentFragment();
        var dl = document.createElement('div');

        var fields = [
            ['Associazione', t.associazione_nome],
            ['Socio', (t.socio_nome || '') + ' ' + (t.socio_cognome || '')],
            ['N. Tessera', t.numero_tessera],
            ['Anno', t.anno_validita],
            ['Scadenza', formatDate(t.data_scadenza)]
        ];

        if (t.stato_tessera) {
            fields.push(['Stato', t.stato_tessera]);
        }

        fields.forEach(function(f) {
            var row = document.createElement('p');
            row.className = 'mb-1';
            var label = document.createElement('strong');
            label.textContent = f[0] + ': ';
            row.appendChild(label);
            row.appendChild(document.createTextNode(f[1] || '-'));
            dl.appendChild(row);
        });

        return dl;
    }

    function formatDate(d) {
        if (!d) return '-';
        var parts = d.split('-');
        if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0];
        return d;
    }

    // Avvio automatico
    startScanner();

})();
</script>
</body>
</html>
