/*
 * "Scatta con la fotocamera" accanto ai campi di caricamento marcati con
 * data-scatta (#72): sui dispositivi touch apre l'app fotocamera del
 * telefono (capture), lasciando il normale selettore per la galleria e i
 * file. Il campo fotocamera è una copia del campo originale con lo stesso
 * nome, disattivata finché non la si usa: il form invia sempre un solo file.
 */
(function () {
    'use strict';

    if (!window.matchMedia || !window.matchMedia('(pointer: coarse)').matches) {
        return;
    }

    document.querySelectorAll('input[type="file"][data-scatta]').forEach(function (originale) {
        var fotocamera = document.createElement('input');
        fotocamera.type = 'file';
        fotocamera.name = originale.name;
        fotocamera.accept = 'image/*';
        fotocamera.setAttribute('capture', 'environment');
        fotocamera.multiple = originale.multiple;
        fotocamera.disabled = true;
        fotocamera.className = 'd-none';

        var pulsante = document.createElement('button');
        pulsante.type = 'button';
        pulsante.className = 'btn btn-sm btn-link px-0 mt-1';
        var icona = document.createElement('i');
        icona.className = 'bi bi-camera me-1';
        pulsante.appendChild(icona);
        pulsante.appendChild(document.createTextNode('Scatta con la fotocamera'));

        var nomeScelto = document.createElement('div');
        nomeScelto.className = 'form-text';

        pulsante.addEventListener('click', function () {
            fotocamera.disabled = false;
            fotocamera.click();
        });
        fotocamera.addEventListener('change', function () {
            if (!fotocamera.files.length) {
                fotocamera.disabled = true;
                return;
            }
            // Vale la foto appena scattata: il campo originale non viene inviato
            originale.disabled = true;
            originale.required = false;
            nomeScelto.textContent = fotocamera.files.length === 1
                ? 'Foto scattata: ' + fotocamera.files[0].name
                : fotocamera.files.length + ' foto scattate';
        });

        originale.insertAdjacentElement('afterend', nomeScelto);
        originale.insertAdjacentElement('afterend', pulsante);
        originale.insertAdjacentElement('afterend', fotocamera);
    });
})();
