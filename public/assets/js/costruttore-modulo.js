// Costruttore del modulo di iscrizione: riordino con SortableJS, interruttori e anteprima dal vivo.
(function () {
    'use strict';

    var form = document.getElementById('builderForm');
    if (!form) return;
    var $ = function (id) { return document.getElementById(id); };

    // ---- Riordino ----
    function rinumera() {
        var lista = $('listaStandard');
        if (lista) lista.querySelectorAll('[data-ordine]').forEach(function (input, i) { input.value = i + 1; });
        anteprima();
    }
    if (window.Sortable) {
        ['listaStandard', 'listaPersonalizzati'].forEach(function (id) {
            if ($(id)) window.Sortable.create($(id), { handle: '.field-grip', animation: 150, ghostClass: 'kanban-ghost', onEnd: rinumera });
        });
    }

    // ---- Interruttori ----
    form.addEventListener('change', function (e) {
        var riga = e.target.closest('.field-row');
        if (riga) {
            var visibile = riga.querySelector('[data-ruolo="visibile"]');
            var obbligatorio = riga.querySelector('[data-ruolo="obbligatorio"]');
            // Un campo nascosto non può essere obbligatorio; renderlo obbligatorio lo mostra
            if (e.target === visibile && !visibile.checked && obbligatorio) obbligatorio.checked = false;
            if (e.target === obbligatorio && obbligatorio.checked && visibile) visibile.checked = true;
            riga.classList.toggle('is-off', visibile && !visibile.checked);
            var stella = riga.querySelector('.req');
            if (stella && obbligatorio) stella.hidden = !(obbligatorio.checked && visibile.checked);
        }
        if (e.target.id === 'iscrizioni_socio_aperte') {
            var badge = $('badgeStato');
            badge.textContent = e.target.checked ? 'Pubblicato' : 'Chiuso';
            badge.className = 'badge ' + (e.target.checked ? 'bg-success' : 'bg-secondary');
        }
        anteprima();
    });
    $('intro_html').addEventListener('input', anteprima);

    // ---- Anteprima ----
    function campoAnteprima(riga) {
        var obb = riga.querySelector('[data-ruolo="obbligatorio"]');
        var obbligatorio = obb ? obb.checked : riga.dataset.obbligatorio === '1';
        var tipo = riga.dataset.tipo || 'text';
        var etichetta = riga.dataset.anteprimaCampo + (obbligatorio ? ' *' : '');
        var wrap = document.createElement('div');
        wrap.className = 'mb-2';
        if (tipo === 'checkbox') {
            wrap.className = 'form-check small mb-2';
            var c = document.createElement('input'); c.type = 'checkbox'; c.className = 'form-check-input'; c.disabled = true;
            var l = document.createElement('label'); l.className = 'form-check-label'; l.textContent = etichetta;
            wrap.appendChild(c); wrap.appendChild(l);
            return wrap;
        }
        var label = document.createElement('label');
        label.className = 'form-label small mb-1'; label.textContent = etichetta;
        wrap.appendChild(label);
        var campo;
        if (tipo === 'select') {
            campo = document.createElement('select'); campo.className = 'form-select form-select-sm';
            (riga.dataset.opzioni || '').split('\n').filter(Boolean).slice(0, 4).forEach(function (o) {
                var op = document.createElement('option'); op.textContent = o.trim(); campo.appendChild(op);
            });
        } else if (tipo === 'textarea') {
            campo = document.createElement('textarea'); campo.className = 'form-control form-control-sm'; campo.rows = 2;
        } else {
            campo = document.createElement('input'); campo.className = 'form-control form-control-sm';
            campo.type = ['date', 'email', 'number', 'tel'].indexOf(tipo) >= 0 ? tipo : 'text';
        }
        campo.disabled = true;
        wrap.appendChild(campo);
        return wrap;
    }
    function anteprima() {
        var box = $('previewFields');
        box.textContent = '';
        var righe = form.querySelectorAll('.field-row');
        var visibili = 0;
        righe.forEach(function (riga) {
            var v = riga.querySelector('[data-ruolo="visibile"]');
            if (v && !v.checked) return;
            visibili++;
            box.appendChild(campoAnteprima(riga));
        });
        var conteggio = form.querySelector('[data-conteggio-campi]');
        if (conteggio) conteggio.textContent = visibili + (visibili === 1 ? ' campo' : ' campi') + ' nel modulo';
        // Il testo introduttivo nell'anteprima è solo testo (niente HTML eseguito qui)
        var tmp = document.createElement('div');
        tmp.innerHTML = $('intro_html').value.replace(/<script[\s\S]*?<\/script>/gi, '');
        $('previewIntro').textContent = (tmp.textContent || '').trim();
    }

    document.querySelectorAll('[data-dispositivo]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('[data-dispositivo]').forEach(function (x) { x.classList.toggle('active', x === b); });
            $('previewFrame').classList.toggle('is-mobile', b.dataset.dispositivo === 'mobile');
        });
    });

    // ---- Nuovo campo dalla palette ----
    var titoli = { text: 'Testo breve', email: 'Email', date: 'Data', select: 'Scelta', checkbox: 'Casella di consenso', number: 'Numero', tel: 'Telefono', textarea: 'Testo lungo' };
    document.querySelectorAll('[data-nuovo-campo]').forEach(function (b) {
        b.addEventListener('click', function () {
            var tipo = b.dataset.nuovoCampo;
            $('nuovoTipo').value = tipo;
            $('modaleCampoTitolo').textContent = 'Nuovo campo: ' + (titoli[tipo] || tipo);
            $('nuoveOpzioni').hidden = tipo !== 'select';
            $('nuovoOpzioniTesto').required = tipo === 'select';
            bootstrap.Modal.getOrCreateInstance($('modaleCampo')).show();
        });
    });
    $('modaleCampo').addEventListener('shown.bs.modal', function () { $('nuovoNome').focus(); });

    // ---- Copia link / embed ----
    function copia(testo, bottone) {
        navigator.clipboard.writeText(testo).then(function () {
            var prima = bottone.innerHTML;
            bottone.textContent = 'Copiato';
            setTimeout(function () { bottone.innerHTML = prima; }, 1500);
        });
    }
    document.querySelectorAll('[data-copia]').forEach(function (b) { b.addEventListener('click', function () { copia(b.dataset.copia, b); }); });
    document.querySelectorAll('[data-copia-da]').forEach(function (b) { b.addEventListener('click', function () { copia($(b.dataset.copiaDa).value, b); }); });

    anteprima();
})();
