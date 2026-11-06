<?php

namespace App\Services\Struttura;

use App\Models\ManualeEsecuzione;
use App\Models\ManualeEsecuzionePasso;
use App\Models\ManualePagina;
use App\Models\Utente;
use Illuminate\Support\Facades\DB;

/**
 * Manuale operativo (issue #12): modelli di procedure da cui partire e
 * checklist avviate dalle pagine.
 */
class ManualeService
{
    /**
     * Procedure suggerite: sezione, titolo, testo e passi.
     *
     * @var list<array{sezione: string, titolo: string, contenuto: string, passi: list<string>}>
     */
    public const MODELLI = [
        [
            'sezione' => 'Tesseramento',
            'titolo' => 'Apertura del nuovo anno sociale',
            'contenuto' => "Ogni anno, prima dell'inizio del tesseramento.\n\n## Prima di iniziare\n- Verifica con il tesoriere l'importo delle quote approvato dal direttivo.\n- Controlla che il modello della tessera sia aggiornato.",
            'passi' => [
                'Aggiornare le quote per tipo di socio',
                'Aprire il nuovo anno sociale dalla pagina Rinnovi',
                'Avviare la campagna rinnovi con email ai soci',
                'Controllare dopo due settimane chi non ha rinnovato e inviare i solleciti',
            ],
        ],
        [
            'sezione' => 'Eventi',
            'titolo' => 'Organizzazione di un evento',
            'contenuto' => "Dalla proposta alla chiusura dell'evento.\n\n## Da sapere\n- Gli eventi aperti al pubblico vanno pubblicati sul mini-sito almeno tre settimane prima.\n- Per gli eventi a pagamento serve la conferma del tesoriere.",
            'passi' => [
                'Approvare l\'evento in direttivo e fissare il budget',
                'Prenotare lo spazio e verificare i conflitti',
                'Creare l\'evento nel gestionale con capienza e prenotazioni',
                'Organizzare i turni dei volontari',
                'Comunicare l\'evento ai soci (annuncio o newsletter)',
                'Registrare le presenze e le spese',
                'Inviare il questionario di gradimento',
            ],
        ],
        [
            'sezione' => 'Soci',
            'titolo' => 'Accoglienza di un nuovo socio',
            'contenuto' => 'Cosa fare quando arriva una nuova richiesta di iscrizione.',
            'passi' => [
                'Verificare i dati della richiesta di iscrizione',
                'Approvare la richiesta con la delibera del direttivo',
                'Controllare che la tessera e la quota siano state generate',
                'Inviare il messaggio di benvenuto con le prossime attività',
                'Proporre al nuovo socio un referente per il primo periodo',
            ],
        ],
        [
            'sezione' => 'Assemblea',
            'titolo' => 'Preparazione dell\'assemblea di bilancio',
            'contenuto' => "L'assemblea ordinaria per l'approvazione del bilancio va convocata secondo i tempi dello statuto.\n\n## Documenti da preparare\n- Bilancio consuntivo e preventivo\n- Relazione del direttivo\n- Eventuale relazione dell'organo di controllo",
            'passi' => [
                'Far approvare il progetto di bilancio dal direttivo',
                'Creare l\'assemblea con prima e seconda convocazione',
                'Calcolare l\'elenco degli aventi diritto',
                'Inviare la convocazione nei tempi previsti dallo statuto',
                'Pubblicare i documenti in Trasparenza',
                'Preparare l\'appello e le deleghe',
                'Generare e far firmare il verbale',
            ],
        ],
    ];

    /**
     * Crea le procedure suggerite che non esistono già (stesso titolo).
     */
    public function importaModelli(): int
    {
        $esistenti = ManualePagina::query()->pluck('titolo')->map(fn ($t) => mb_strtolower($t))->flip();
        $create = 0;

        foreach (self::MODELLI as $ordine => $modello) {
            if ($esistenti->has(mb_strtolower($modello['titolo']))) {
                continue;
            }

            ManualePagina::create($modello + ['ordine' => $ordine, 'revisionata_at' => now()]);
            $create++;
        }

        return $create;
    }

    public function avvia(ManualePagina $pagina, string $titolo, ?Utente $utente): ManualeEsecuzione
    {
        $passi = $pagina->elencoPassi();

        if ($passi === []) {
            throw new \InvalidArgumentException('La pagina non ha passi da seguire.');
        }

        return DB::transaction(function () use ($pagina, $titolo, $utente, $passi): ManualeEsecuzione {
            $esecuzione = ManualeEsecuzione::create([
                'associazione_id' => $pagina->associazione_id,
                'pagina_id' => $pagina->id,
                'titolo' => $titolo,
                'avviata_da' => $utente?->id,
            ]);

            foreach ($passi as $indice => $testo) {
                ManualeEsecuzionePasso::create([
                    'associazione_id' => $pagina->associazione_id,
                    'esecuzione_id' => $esecuzione->id,
                    'indice' => $indice,
                    'testo' => mb_substr($testo, 0, 500),
                ]);
            }

            return $esecuzione;
        });
    }

    /**
     * Spunta o toglie la spunta a un passo; la checklist è completata
     * quando lo sono tutti i passi.
     */
    public function aggiornaPasso(ManualeEsecuzionePasso $passo, bool $completato, ?string $note, ?Utente $utente): void
    {
        DB::transaction(function () use ($passo, $completato, $note, $utente): void {
            $passo->update([
                'completato_at' => $completato ? ($passo->completato_at ?? now()) : null,
                'completato_da' => $completato ? ($passo->completato_da ?? $utente?->id) : null,
                'note' => filled($note) ? trim((string) $note) : null,
            ]);

            $esecuzione = ManualeEsecuzione::query()->whereKey($passo->esecuzione_id)->lockForUpdate()->firstOrFail();
            $aperti = ManualeEsecuzionePasso::query()->where('esecuzione_id', $esecuzione->id)->whereNull('completato_at')->exists();

            $esecuzione->update(['completata_at' => $aperti ? null : ($esecuzione->completata_at ?? now())]);
        });
    }
}
