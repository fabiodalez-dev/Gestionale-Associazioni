<?php

namespace App\Services\Privacy;

use App\Models\Account;
use App\Models\DocumentoSocio;
use App\Models\Prenotazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Raccolta dei dati personali di un socio o di un account (diritto di accesso
 * e portabilità, artt. 15 e 20 GDPR), usata dall'archivio ZIP
 * (spatie/laravel-personal-data-export) e dall'export JSON immediato.
 *
 * Ogni sezione è un elenco di righe con gli attributi dei modelli, senza
 * segreti (password, token), chiavi tecniche e contenuti pesanti (HTML delle
 * email). Le note interne della segreteria (soci.note e note_soci) sono
 * incluse nella sezione note_interne: sono dati che riguardano il socio
 * (art. 15 GDPR), con data e autore della nota.
 *
 * Le relazioni sono filtrate per socio_id/account_id, quindi il risultato non
 * dipende dallo scope di tenant (il job gira in coda senza sessione).
 */
class DatiPersonali
{
    /** Attributi mai esportati, oltre agli $hidden dei modelli. */
    private const ESCLUSI = [
        'associazione_id', 'socio_id', 'account_id', 'note', 'percorso_file', 'body_html',
        'remember_token', 'deleted_at', 'qr_code_url',
        'origine_type', 'origine_id', 'batch_id', 'queue_id',
    ];

    /**
     * @return array<string, mixed> sezione => dati
     */
    public function socio(Socio $socio): array
    {
        $socio->loadMissing(['associazione', 'sede', 'tipoSocio', 'categoriaSocio', 'nucleo']);

        $sezioni = [
            'esportato_at' => now()->toIso8601String(),
            'associazione' => $socio->associazione?->nome,
            'anagrafica' => $this->riga($socio, [
                'sede' => $socio->sede?->nome,
                'tipo_socio' => $socio->tipoSocio?->nome,
                'categoria' => $socio->categoriaSocio?->nome,
                'nucleo_familiare' => $socio->nucleo?->nome,
            ]),
            'campi_personalizzati' => $socio->valoriCampiPersonalizzati()->with('campo')->get()
                ->map(fn (Model $v) => ['campo' => $v->campo?->nome_campo, 'valore' => $v->valore])->all(),
            'tessere' => $this->righe($socio->tessere()->orderBy('data_emissione')->get()),
            'quote' => $this->righe($socio->quote()->orderBy('anno')->get()),
            'documenti' => $socio->documenti()->orderBy('created_at')->get()
                ->map(fn (DocumentoSocio $d) => $this->riga($d, ['file_nell_archivio' => $this->percorsoDocumento($d)]))->all(),
            'eventi' => $socio->partecipazioni()->with(['evento' => fn ($q) => $q->withoutGlobalScopes()])->get()
                ->map(fn (Model $p) => $this->riga($p, ['evento' => $p->evento?->titolo, 'data_evento' => $p->evento?->data_evento?->toIso8601String()]))->all(),
            'cariche' => $this->righe($socio->cariche()->orderBy('inizio')->get()),
            'mansioni' => $socio->mansioni()->pluck('nome')->all(),
            'turni_volontariato' => $this->righe($socio->turniAssegnazioni()->get()),
            'prestiti_attrezzature' => $this->righe($socio->prestitiAttrezzature()->get()),
            'cessazioni' => $this->righe($socio->cessazioni()->get()),
            'richieste_modifica_dati' => $this->righe($socio->richiesteModificaDati()->get()),
            'storico_attivita' => $this->righe($socio->storicoAttivita()->orderBy('data_attivita')->get()),
            'note_interne' => collect(filled($socio->note) ? [['tipo' => 'Nota in anagrafica', 'testo' => $socio->note, 'autore' => null, 'scritta_il' => null]] : [])
                ->merge($socio->noteInterne()->withoutGlobalScopes()->with('autore:id,nome,cognome')->orderBy('created_at')->get()
                    ->map(fn (Model $n) => [
                        'tipo' => 'Nota della segreteria',
                        'testo' => $n->getAttribute('testo'),
                        'autore' => $n->getRelation('autore')?->nome_completo,
                        'scritta_il' => $n->getAttribute('created_at')?->toIso8601String(),
                    ]))
                ->values()->all(),
            'consensi' => $socio->consensi()->withoutGlobalScopes()->orderBy('created_at')->get()
                ->map(fn (Model $c) => $c->only(['testo', 'accettato', 'origine', 'created_at']))->all(),
            'email_ricevute' => $socio->emailLogs()->orderBy('sent_at')->get()
                ->map(fn (Model $e) => $e->only(['to_email', 'subject', 'status', 'sent_at']))->all(),
            'account_collegati' => Account::query()->withoutGlobalScopes()->where('socio_id', $socio->id)->get()
                ->map(fn (Account $a) => $this->account($a, conIntestazione: false))->all(),
        ];

        return $sezioni;
    }

    /**
     * @return array<string, mixed>
     */
    public function account(Account $account, bool $conIntestazione = true): array
    {
        $dati = [
            'profilo' => $this->riga($account),
            'prenotazioni' => $account->prenotazioni()
                ->withoutGlobalScopes()
                ->with(['evento' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderBy('created_at')
                ->get()
                ->map(fn (Prenotazione $p) => $this->riga($p, [
                    'evento' => $p->evento?->titolo,
                    'data_evento' => $p->evento?->data_evento?->toIso8601String(),
                    'luogo' => $p->evento?->luogo,
                ], ['qr_token', 'gateway_transaction_id', 'checkin_utente_id', 'approvata_da']))
                ->all(),
            'richieste_iscrizione' => $this->righe(
                RichiestaIscrizione::query()->withoutGlobalScopes()->where('account_id', $account->id)->get(),
                ['gestita_da'],
            ),
        ];

        if (! $conIntestazione) {
            return $dati;
        }

        return [
            'esportato_at' => now()->toIso8601String(),
            'associazione' => $account->associazione?->nome,
        ] + $dati;
    }

    /** Percorso del documento caricato dentro l'archivio ZIP. */
    public function percorsoDocumento(DocumentoSocio $documento): string
    {
        $nome = preg_replace('/[^\w.\- ]+/u', '_', (string) $documento->nome_file) ?: 'documento';

        return 'documenti/'.$documento->id.'-'.$nome;
    }

    /**
     * @param  Collection<int, covariant Model>  $modelli
     * @param  list<string>  $esclusi
     * @return list<array<string, mixed>>
     */
    private function righe(Collection $modelli, array $esclusi = []): array
    {
        return $modelli->map(fn (Model $m) => $this->riga($m, [], $esclusi))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $esclusi
     * @return array<string, mixed>
     */
    private function riga(Model $modello, array $extra = [], array $esclusi = []): array
    {
        $attributi = array_filter(
            $modello->attributesToArray(),
            fn (string $chiave) => ! in_array($chiave, [...self::ESCLUSI, ...$esclusi], true)
                && ! str_contains($chiave, 'token')
                && ! str_contains($chiave, 'password')
                && ! str_ends_with($chiave, '_hash'),
            ARRAY_FILTER_USE_KEY,
        );

        return $attributi + $extra;
    }
}
