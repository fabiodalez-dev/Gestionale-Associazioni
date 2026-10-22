<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\ModuliRequest;
use App\Models\CampoPersonalizzato;
use App\Settings\ModuliSettings;
use Illuminate\Http\RedirectResponse;
use Mews\Purifier\Facades\Purifier;

/**
 * Configurazioni → Moduli pubblici: aspetto, testi, campi e consensi della
 * pre-iscrizione e dell'iscrizione online (ModuliSettings).
 */
class ModuliController extends Controller
{
    private const INTRO_HTML_ALLOWED = 'p,b,strong,i,em,u,ul,ol,li,a[href|title|target],br';

    public function update(ModuliRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();
        $dati = $request->validated();

        $intro = trim((string) ($dati['intro_preiscrizione_html'] ?? ''));
        $visibili = (array) ($dati['campi_visibili'] ?? []);

        // Tutti i campi personalizzati selezionati = null, così i nuovi compaiono da soli
        $tutti = CampoPersonalizzato::query()->forAssociazione($associazione->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $scelti = array_values(array_map('strval', (array) ($dati['campi_personalizzati'] ?? [])));
        $personalizzati = array_diff($tutti, $scelti) === [] ? null : $scelti;

        $associazione->impostazioni(ModuliSettings::class)->aggiorna([
            'colore_primario' => $dati['colore_primario'],
            'mostra_logo' => $request->boolean('mostra_logo'),
            'titolo_preiscrizione' => $dati['titolo_preiscrizione'] ?? null,
            'intro_preiscrizione_html' => $intro === '' ? null : Purifier::clean($intro, ['HTML.Allowed' => self::INTRO_HTML_ALLOWED]),
            'messaggio_conferma' => $dati['messaggio_conferma'] ?? null,
            'campi_nascosti_preiscrizione' => array_values(array_diff(ModuliSettings::CAMPI_NASCONDIBILI, $visibili)),
            'campi_personalizzati_preiscrizione' => $personalizzati,
            'consensi' => array_values((array) ($dati['consensi'] ?? [])),
        ]);

        return redirect()->route('configurazioni.index', ['section' => 'moduli'])
            ->with('success', 'Moduli pubblici aggiornati.');
    }
}
