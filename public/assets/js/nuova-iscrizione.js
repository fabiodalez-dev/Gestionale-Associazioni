// Nuova iscrizione a passi: navigazione, validazione per passo, riepilogo e controlli automatici.
(function () {
    'use strict';

    var form = document.getElementById('wizardIscrizione');
    if (!form) return;

    var passi = Array.prototype.slice.call(form.querySelectorAll('.wizard-step'));
    var tab = Array.prototype.slice.call(form.querySelectorAll('[data-step-tab]'));
    var btnAvanti = form.querySelector('[data-avanti]');
    var btnIndietro = form.querySelector('[data-indietro]');
    var btnConferma = form.querySelector('[data-conferma]');
    var corrente = parseInt(form.dataset.passoIniziale || '1', 10);
    var massimoRaggiunto = corrente;
    var $ = function (id) { return document.getElementById(id); };

    function campiDelPasso(n) {
        return Array.prototype.slice.call(passi[n - 1].querySelectorAll('input, select, textarea'))
            .filter(function (c) { return c.type !== 'hidden' && !c.disabled; });
    }

    function valido(n) {
        var campi = campiDelPasso(n);
        for (var i = 0; i < campi.length; i++) {
            if (!campi[i].checkValidity()) {
                campi[i].reportValidity();
                return false;
            }
        }
        return true;
    }

    function mostra(n) {
        corrente = n;
        massimoRaggiunto = Math.max(massimoRaggiunto, n);
        passi.forEach(function (p, i) { p.hidden = i !== n - 1; });
        tab.forEach(function (t, i) {
            t.classList.toggle('is-active', i === n - 1);
            t.classList.toggle('is-done', i < n - 1);
            t.querySelector('button').setAttribute('aria-current', i === n - 1 ? 'step' : 'false');
        });
        btnIndietro.style.visibility = n === 1 ? 'hidden' : 'visible';
        btnAvanti.hidden = n === passi.length;
        btnConferma.hidden = n !== passi.length;
        if (n === passi.length) riepilogoFinale();
        var primo = passi[n - 1].querySelector('.is-invalid') || null;
        if (primo) primo.focus();
    }

    btnAvanti.addEventListener('click', function () {
        if (valido(corrente)) {
            mostra(corrente + 1);
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    btnIndietro.addEventListener('click', function () { if (corrente > 1) mostra(corrente - 1); });
    form.querySelectorAll('[data-vai-passo]').forEach(function (b) {
        b.addEventListener('click', function () {
            var n = parseInt(b.dataset.vaiPasso, 10);
            if (n <= corrente || n <= massimoRaggiunto) { mostra(n); return; }
            // avanti di più passi: ogni passo intermedio deve essere valido
            for (var i = corrente; i < n; i++) { if (!valido(i)) { mostra(i); return; } }
            mostra(n);
        });
    });
    form.addEventListener('submit', function (e) {
        for (var i = 1; i <= passi.length; i++) {
            if (!valido(i)) { e.preventDefault(); mostra(i); valido(i); return; }
        }
        btnConferma.disabled = true;
    });
    // Invio con Enter: avanza invece di inviare a metà
    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.tagName === 'INPUT' && corrente < passi.length) {
            e.preventDefault();
            btnAvanti.click();
        }
    });

    // ---- Riepilogo laterale ----
    var euro = function (v) {
        if (v === '' || v === null || isNaN(parseFloat(v))) return '—';
        return '€ ' + parseFloat(v).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    function setRiepilogo(chiave, testo) {
        var el = form.querySelector('[data-riepilogo="' + chiave + '"]');
        if (el) el.textContent = testo;
    }
    function aggiornaRiepilogo() {
        var nome = ($('nome').value + ' ' + $('cognome').value).trim();
        setRiepilogo('nome', nome || '—');
        var tipo = form.querySelector('input[name="tipo_socio_id"]:checked');
        setRiepilogo('tipo', tipo ? tipo.dataset.nome : '—');
        setRiepilogo('quota', tipo ? euro(tipo.dataset.costo) : '—');
        setRiepilogo('tessera', $('genera_tessera').checked ? 'Sì' : 'No');
        controlloObbligatori();
    }
    form.addEventListener('input', aggiornaRiepilogo);
    form.addEventListener('change', aggiornaRiepilogo);

    function riepilogoFinale() {
        var dl = $('riepilogoDati');
        dl.textContent = '';
        var voci = [
            ['Nome', ($('nome').value + ' ' + $('cognome').value).trim()],
            ['Email', $('email').value],
            ['Codice fiscale', $('codice_fiscale').value.toUpperCase()],
            ['Data di nascita', $('data_nascita').value ? new Date($('data_nascita').value).toLocaleDateString('it-IT') : ''],
            ['Telefono', $('telefono').value],
            ['Indirizzo', [$('indirizzo').value, $('cap').value, $('citta').value, $('provincia').value.toUpperCase()].filter(Boolean).join(' ')],
            ['Tipo di adesione', (form.querySelector('input[name="tipo_socio_id"]:checked') || {}).dataset ? form.querySelector('input[name="tipo_socio_id"]:checked').dataset.nome : ''],
            ['Categoria', $('categoria_socio_id').selectedIndex > 0 ? $('categoria_socio_id').selectedOptions[0].text : ''],
            ['Sede', $('sede_id').selectedIndex > 0 ? $('sede_id').selectedOptions[0].text : ''],
            ['Iscritto dal', $('data_iscrizione').value ? new Date($('data_iscrizione').value).toLocaleDateString('it-IT') : ''],
            ['Stato', $('stato').value],
        ];
        if (!$('cardTutore').classList.contains('d-none')) voci.push(['Tutore', [$('tutore_nome').value, $('tutore_telefono').value, $('tutore_email').value].filter(Boolean).join(' · ')]);
        voci.forEach(function (v) {
            var dt = document.createElement('dt'); dt.textContent = v[0];
            var dd = document.createElement('dd'); dd.textContent = v[1] || '—';
            dl.appendChild(dt); dl.appendChild(dd);
        });
    }

    // ---- Controlli automatici ----
    var lista = $('controlliAutomatici');
    function check(nome, stato, testo) {
        var li = lista.querySelector('[data-check="' + nome + '"]');
        li.className = 'is-' + stato;
        li.querySelector('span').textContent = testo;
    }
    function marca(input, errore, idFeedback) {
        var fb = form.querySelector('[data-feedback="' + idFeedback + '"]');
        input.classList.toggle('is-invalid', !!errore);
        input.setCustomValidity(errore || '');
        if (fb) fb.textContent = errore || '';
    }

    var timer = null;
    var ultima = '';
    function verificaRemota() {
        var email = $('email').value.trim();
        var cf = $('codice_fiscale').value.trim().toUpperCase();
        var chiave = email + '|' + cf;
        if (chiave === ultima) return;
        ultima = chiave;
        if (!email) check('email', 'pending', 'Email da inserire');
        if (!cf) { check('cf', 'pending', 'Codice fiscale da inserire'); marca($('codice_fiscale'), '', 'codice_fiscale'); }
        if (!email && !cf) return;
        var url = form.dataset.verificaUrl + '?' + new URLSearchParams({ email: email, codice_fiscale: cf }).toString();
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                if (d.email) {
                    if (!d.email.valida) { check('email', 'warn', 'Email non valida'); }
                    else if (d.email.duplicato) { check('email', 'error', 'Esiste già un socio con questa email'); marca($('email'), 'Esiste già un socio con questa email in questa associazione.', 'email'); }
                    else { check('email', 'ok', 'Email univoca nell\'associazione'); marca($('email'), '', 'email'); }
                }
                if (d.codice_fiscale) {
                    var c = d.codice_fiscale;
                    if (cf.length < 16) { check('cf', 'warn', 'Codice fiscale incompleto (' + cf.length + '/16)'); marca($('codice_fiscale'), '', 'codice_fiscale'); }
                    else if (!c.valido) { check('cf', 'error', 'Codice fiscale non valido'); marca($('codice_fiscale'), 'Il codice fiscale non è valido.', 'codice_fiscale'); }
                    else if (c.duplicato) { check('cf', 'error', 'Codice fiscale già presente'); marca($('codice_fiscale'), 'Esiste già un socio con questo codice fiscale.', 'codice_fiscale'); }
                    else {
                        check('cf', 'ok', 'Codice fiscale valido' + (c.sesso ? ' (' + (c.sesso === 'F' ? 'femmina' : 'maschio') + ')' : ''));
                        marca($('codice_fiscale'), '', 'codice_fiscale');
                        if (c.data_nascita && !$('data_nascita').value) { $('data_nascita').value = c.data_nascita; aggiornaEta(); }
                        else if (c.data_nascita && $('data_nascita').value !== c.data_nascita) { check('cf', 'warn', 'Data di nascita diversa da quella del codice fiscale'); }
                    }
                }
            })
            .catch(function () {});
    }
    ['email', 'codice_fiscale'].forEach(function (id) {
        $(id).addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(verificaRemota, 450); });
        $(id).addEventListener('blur', verificaRemota);
    });

    // ---- Tutore per minorenni ----
    var campiTutore = ['tutore_nome', 'tutore_telefono', 'tutore_email'].map($);
    function eta() {
        var v = $('data_nascita').value;
        if (!v) return null;
        var n = new Date(v), o = new Date();
        if (isNaN(n.getTime())) return null;
        var e = o.getFullYear() - n.getFullYear();
        var m = o.getMonth() - n.getMonth();
        if (m < 0 || (m === 0 && o.getDate() < n.getDate())) e--;
        return e;
    }
    function aggiornaEta() {
        var e = eta();
        var minore = e !== null && e < 18;
        var haValori = campiTutore.some(function (c) { return c.value.trim() !== ''; });
        $('cardTutore').classList.toggle('d-none', !minore && !haValori);
        campiTutore.forEach(function (c) { c.required = minore; });
        if (e === null) check('eta', 'pending', 'Età da verificare');
        else if (minore) check('eta', 'warn', 'Minorenne (' + e + ' anni): servono i dati del tutore');
        else check('eta', 'ok', 'Maggiorenne (' + e + ' anni)');
        if ($('codice_fiscale').value.trim().length === 16) { ultima = ''; verificaRemota(); }
    }
    $('data_nascita').addEventListener('change', aggiornaEta);

    function controlloObbligatori() {
        var mancanti = Array.prototype.slice.call(form.querySelectorAll('[required]'))
            .filter(function (c) { return c.type === 'radio' ? !form.querySelector('input[name="' + c.name + '"]:checked') : !c.value.trim(); });
        var nomi = {};
        mancanti.forEach(function (c) { nomi[c.name] = true; });
        var n = Object.keys(nomi).length;
        check('obbligatori', n ? 'warn' : 'ok', n ? (n === 1 ? 'Manca 1 campo obbligatorio' : 'Mancano ' + n + ' campi obbligatori') : 'Campi obbligatori compilati');
    }

    aggiornaEta();
    aggiornaRiepilogo();
    if ($('email').value || $('codice_fiscale').value) verificaRemota();
    mostra(corrente);
})();
