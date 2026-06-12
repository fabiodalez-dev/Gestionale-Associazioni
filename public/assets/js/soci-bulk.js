/**
 * Azioni in blocco sulla lista soci (f.2 piano "Gestione 2").
 *
 * - Checkbox per riga + "seleziona tutti" nella tabella (anche dopo i
 *   re-render AJAX della lista: event delegation su #sociResults).
 * - Barra azioni (#bulkBar) visibile solo con almeno un socio selezionato.
 * - Conferma con riepilogo (modale) prima dell'applicazione.
 * - Export della selezione: submit diretto (formaction dedicata).
 *
 * File esterno: nessuno script inline, compatibile con CSP restrittive.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var results = document.getElementById('sociResults');
        var bar = document.getElementById('bulkBar');
        var form = document.getElementById('bulkForm');

        if (!results || !bar || !form) return;

        var countEl = document.getElementById('bulkCount');
        var azioneSel = document.getElementById('bulkAzione');
        var valoreSel = document.getElementById('bulkValore');
        var idsContainer = document.getElementById('bulkIdsContainer');

        function selected() {
            return Array.prototype.slice.call(results.querySelectorAll('.socio-check:checked'));
        }

        function syncBar() {
            var checks = selected();
            countEl.textContent = String(checks.length);
            bar.classList.toggle('d-none', checks.length === 0);

            idsContainer.textContent = '';
            checks.forEach(function (c) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'soci_ids[]';
                input.value = c.value;
                idsContainer.appendChild(input);
            });
        }

        // Delegation: la lista viene rimpiazzata via AJAX dai filtri
        results.addEventListener('change', function (e) {
            var t = e.target;
            if (!t) return;

            if (t.id === 'selectAllSoci') {
                results.querySelectorAll('.socio-check').forEach(function (c) {
                    c.checked = t.checked;
                });
            }

            if (t.id === 'selectAllSoci' || t.classList.contains('socio-check')) {
                syncBar();
            }
        });

        // Cambio azione → opzioni valore corrispondenti (dai <template>)
        azioneSel.addEventListener('change', function () {
            var tpl = document.getElementById('bulkOpzioni-' + azioneSel.value);
            if (!tpl) return;
            while (valoreSel.firstChild) valoreSel.removeChild(valoreSel.firstChild);
            valoreSel.appendChild(tpl.content.cloneNode(true));
            valoreSel.dataset.azione = azioneSel.value;
        });

        // Riepilogo nella modale di conferma
        var modalEl = document.getElementById('bulkConfermaModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', function () {
                document.getElementById('bulkRiepilogoAzione').textContent =
                    azioneSel.options[azioneSel.selectedIndex] ? azioneSel.options[azioneSel.selectedIndex].text : '';
                document.getElementById('bulkRiepilogoValore').textContent =
                    valoreSel.options[valoreSel.selectedIndex] ? valoreSel.options[valoreSel.selectedIndex].text : '';
                document.getElementById('bulkRiepilogoCount').textContent = countEl.textContent;
            });
        }

        var confirmBtn = document.getElementById('bulkConfermaSubmit');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                form.submit();
            });
        }

        var annulla = document.getElementById('bulkAnnulla');
        if (annulla) {
            annulla.addEventListener('click', function () {
                results.querySelectorAll('.socio-check, #selectAllSoci').forEach(function (c) {
                    c.checked = false;
                });
                syncBar();
            });
        }
    });
})();
