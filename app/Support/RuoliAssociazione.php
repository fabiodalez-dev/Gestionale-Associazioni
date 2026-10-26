<?php

namespace App\Support;

/**
 * Matrice ruoli × permessi dell'applicazione: unica fonte usata da RolesSeeder,
 * dalla pagina "Ruoli e permessi" e dai test.
 *
 * Permessi nel formato {modulo}.{azione} (spatie/laravel-permission, guard web).
 * Ruoli di sistema (colonna utenti.ruolo): super_admin, admin_associazione, operatore.
 * Ruoli associativi (cariche): assegnabili agli utenti staff dell'associazione,
 * da soli o insieme a "operatore"; i permessi si sommano.
 */
final class RuoliAssociazione
{
    public const AZIONI = [
        'view' => 'Vedere',
        'create' => 'Creare',
        'update' => 'Modificare',
        'delete' => 'Eliminare',
    ];

    public const MODULI = [
        'soci' => 'Soci e anagrafica',
        'iscrizioni' => 'Richieste di iscrizione',
        'tessere' => 'Tessere',
        'quote' => 'Quote associative',
        'economia' => 'Prima nota e donazioni',
        'eventi' => 'Eventi e prenotazioni',
        'volontari' => 'Volontari e turni',
        'spazi' => 'Spazi e inventario',
        'progetti' => 'Progetti',
        'riunioni' => 'Riunioni e decisioni',
        'proposte' => 'Proposte dei soci',
        'verbali' => 'Verbali',
        'documenti' => 'Documenti',
        'manuale' => 'Manuale operativo',
        'scadenze' => 'Scadenze',
        'email' => 'Email e SMTP',
        'comunicazione' => 'Annunci, newsletter e sondaggi',
        'relazioni' => 'Contatti esterni e sponsor',
        'struttura' => 'Configurazione dell\'associazione',
        'api' => 'Chiavi API',
    ];

    /** Ruoli assegnabili agli utenti staff dalla pagina "Ruoli e permessi" */
    public const RUOLI_STAFF = [
        'operatore' => ['label' => 'Operatore', 'descrizione' => 'Gestione operativa quotidiana: soci, tessere, quote, eventi, verbali e scadenze.'],
        'presidente' => ['label' => 'Presidente', 'descrizione' => 'Visione completa e gestione di tutte le attività, configurazione inclusa (senza chiavi API).'],
        'vicepresidente' => ['label' => 'Vicepresidente', 'descrizione' => 'Come il presidente, ma senza eliminare dati.'],
        'segretario' => ['label' => 'Segretario', 'descrizione' => 'Anagrafica, iscrizioni, tessere, verbali, riunioni, documenti e comunicazioni.'],
        'tesoriere' => ['label' => 'Tesoriere', 'descrizione' => 'Quote, prima nota, donazioni e rendiconti dei progetti; soci in sola lettura.'],
        'consigliere' => ['label' => 'Consigliere', 'descrizione' => 'Consultazione di tutte le attività dell\'associazione, senza modifiche.'],
        'responsabile_sede' => ['label' => 'Responsabile di sede', 'descrizione' => 'Soci, tessere, quote, eventi, volontari e spazi della propria sede; prima nota in inserimento. Va assegnato insieme alle sedi.'],
        'volontario' => ['label' => 'Volontario', 'descrizione' => 'Eventi, turni, spazi e documenti in sola lettura.'],
    ];

    /**
     * Permessi per modulo di ciascun ruolo: lettere v/c/u/d = view/create/update/delete.
     *
     * @return array<string, array<string, string>>
     */
    private static function matrice(): array
    {
        $tutto = array_fill_keys(array_keys(self::MODULI), 'vcud');
        $presidente = array_fill_keys(array_keys(self::MODULI), 'vcud');
        $presidente['soci'] = $presidente['tessere'] = $presidente['quote'] = $presidente['economia'] = 'vcu';
        $presidente['email'] = 'vcu';
        $presidente['struttura'] = 'vu';
        unset($presidente['api']);

        return [
            'super_admin' => $tutto,
            'admin_associazione' => $tutto,
            // Invariato rispetto alla versione precedente; i nuovi moduli ereditano
            // i permessi della famiglia che li autorizzava prima (economia da quote,
            // iscrizioni/volontari da soci, spazi da eventi, comunicazione da email).
            'operatore' => [
                'soci' => 'vcud', 'tessere' => 'vcud', 'quote' => 'vcud', 'eventi' => 'vcud',
                'verbali' => 'vcud', 'scadenze' => 'vcud', 'documenti' => 'vc', 'email' => 'v',
                'economia' => 'vcud', 'iscrizioni' => 'vcud', 'volontari' => 'vcud', 'spazi' => 'vcud',
                'comunicazione' => 'v', 'manuale' => 'v',
            ],
            'presidente' => $presidente,
            'vicepresidente' => array_map(fn (string $a) => str_replace('d', '', $a), $presidente),
            'segretario' => [
                'soci' => 'vcud', 'iscrizioni' => 'vcud', 'tessere' => 'vcud', 'quote' => 'v',
                'eventi' => 'vcu', 'volontari' => 'v', 'spazi' => 'v', 'progetti' => 'v',
                'riunioni' => 'vcud', 'proposte' => 'vcud', 'verbali' => 'vcud', 'documenti' => 'vcud', 'manuale' => 'vcud', 'scadenze' => 'vcud',
                'email' => 'vcu', 'comunicazione' => 'vcud', 'relazioni' => 'vcu', 'struttura' => 'v',
            ],
            'tesoriere' => [
                'soci' => 'v', 'iscrizioni' => 'v', 'tessere' => 'vu', 'quote' => 'vcud', 'economia' => 'vcud',
                'eventi' => 'v', 'progetti' => 'vu', 'riunioni' => 'v', 'verbali' => 'v',
                'documenti' => 'vc', 'manuale' => 'v', 'scadenze' => 'vcu', 'relazioni' => 'vcu',
            ],
            'consigliere' => array_fill_keys(
                ['soci', 'iscrizioni', 'tessere', 'quote', 'economia', 'eventi', 'volontari', 'spazi', 'progetti', 'riunioni', 'proposte', 'verbali', 'documenti', 'manuale', 'scadenze', 'comunicazione', 'relazioni'],
                'v',
            ),
            'responsabile_sede' => [
                'soci' => 'vcu', 'iscrizioni' => 'v', 'tessere' => 'vcu', 'quote' => 'vcu', 'economia' => 'vc',
                'eventi' => 'vcud', 'volontari' => 'vcud', 'spazi' => 'vcu', 'documenti' => 'vc',
                'manuale' => 'v', 'scadenze' => 'v', 'comunicazione' => 'v',
            ],
            'volontario' => ['eventi' => 'v', 'volontari' => 'v', 'spazi' => 'v', 'documenti' => 'v', 'manuale' => 'v'],
        ];
    }

    /**
     * Tutti i permessi dell'applicazione.
     *
     * @return list<string>
     */
    public static function tuttiIPermessi(): array
    {
        $permessi = [];
        foreach (array_keys(self::MODULI) as $modulo) {
            foreach (array_keys(self::AZIONI) as $azione) {
                $permessi[] = "{$modulo}.{$azione}";
            }
        }

        return $permessi;
    }

    /**
     * @return list<string>
     */
    public static function ruoli(): array
    {
        return array_keys(self::matrice());
    }

    /**
     * Permessi di un ruolo, es. ['soci.view', 'soci.create', …].
     *
     * @return list<string>
     */
    public static function permessi(string $ruolo): array
    {
        $lettere = ['v' => 'view', 'c' => 'create', 'u' => 'update', 'd' => 'delete'];
        $permessi = [];
        foreach (self::matrice()[$ruolo] ?? [] as $modulo => $azioni) {
            foreach (str_split($azioni) as $lettera) {
                $permessi[] = "{$modulo}.{$lettere[$lettera]}";
            }
        }

        return $permessi;
    }

    public static function label(string $ruolo): string
    {
        return self::RUOLI_STAFF[$ruolo]['label'] ?? match ($ruolo) {
            'super_admin' => 'Super admin',
            'admin_associazione' => 'Amministratore',
            default => ucfirst($ruolo),
        };
    }
}
