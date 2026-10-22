// CRM a colonne: trascinamento con SortableJS e modale di creazione/modifica.
(function () {
    'use strict';

    var board = document.getElementById('kanban');
    if (!board) return;
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var modificabile = board.dataset.modificabile === '1';
    var euro = function (v) { return '€ ' + Math.round(v).toLocaleString('it-IT'); };

    function aggiornaColonna(lista) {
        var col = lista.closest('.kanban-col');
        var schede = lista.querySelectorAll('.kanban-card');
        var somma = 0;
        schede.forEach(function (s) { somma += parseFloat(s.dataset.valore || '0'); });
        col.querySelector('[data-conta]').textContent = schede.length;
        col.querySelector('[data-somma]').textContent = euro(somma);
    }

    if (modificabile && window.Sortable) {
        board.querySelectorAll('.kanban-list').forEach(function (lista) {
            window.Sortable.create(lista, {
                group: 'crm-' + board.dataset.pipeline,
                animation: 160,
                ghostClass: 'kanban-ghost',
                chosenClass: 'kanban-chosen',
                delay: 120,
                delayOnTouchOnly: true,
                onEnd: function (evt) {
                    var scheda = evt.item;
                    var destinazione = evt.to;
                    aggiornaColonna(evt.from);
                    aggiornaColonna(destinazione);
                    if (evt.from === destinazione && evt.oldIndex === evt.newIndex) return;
                    var ordine = Array.prototype.map.call(destinazione.querySelectorAll('.kanban-card'), function (s) { return s.dataset.id; });
                    fetch(board.dataset.spostaUrl + '/' + scheda.dataset.id + '/sposta', {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ fase: destinazione.dataset.fase, ordine: ordine })
                    })
                        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
                        .then(function (d) {
                            scheda.classList.toggle('is-won', d.esito === 'vinta');
                            var dati = JSON.parse(scheda.dataset.scheda);
                            dati.fase = destinazione.dataset.fase; dati.esito = d.esito;
                            scheda.dataset.scheda = JSON.stringify(dati);
                        })
                        .catch(function () {
                            if (typeof showToast === 'function') showToast('Spostamento non salvato: ricarico la pagina', 'error');
                            setTimeout(function () { location.reload(); }, 900);
                        });
                }
            });
        });
    }

    // ---- Modale ----
    var modale = document.getElementById('crmModal');
    if (!modale) return;
    var form = document.getElementById('crmForm');
    var istanza = function () { return bootstrap.Modal.getOrCreateInstance(modale); };
    var campi = ['titolo', 'descrizione', 'origine', 'valore', 'email', 'telefono'];

    function apriNuovo(fase) {
        form.reset();
        form.action = form.dataset.store;
        document.getElementById('crmMetodo').value = 'POST';
        document.getElementById('crmPipeline').disabled = false;
        document.getElementById('crm_fase').value = fase;
        document.getElementById('crmModalTitolo').textContent = 'Nuovo contatto';
        document.getElementById('crmAzioniScheda').hidden = true;
        istanza().show();
    }

    function apriScheda(scheda) {
        var d = JSON.parse(scheda.dataset.scheda);
        form.action = form.dataset.base + '/' + d.id;
        document.getElementById('crmMetodo').value = 'PUT';
        // La pipeline non si cambia in modifica
        document.getElementById('crmPipeline').disabled = true;
        campi.forEach(function (c) { document.getElementById('crm_' + c).value = d[c] == null ? '' : d[c]; });
        document.getElementById('crm_fase').value = d.fase;
        document.getElementById('crm_azione').value = d.prossima_azione_il || '';
        document.getElementById('crm_responsabile').value = d.responsabile_id || '';
        document.getElementById('crmModalTitolo').textContent = d.titolo;
        document.getElementById('crmAzioniScheda').hidden = false;
        document.getElementById('crmPersa').action = form.dataset.base + '/' + d.id + '/persa';
        document.getElementById('crmElimina').action = form.dataset.base + '/' + d.id;
        var crea = document.getElementById('crmCreaSocio');
        if (crea) {
            var parti = (d.titolo || '').trim().split(/\s+/);
            var params = new URLSearchParams({ nome: parti.shift() || '', cognome: parti.join(' '), email: d.email || '', telefono: d.telefono || '' });
            crea.href = crea.dataset.base + '?' + params.toString();
        }
        istanza().show();
    }

    document.querySelectorAll('[data-crm-nuovo]').forEach(function (b) {
        b.addEventListener('click', function () { apriNuovo(b.dataset.crmNuovo); });
    });
    board.addEventListener('click', function (e) {
        var scheda = e.target.closest('.kanban-card');
        if (scheda && modificabile) apriScheda(scheda);
    });
    board.addEventListener('keydown', function (e) {
        var scheda = e.target.closest('.kanban-card');
        if (scheda && modificabile && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); apriScheda(scheda); }
    });
})();
