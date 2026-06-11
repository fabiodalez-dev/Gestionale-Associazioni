<?php

namespace App\Services\Struttura;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\CategoriaSocio;
use App\Models\GruppoDinamico;
use App\Models\Sede;
use App\Models\Tag;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;

/**
 * Clonazione configurazioni da un'associazione all'altra (spec struttura §1.3.5).
 *
 * - Ogni dataset è opzionale (flag indipendenti);
 * - ogni riga clonata riceve un nuovo UUID e l'associazione di destinazione;
 * - i template tessera rimappano tipo_socio_id tramite la mappa dei tipi clonati
 *   (tipo non clonato o template default → tipo_socio_id NULL);
 * - utenti/admin e soci NON vengono mai clonati;
 * - obbligatorio_preiscrizione dei campi personalizzati NON viene copiato (come nel legacy).
 *
 * Da eseguire dentro una transazione del chiamante.
 */
class CloneAssociazioneConfigService
{
    /**
     * @param  array{tipi?: bool, categorie?: bool, sedi?: bool, campi?: bool, tags?: bool, gruppi?: bool, templates?: bool}  $flags
     */
    public function clone(Associazione $sorgente, Associazione $destinazione, array $flags): void
    {
        $tipoMap = [];

        if (! empty($flags['tipi'])) {
            $tipi = TipoSocio::query()->forAssociazione($sorgente->id)->get();
            foreach ($tipi as $tipo) {
                $nuovo = TipoSocio::create([
                    'associazione_id' => $destinazione->id,
                    'nome' => $tipo->nome,
                    'descrizione' => $tipo->descrizione,
                    'costo_tessera' => $tipo->costo_tessera,
                ]);
                $tipoMap[$tipo->id] = $nuovo->id;
            }
        }

        if (! empty($flags['categorie'])) {
            CategoriaSocio::query()->forAssociazione($sorgente->id)->get()
                ->each(fn (CategoriaSocio $categoria) => CategoriaSocio::create([
                    'associazione_id' => $destinazione->id,
                    'nome' => $categoria->nome,
                    'descrizione' => $categoria->descrizione,
                ]));
        }

        if (! empty($flags['sedi'])) {
            Sede::query()->forAssociazione($sorgente->id)->get()
                ->each(fn (Sede $sede) => Sede::create([
                    'associazione_id' => $destinazione->id,
                    'nome' => $sede->nome,
                    'indirizzo' => $sede->indirizzo,
                    'citta' => $sede->citta,
                    'provincia' => $sede->provincia,
                    'cap' => $sede->cap,
                    'email' => $sede->email,
                    'telefono' => $sede->telefono,
                    'responsabile' => $sede->responsabile,
                ]));
        }

        if (! empty($flags['campi'])) {
            // NB: obbligatorio_preiscrizione resta al default (non clonato, come nel legacy)
            CampoPersonalizzato::query()->forAssociazione($sorgente->id)->get()
                ->each(fn (CampoPersonalizzato $campo) => CampoPersonalizzato::create([
                    'associazione_id' => $destinazione->id,
                    'nome_campo' => $campo->nome_campo,
                    'tipo_campo' => $campo->tipo_campo,
                    'descrizione' => $campo->descrizione,
                    'opzioni' => $campo->opzioni,
                    'obbligatorio' => $campo->obbligatorio,
                    'ordine' => $campo->ordine,
                ]));
        }

        if (! empty($flags['tags'])) {
            Tag::query()->forAssociazione($sorgente->id)->get()
                ->each(fn (Tag $tag) => Tag::create([
                    'associazione_id' => $destinazione->id,
                    'nome_tag' => $tag->nome_tag,
                    'colore' => $tag->colore,
                ]));
        }

        if (! empty($flags['gruppi'])) {
            GruppoDinamico::query()->forAssociazione($sorgente->id)->get()
                ->each(fn (GruppoDinamico $gruppo) => GruppoDinamico::create([
                    'associazione_id' => $destinazione->id,
                    'nome_gruppo' => $gruppo->nome_gruppo,
                    'descrizione' => $gruppo->descrizione,
                    'filtri_json' => $gruppo->filtri_json,
                ]));
        }

        if (! empty($flags['templates'])) {
            TesseraTemplate::query()->forAssociazione($sorgente->id)->get()
                ->each(fn (TesseraTemplate $template) => TesseraTemplate::create([
                    'associazione_id' => $destinazione->id,
                    // Rimappa il tipo clonato; tipo non clonato o template default → NULL
                    'tipo_socio_id' => $tipoMap[$template->tipo_socio_id] ?? null,
                    'titolo' => $template->titolo,
                    'contenuto' => $template->contenuto,
                    'attivo' => $template->attivo,
                ]));
        }
    }
}
