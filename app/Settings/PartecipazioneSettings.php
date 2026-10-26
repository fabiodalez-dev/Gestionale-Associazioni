<?php

namespace App\Settings;

use App\Models\RubricaScheda;

/**
 * Partecipazione dei soci alla vita associativa: proposte con raccolta di
 * adesioni (issue #10), raccolta annuale delle dichiarazioni sui conflitti
 * di interesse di chi ricopre una carica (issue #11), regolamento dello
 * spazio di discussione tra soci (issue #13) e rubrica interna (issue #14).
 */
class PartecipazioneSettings extends ImpostazioniAssociazione
{
    /** Adesioni necessarie perché una proposta vada esaminata. */
    public int $proposte_soglia;

    /** Se vero la soglia è una percentuale dei soci attivi. */
    public bool $proposte_soglia_percentuale;

    /** Se vero le proposte restano in revisione finché la segreteria non le pubblica. */
    public bool $proposte_moderazione;

    /** Anno della raccolta delle dichiarazioni in corso (promemoria mensili), null se ferma. */
    public ?int $dichiarazioni_anno_campagna;

    /** Regolamento delle discussioni, da accettare al primo accesso. */
    public string $discussioni_regolamento;

    /** Rubrica interna dei soci disponibile nell'area riservata. */
    public bool $rubrica_attiva;

    /** Se vero vede la rubrica solo chi vi compare a sua volta. */
    public bool $rubrica_reciprocita;

    /** Campi che i soci possono scegliere di mostrare (sottoinsieme di RubricaScheda::CAMPI). */
    public array $rubrica_campi;

    public const REGOLAMENTO_PREDEFINITO = "Le discussioni sono uno spazio dei soci per confrontarsi sulla vita dell'associazione.\n\n"
        ."- Scrivi con rispetto: niente insulti, attacchi personali o linguaggio offensivo.\n"
        ."- Resta in tema con lo spazio in cui scrivi.\n"
        ."- Non pubblicare dati personali di altri (telefoni, indirizzi, email) né pubblicità.\n"
        ."- Quello che scrivi è visibile agli altri soci che hanno accesso allo spazio.\n"
        .'- La segreteria può nascondere i messaggi che non rispettano queste regole e sospendere chi le viola.';

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
            'dichiarazioni_anno_campagna' => filled($valori['dichiarazioni_anno_campagna'] ?? null) ? (int) $valori['dichiarazioni_anno_campagna'] : null,
            'discussioni_regolamento' => filled($valori['discussioni_regolamento'] ?? null) ? (string) $valori['discussioni_regolamento'] : self::REGOLAMENTO_PREDEFINITO,
            'rubrica_attiva' => filter_var($valori['rubrica_attiva'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'rubrica_reciprocita' => filter_var($valori['rubrica_reciprocita'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'rubrica_campi' => is_array($valori['rubrica_campi'] ?? null)
                ? array_values(array_intersect(array_keys(RubricaScheda::CAMPI), $valori['rubrica_campi']))
                : array_keys(RubricaScheda::CAMPI),
        ];
    }
}
