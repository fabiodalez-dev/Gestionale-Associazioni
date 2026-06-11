// Installer: test connessione database via AJAX + toggle campi SMTP.
// File esterno (no inline) per compatibilità con la Content-Security-Policy.
(function () {
    'use strict';

    // --- Step database: pulsante "Testa connessione" ---
    var btn = document.getElementById('testConnessione');
    if (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('form');
            var esito = document.getElementById('testEsito');
            var token = document.querySelector('meta[name="csrf-token"]').content;

            btn.disabled = true;
            esito.className = 'alert alert-info mt-3 mb-0';
            esito.textContent = 'Connessione in corso…';

            fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                body: new FormData(form),
            })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
                .then(function (res) {
                    btn.disabled = false;
                    if (res.status === 422) {
                        esito.className = 'alert alert-warning mt-3 mb-0';
                        var errs = res.data.errors ? Object.values(res.data.errors).flat().join(' ') : 'Dati non validi.';
                        esito.textContent = errs;
                        return;
                    }
                    if (res.data.ok) {
                        esito.className = 'alert alert-success mt-3 mb-0';
                        esito.textContent = res.data.tables > 0
                            ? 'Connessione riuscita. Attenzione: il database contiene già ' + res.data.tables + ' tabelle.'
                            : 'Connessione riuscita. Il database è vuoto e pronto.';
                    } else {
                        esito.className = 'alert alert-danger mt-3 mb-0';
                        esito.textContent = 'Connessione fallita: ' + (res.data.error || 'errore sconosciuto');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    esito.className = 'alert alert-danger mt-3 mb-0';
                    esito.textContent = 'Errore di rete durante il test.';
                });
        });
    }

    // --- Step email: mostra i campi SMTP solo se driver = smtp ---
    var driver = document.getElementById('driver');
    var smtpFields = document.getElementById('smtpFields');
    if (driver && smtpFields) {
        driver.addEventListener('change', function () {
            smtpFields.classList.toggle('d-none', driver.value !== 'smtp');
        });
    }
})();
