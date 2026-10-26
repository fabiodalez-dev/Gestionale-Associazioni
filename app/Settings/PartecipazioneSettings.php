<?php

namespace App\Settings;

/**
 * Partecipazione dei soci alla vita associativa: proposte con raccolta di
 * adesioni (issue #10).
 */
class PartecipazioneSettings extends ImpostazioniAssociazione
{
    /** Adesioni necessarie perché una proposta vada esaminata. */
    public int $proposte_soglia;

    /** Se vero la soglia è una percentuale dei soci attivi. */
    public bool $proposte_soglia_percentuale;

    /** Se vero le proposte restano in revisione finché la segreteria non le pubblica. */
    public bool $proposte_moderazione;

    public static function group(): string
    {
        return 'partecipazione';
    }

    /** Nessuna colonna corrispondente su `associazioni`. */
    public static function colonne(): array
    {
        return [];
    }

    protected static function normalizza(array $valori): array
    {
        return [
            'proposte_soglia' => max(1, (int) ($valori['proposte_soglia'] ?? 10)),
            'proposte_soglia_percentuale' => filter_var($valori['proposte_soglia_percentuale'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'proposte_moderazione' => filter_var($valori['proposte_moderazione'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
