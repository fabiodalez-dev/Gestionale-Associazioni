/*
 * Azioni dichiarative tramite attributi data-*.
 *
 * Sostituiscono gli handler inline (onsubmit/onclick/onchange) che la
 * Content-Security-Policy con nonce blocca: i listener sono delegati sul
 * document, quindi funzionano anche per il markup inserito dopo il caricamento.
 *
 *   <form data-conferma="Messaggio">           conferma prima dell'invio
 *     data-conferma-salta-action="/test"        ...salvo se il pulsante che invia punta a quell'URL
 *   <button|a data-conferma="Messaggio">       conferma prima del click
 *   <select data-invio-automatico>             invia il form al cambio
 *   <select data-imposta-action="idForm">      imposta l'action del form da option[data-action]
 *   <form data-richiede-action>                blocca l'invio finché l'action non è impostata
 *   <select data-mostra-se-valore="v" data-mostra-target="id">  mostra l'elemento solo se value === v
 *   <input type=checkbox data-seleziona-tutti="selettore">      spunta/despunta i checkbox indicati
 *   <button data-copia-da="id">                copia value (o testo) dell'elemento negli appunti
 *     data-copia-feedback="testo" [data-copia-icona="classi bi"] data-copia-ripristina="ms"
 *   <button data-stampa>                       window.print()
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.hasAttribute('data-richiede-action')) {
            var action = form.getAttribute('action') || '';
            if (action === '' || form.action === window.location.href) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
        }

        if (form.hasAttribute('data-conferma')) {
            var salta = form.getAttribute('data-conferma-salta-action');
            var submitter = e.submitter;
            if (salta && submitter && submitter.formAction && submitter.formAction.indexOf(salta) !== -1) {
                return;
            }
            if (!window.confirm(form.getAttribute('data-conferma'))) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }
    }, true);

    document.addEventListener('click', function (e) {
        var target = e.target instanceof Element ? e.target : null;
        if (!target) {
            return;
        }

        var conferma = target.closest('[data-conferma]:not(form)');
        if (conferma && !window.confirm(conferma.getAttribute('data-conferma'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }

        var tutti = target.closest('input[data-seleziona-tutti]');
        if (tutti) {
            document.querySelectorAll(tutti.getAttribute('data-seleziona-tutti')).forEach(function (c) {
                c.checked = tutti.checked;
            });
        }

        var copia = target.closest('[data-copia-da]');
        if (copia) {
            copiaNegliAppunti(copia);
        }

        if (target.closest('[data-stampa]')) {
            window.print();
        }
    }, true);

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!(el instanceof Element)) {
            return;
        }

        if (el.hasAttribute('data-imposta-action') && el instanceof HTMLSelectElement) {
            var form = document.getElementById(el.getAttribute('data-imposta-action'));
            var opzione = el.options[el.selectedIndex];
            if (form) {
                form.setAttribute('action', (opzione && opzione.dataset.action) || '');
            }
        }

        if (el.hasAttribute('data-mostra-target')) {
            var bersaglio = document.getElementById(el.getAttribute('data-mostra-target'));
            if (bersaglio) {
                bersaglio.style.display = el.value === el.getAttribute('data-mostra-se-valore') ? '' : 'none';
            }
        }

        if (el.hasAttribute('data-invio-automatico') && el.form) {
            el.form.submit();
        }
    });

    function copiaNegliAppunti(pulsante) {
        var sorgente = document.getElementById(pulsante.getAttribute('data-copia-da'));
        if (!sorgente || !navigator.clipboard) {
            return;
        }
        var testo = 'value' in sorgente ? sorgente.value : sorgente.textContent;
        var promessa = navigator.clipboard.writeText(testo);
        var feedback = pulsante.getAttribute('data-copia-feedback');
        if (feedback === null) {
            return;
        }
        var icona = pulsante.getAttribute('data-copia-icona');
        promessa.then(function () {
            // Niente innerHTML: il contenuto originale si conserva come nodi e il
            // feedback (testo più icona facoltativa) si costruisce senza
            // reinterpretare stringhe come HTML
            var originale = Array.prototype.map.call(pulsante.childNodes, function (n) { return n.cloneNode(true); });
            var nodi = [];
            if (icona) {
                var i = document.createElement('i');
                i.className = icona;
                nodi.push(i);
            }
            nodi.push(document.createTextNode(feedback));
            pulsante.replaceChildren.apply(pulsante, nodi);
            var ms = parseInt(pulsante.getAttribute('data-copia-ripristina') || '', 10);
            if (ms > 0) {
                setTimeout(function () { pulsante.replaceChildren.apply(pulsante, originale); }, ms);
            }
        });
    }
})();
