// Pagina Aggiornamenti: avvio dell'update via AJAX con polling dello stato
// (l'aggiornamento gira nella richiesta POST; il polling serve se un proxy
// chiude la connessione prima della fine), controllo release e upload del
// pacchetto. File esterno per la Content-Security-Policy.
(function () {
    'use strict';

    var card = document.getElementById('cardVersione');
    if (!card) { return; }

    var token = document.querySelector('meta[name="csrf-token"]');
    var csrf = token ? token.content : '';
    var avanzamento = document.getElementById('avanzamento');
    var avanzamentoTesto = document.getElementById('avanzamentoTesto');
    var esito = document.getElementById('esitoUpdate');
    var pollTimer = null;
    var versioneIniziale = null;

    function mostraEsito(ok, testo) {
        esito.className = 'alert mt-3 mb-0 ' + (ok ? 'alert-success' : 'alert-danger');
        esito.textContent = testo;
        esito.classList.remove('d-none');
    }

    function avvia(testo) {
        avanzamento.classList.remove('d-none');
        if (testo) { avanzamentoTesto.textContent = testo; }
        esito.classList.add('d-none');
        document.querySelectorAll('#btnAggiorna, #btnCarica').forEach(function (b) { b.disabled = true; });
    }

    function ferma() {
        avanzamento.classList.add('d-none');
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // Polling: quando il lock si libera legge l'ultimo log e ricarica
    function avviaPolling() {
        if (pollTimer) { return; }
        pollTimer = setInterval(function () {
            fetch(card.dataset.urlStato, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (s) {
                    if (s.in_corso) {
                        avanzamentoTesto.textContent = 'Aggiornamento in corso… (versione installata: v' + s.versione + ')';
                        return;
                    }
                    if (versioneIniziale !== null && s.versione !== versioneIniziale) {
                        ferma();
                        mostraEsito(true, 'Aggiornamento completato: ora sei alla v' + s.versione + '. Ricarico la pagina…');
                        setTimeout(function () { window.location.reload(); }, 1500);
                        return;
                    }
                    if (s.ultimo && s.ultimo.stato !== 'avviato') {
                        ferma();
                        var ok = s.ultimo.stato === 'completato';
                        mostraEsito(ok, ok
                            ? 'Aggiornamento completato. Ricarico la pagina…'
                            : 'Aggiornamento ' + s.ultimo.stato + ': ' + (s.ultimo.messaggio_errore || 'vedi lo storico.'));
                        if (ok) { setTimeout(function () { window.location.reload(); }, 1500); }
                    }
                })
                .catch(function () { /* il server è in manutenzione o riavvio: si riprova */ });
        }, 3000);
    }

    function invia(form, testo) {
        avvia(testo);
        var statoIniziale = fetch(card.dataset.urlStato, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (s) { versioneIniziale = s.versione; })
            .catch(function () {});

        statoIniziale.then(function () {
            avviaPolling();
            fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: new FormData(form),
            })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
                .then(function (res) {
                    ferma();
                    var ok = res.status === 200 && res.data.success;
                    var msg = res.data.message || (res.data.errors ? Object.values(res.data.errors).flat().join(' ') : 'Risposta inattesa dal server.');
                    mostraEsito(ok, msg);
                    if (ok) { setTimeout(function () { window.location.reload(); }, 1500); }
                    else { document.querySelectorAll('#btnAggiorna, #btnCarica').forEach(function (b) { b.disabled = false; }); }
                })
                .catch(function () {
                    // Connessione chiusa (timeout del proxy): il polling dirà come è finita
                    avanzamentoTesto.textContent = 'La connessione si è chiusa ma l\'aggiornamento può essere ancora in corso: attendo l\'esito…';
                });
        });
    }

    var formAggiorna = document.getElementById('formAggiorna');
    if (formAggiorna) {
        formAggiorna.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!window.confirm('Avviare l\'aggiornamento alla versione ' + formAggiorna.dataset.versione + '? Verrà creato un backup del database e dei file, e il sito andrà in manutenzione per qualche minuto.')) { return; }
            invia(formAggiorna, 'Aggiornamento alla v' + formAggiorna.dataset.versione + ' in corso: backup, download e verifica del pacchetto, copia dei file, migration. Non chiudere la pagina.');
        });
    }

    var formCarica = document.getElementById('formCarica');
    if (formCarica) {
        formCarica.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!window.confirm('Aggiornare con il pacchetto caricato? Verrà creato un backup e il sito andrà in manutenzione per qualche minuto.')) { return; }
            invia(formCarica, 'Caricamento del pacchetto e aggiornamento in corso. Non chiudere la pagina.');
        });
    }

    var btnControlla = document.getElementById('btnControlla');
    if (btnControlla) {
        btnControlla.addEventListener('click', function (e) {
            e.preventDefault();
            btnControlla.textContent = 'controllo…';
            fetch(card.dataset.urlControlla, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (s) {
                    btnControlla.textContent = 'controlla ora';
                    if (s.errore) { mostraEsito(false, s.errore); return; }
                    if (s.disponibile) { window.location.reload(); return; }
                    mostraEsito(true, 'Nessun aggiornamento: v' + s.corrente + ' è l\'ultima versione.');
                })
                .catch(function () { btnControlla.textContent = 'controlla ora'; mostraEsito(false, 'Controllo non riuscito.'); });
        });
    }

    if (card.dataset.inCorso === '1') {
        avvia('Un aggiornamento è in corso in un altro processo: attendo l\'esito…');
        avviaPolling();
    }
})();
