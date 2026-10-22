/*
 * Indicatore di robustezza password (zxcvbn-ts, dizionari comune + italiano).
 * Rispecchia App\Support\PasswordPolicy: la validazione vera resta sul server.
 *
 * Markup atteso (widget Formello "password"):
 *   <input data-password-strength data-min-score="3" data-min-length="10"
 *          data-password-inputs="#nome,#cognome,#email" data-password-dati='["mario"]'>
 *   <div data-password-meter-for="ID_INPUT"> … </div>
 *   <button data-toggle-password="ID_INPUT">
 */
import { zxcvbn, zxcvbnOptions } from '@zxcvbn-ts/core';
import * as common from '@zxcvbn-ts/language-common';
import * as it from '@zxcvbn-ts/language-it';

zxcvbnOptions.setOptions({
    translations: it.translations,
    graphs: common.adjacencyGraphs,
    dictionary: { ...common.dictionary, ...it.dictionary },
});

const ETICHETTE = ['Molto debole', 'Debole', 'Sufficiente', 'Buona', 'Ottima'];
const COLORI = ['bg-danger', 'bg-danger', 'bg-warning', 'bg-success', 'bg-success'];

function datiUtente(input) {
    const dati = [];
    try {
        JSON.parse(input.dataset.passwordDati || '[]').forEach((v) => v && dati.push(String(v)));
    } catch (e) { /* attributo non valido: ignorato */ }
    (input.dataset.passwordInputs || '').split(',').filter(Boolean).forEach((sel) => {
        const campo = document.querySelector(sel.trim());
        if (campo && campo.value) dati.push(campo.value);
    });
    return dati;
}

function aggiorna(input) {
    const meter = document.querySelector('[data-password-meter-for="' + input.id + '"]');
    if (!meter) return;
    const barra = meter.querySelector('[data-meter-bar]');
    const testo = meter.querySelector('[data-meter-text]');
    const valore = input.value;

    if (valore === '') {
        meter.hidden = true;
        return;
    }
    meter.hidden = false;

    const minScore = parseInt(input.dataset.minScore || '3', 10);
    const minLength = parseInt(input.dataset.minLength || '10', 10);
    const dati = datiUtente(input);
    const esito = zxcvbn(valore, dati);
    // Come PasswordPolicy: niente nome, cognome o email (anche solo la parte prima della @)
    const minuscola = valore.toLowerCase();
    const contieneDati = dati
        .flatMap((d) => { const v = d.trim().toLowerCase(); return v.includes('@') ? [v, v.split('@')[0]] : [v]; })
        .some((d) => d.length >= 3 && minuscola.includes(d));
    const ok = esito.score >= minScore && valore.length >= minLength && !contieneDati;

    barra.style.width = ((esito.score + 1) * 20) + '%';
    // Verde solo se la password rispetta tutti i requisiti
    barra.className = 'progress-bar ' + (ok ? COLORI[esito.score] : (esito.score >= 2 ? 'bg-warning' : 'bg-danger'));

    let messaggio = ETICHETTE[esito.score];
    if (contieneDati) {
        messaggio += ' · non usare nome, cognome o email';
    } else if (valore.length < minLength) {
        messaggio += ' · servono almeno ' + minLength + ' caratteri';
    } else if (!ok) {
        const consiglio = esito.feedback.warning || esito.feedback.suggestions[0];
        messaggio += consiglio ? ' · ' + consiglio : ' · rendila meno prevedibile';
    } else {
        messaggio += ' · va bene';
    }
    testo.textContent = messaggio;
    testo.className = 'small ' + (ok ? 'text-success' : 'text-body-secondary');
}

function init() {
    document.querySelectorAll('input[data-password-strength]').forEach((input) => {
        input.addEventListener('input', () => aggiorna(input));
        if (input.value) aggiorna(input);
    });
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
        if (btn.dataset.passwordToggleBound) return;
        btn.dataset.passwordToggleBound = '1';
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.togglePassword);
            if (!input) return;
            const mostra = input.type === 'password';
            input.type = mostra ? 'text' : 'password';
            btn.setAttribute('aria-pressed', mostra ? 'true' : 'false');
            btn.setAttribute('aria-label', mostra ? 'Nascondi password' : 'Mostra password');
            const icona = btn.querySelector('i');
            if (icona) icona.className = mostra ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
