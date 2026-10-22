<?php

namespace App\Services\Privacy;

use App\Models\Account;
use App\Models\Socio;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Spatie\PersonalDataExport\PersonalDataSelection;
use Spatie\PersonalDataExport\Zip;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Contenuto dell'archivio dei dati personali (dati.json, riepilogo.pdf,
 * documenti/...) e generazione immediata per la segreteria.
 */
class ArchivioDatiPersonali
{
    /** Nome dei file sul disco: <id titolare>_<timestamp>_<64 caratteri casuali>.zip */
    public const FORMATO_NOME = '/^[0-9a-f-]{36}_\d{10}_[A-Za-z0-9]{64}\.zip$/';

    public function __construct(private DatiPersonali $dati) {}

    public function riempi(PersonalDataSelection $selezione, Socio|Account $titolare): void
    {
        $dati = $titolare instanceof Socio ? $this->dati->socio($titolare) : $this->dati->account($titolare);

        $selezione->add('dati.json', $dati);
        $selezione->add('riepilogo.pdf', Pdf::loadView('privacy.dati-personali-pdf', [
            'titolare' => $titolare,
            'dati' => $dati,
        ])->output());

        if ($titolare instanceof Socio) {
            $disco = Storage::disk('local');
            $foto = $titolare->getFirstMedia(Socio::COLLEZIONE_FOTO);
            if ($foto !== null && is_file($foto->getPath())) {
                $selezione->add('foto/'.$foto->file_name, (string) file_get_contents($foto->getPath()));
            }

            foreach ($titolare->documenti()->get() as $documento) {
                if (filled($documento->percorso_file) && $disco->exists($documento->percorso_file)) {
                    $selezione->add($this->dati->percorsoDocumento($documento), (string) $disco->get($documento->percorso_file));
                }
            }
        }
    }

    /**
     * Crea l'archivio subito (export richiesto alla segreteria) e ne restituisce
     * il percorso in una cartella temporanea, da cancellare dopo il download.
     *
     * @return array{0: string, 1: TemporaryDirectory}
     */
    public function creaOra(Socio|Account $titolare): array
    {
        $cartella = (new TemporaryDirectory)->create();
        $selezione = (new PersonalDataSelection($cartella))->forUser($titolare);
        $this->riempi($selezione, $titolare);

        return [Zip::createForPersonalData($selezione, $cartella)->path(), $cartella];
    }
}
