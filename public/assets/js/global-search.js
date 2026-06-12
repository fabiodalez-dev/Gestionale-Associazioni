/**
 * Ricerca globale / command palette ⌘K (f.1 piano "Gestione 2").
 *
 * - Apertura con ⌘K / Ctrl+K o click sull'icona lente nella sidebar
 *   (#globalSearchBtn, predisposta dalle Fondamenta).
 * - Interroga l'endpoint JSON tenant-scoped (data-endpoint sulla modale)
 *   con debounce; risultati raggruppati (soci, eventi, prenotazioni,
 *   documenti, pagine admin).
 * - Navigazione da tastiera: frecce su/giù + Invio, Esc chiude (Bootstrap).
 *
 * File esterno: nessuno script inline, compatibile con CSP restrittive.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('globalSearchModal');
        var input = document.getElementById('globalSearchInput');
        var results = document.getElementById('globalSearchResults');
        var openBtn = document.getElementById('globalSearchBtn');

        if (!modalEl || !input || !results || typeof bootstrap === 'undefined') return;

        var endpoint = modalEl.dataset.endpoint;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var debounceTimer = null;
        var activeIndex = -1;
        var lastController = null;

        function open() {
            modal.show();
        }

        modalEl.addEventListener('shown.bs.modal', function () {
            input.focus();
            input.select();
        });

        if (openBtn) openBtn.addEventListener('click', open);

        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                open();
            }
        });

        function hint(text) {
            results.textContent = '';
            var p = document.createElement('p');
            p.className = 'text-muted small text-center my-3';
            p.textContent = text;
            results.appendChild(p);
        }

        function items() {
            return Array.prototype.slice.call(results.querySelectorAll('a.gs-item'));
        }

        function setActive(index) {
            var list = items();
            if (!list.length) return;
            if (index < 0) index = list.length - 1;
            if (index >= list.length) index = 0;
            list.forEach(function (el, i) {
                el.classList.toggle('active', i === index);
                el.setAttribute('aria-selected', i === index ? 'true' : 'false');
            });
            activeIndex = index;
            list[index].scrollIntoView({ block: 'nearest' });
        }

        function render(data) {
            results.textContent = '';
            activeIndex = -1;

            if (!data.gruppi || !data.gruppi.length) {
                hint('Nessun risultato per "' + data.query + '".');
                return;
            }

            data.gruppi.forEach(function (gruppo) {
                var header = document.createElement('div');
                header.className = 'px-3 pt-2 pb-1 small text-uppercase text-muted fw-semibold';
                var icon = document.createElement('i');
                icon.className = 'bi ' + (gruppo.icona || 'bi-collection') + ' me-1';
                header.appendChild(icon);
                header.appendChild(document.createTextNode(gruppo.gruppo));
                results.appendChild(header);

                var listGroup = document.createElement('div');
                listGroup.className = 'list-group list-group-flush';

                gruppo.risultati.forEach(function (r) {
                    var a = document.createElement('a');
                    a.className = 'list-group-item list-group-item-action gs-item py-2';
                    a.href = r.url;
                    a.setAttribute('role', 'option');

                    var title = document.createElement('div');
                    title.className = 'fw-semibold';
                    title.textContent = r.titolo;
                    a.appendChild(title);

                    if (r.sottotitolo) {
                        var sub = document.createElement('div');
                        sub.className = 'small text-muted';
                        sub.textContent = r.sottotitolo;
                        a.appendChild(sub);
                    }

                    a.addEventListener('mousemove', function () {
                        setActive(items().indexOf(a));
                    });

                    listGroup.appendChild(a);
                });

                results.appendChild(listGroup);
            });

            setActive(0);
        }

        function search() {
            var q = input.value.trim();

            if (q.length < 2) {
                hint('Digita almeno 2 caratteri. Usa ↑ ↓ e Invio per navigare.');
                return;
            }

            if (lastController) lastController.abort();
            lastController = window.AbortController ? new AbortController() : null;

            fetch(endpoint + '?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                signal: lastController ? lastController.signal : undefined
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(render)
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    hint(err === 429 ? 'Troppe ricerche: attendi qualche secondo.' : 'Errore di ricerca, riprova.');
                });
        }

        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(search, 250);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(activeIndex + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(activeIndex - 1);
            } else if (e.key === 'Enter') {
                var list = items();
                if (activeIndex >= 0 && list[activeIndex]) {
                    e.preventDefault();
                    window.location.href = list[activeIndex].href;
                }
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            input.value = '';
            hint('Digita almeno 2 caratteri. Usa ↑ ↓ e Invio per navigare.');
        });
    });
})();
