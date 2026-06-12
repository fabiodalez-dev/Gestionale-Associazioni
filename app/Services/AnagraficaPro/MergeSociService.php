<?php

namespace App\Services\AnagraficaPro;

use App\Models\Account;
use App\Models\DocumentoSocio;
use App\Models\EventoPartecipante;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\Soci\SocioActivityLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merge assistito dei soci duplicati (f.24 PIANO-VITA-SOCIALE):
 * sposta sul socio principale tessere, quote, prenotazioni (via account
 * collegato e partecipazioni eventi), documenti e storico del duplicato,
 * completa i campi anagrafici mancanti e soft-elimina il duplicato.
 */
class MergeSociService
{
    /** Campi anagrafici copiati dal duplicato se vuoti sul principale. */
    private const CAMPI_COMPLETABILI = [
        'email', 'telefono', 'codice_fiscale', 'data_nascita',
        'indirizzo', 'citta', 'provincia', 'cap',
    ];

    /**
     * Campi con unique per tenant su soci: vanno prima liberati sul
     * duplicato (anche da soft-deleted la riga resta nel vincolo unique).
     */
    private const CAMPI_UNIQUE = ['email', 'codice_fiscale'];

    public function __construct(private readonly SocioActivityLogger $activityLogger) {}

    /**
     * @return array{tessere: int, quote: int, documenti: int, storico: int, partecipazioni: int, account_spostato: bool, avvisi: list<string>}
     */
    public function merge(Socio $principale, Socio $duplicato, ?Utente $autore = null): array
    {
        if ($principale->id === $duplicato->id) {
            throw new InvalidArgumentException('Il socio principale e il duplicato coincidono.');
        }

        if ($principale->associazione_id !== $duplicato->associazione_id) {
            throw new InvalidArgumentException('I soci appartengono ad associazioni diverse.');
        }

        return DB::transaction(function () use ($principale, $duplicato): array {
            $associazioneId = $principale->associazione_id;
            $avvisi = [];

            $tessere = Tessera::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('socio_id', $duplicato->id)
                ->update(['socio_id' => $principale->id]);

            $quote = Quota::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('socio_id', $duplicato->id)
                ->update(['socio_id' => $principale->id]);

            // Documenti del fascicolo (inclusi quelli nel cestino soft delete)
            $documenti = DocumentoSocio::allTenants()
                ->withTrashed()
                ->where('associazione_id', $associazioneId)
                ->where('socio_id', $duplicato->id)
                ->update(['socio_id' => $principale->id]);

            $storico = StoricoAttivitaSocio::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('socio_id', $duplicato->id)
                ->update(['socio_id' => $principale->id]);

            // Partecipazioni eventi: unique (evento_id, socio_id) — si spostano
            // solo quelle senza conflitto col principale
            $eventiPrincipale = EventoPartecipante::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('socio_id', $principale->id)
                ->pluck('evento_id');

            $partecipazioni = EventoPartecipante::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('socio_id', $duplicato->id)
                ->whereNotIn('evento_id', $eventiPrincipale)
                ->update(['socio_id' => $principale->id]);

            $conflittiPartecipazioni = EventoPartecipante::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('socio_id', $duplicato->id)
                ->count();

            if ($conflittiPartecipazioni > 0) {
                $avvisi[] = "{$conflittiPartecipazioni} partecipazioni a eventi non spostate (il principale risultava già partecipante).";
            }

            // Prenotazioni: viaggiano con l'account pubblico collegato al socio
            // (accounts.socio_id unique) — si re-collega l'account del duplicato
            // se il principale non ne ha già uno.
            $accountSpostato = $this->spostaAccount($associazioneId, $principale, $duplicato, $avvisi);

            // Completa i campi anagrafici vuoti del principale con quelli del duplicato
            $this->completaCampiMancanti($principale, $duplicato);

            // Traccia l'unione nello storico del principale
            $this->activityLogger->log(
                $principale,
                'Unione duplicati',
                "Unito il duplicato {$duplicato->nome_completo} (n. {$duplicato->numero_socio}) in questo socio.",
                [
                    'duplicato_id' => $duplicato->id,
                    'duplicato_numero_socio' => $duplicato->numero_socio,
                    'tessere' => $tessere,
                    'quote' => $quote,
                    'documenti' => $documenti,
                    'storico' => $storico,
                    'partecipazioni' => $partecipazioni,
                ]
            );

            // Soft delete del duplicato (recuperabile dal cestino soci)
            $duplicato->delete();

            return [
                'tessere' => $tessere,
                'quote' => $quote,
                'documenti' => $documenti,
                'storico' => $storico,
                'partecipazioni' => $partecipazioni,
                'account_spostato' => $accountSpostato,
                'avvisi' => $avvisi,
            ];
        });
    }

    /**
     * @param  list<string>  $avvisi
     */
    private function spostaAccount(string $associazioneId, Socio $principale, Socio $duplicato, array &$avvisi): bool
    {
        $accountDuplicato = Account::allTenants()
            ->where('associazione_id', $associazioneId)
            ->where('socio_id', $duplicato->id)
            ->first();

        if ($accountDuplicato === null) {
            return false;
        }

        $principaleHaAccount = Account::allTenants()
            ->where('associazione_id', $associazioneId)
            ->where('socio_id', $principale->id)
            ->exists();

        if ($principaleHaAccount) {
            // Entrambi collegati: il duplicato viene scollegato (le sue
            // prenotazioni restano sull'account, ora non più legato a un socio)
            $accountDuplicato->update(['socio_id' => null]);
            $avvisi[] = "L'account pubblico del duplicato ({$accountDuplicato->email}) è stato scollegato: il principale ha già un account.";

            return false;
        }

        $accountDuplicato->update(['socio_id' => $principale->id]);

        return true;
    }

    private function completaCampiMancanti(Socio $principale, Socio $duplicato): void
    {
        $modificato = false;
        $duplicatoModificato = false;

        foreach (self::CAMPI_COMPLETABILI as $campo) {
            if (blank($principale->{$campo}) && filled($duplicato->{$campo})) {
                $principale->{$campo} = $duplicato->{$campo};
                $modificato = true;

                // Il valore unique va liberato sul duplicato prima del save
                // del principale: il vincolo unique vale anche per le righe
                // soft-deleted. La colonna email è NOT NULL a schema, quindi
                // si usa un placeholder univoco (basato sull'id del duplicato);
                // il CF nullable viene normalizzato a NULL.
                if (in_array($campo, self::CAMPI_UNIQUE, true)) {
                    $duplicato->{$campo} = $campo === 'email'
                        ? 'merged-'.$duplicato->id.'@example.invalid'
                        : null;
                    $duplicatoModificato = true;
                }
            }
        }

        if ($duplicatoModificato) {
            $duplicato->saveQuietly();
        }

        if ($modificato) {
            $principale->save();
        }
    }
}
