<?php

namespace App\Services\Email;

use App\Models\Associazione;
use App\Models\Socio;

/**
 * Sistema di placeholder dei template email.
 *
 * Sintassi: {NOME_MAIUSCOLO} (regex \{([A-Z0-9_]+)\}).
 * I placeholder non presenti nella mappa restano invariati nel testo.
 * I valori vengono HTML-escaped alla costruzione della mappa (iniettati in HTML).
 * NON si usa Blade per i template utente (rischio injection): porting diretto
 * di renderTemplatePlaceholders del legacy.
 */
class EmailPlaceholders
{
    /** @var list<string> Placeholder comuni a tutti i template */
    public const COMUNI = [
        'NOME', 'COGNOME', 'EMAIL', 'NUMERO_SOCIO', 'DATA_ISCRIZIONE',
        'NOME_ASSOCIAZIONE', 'EMAIL_ASSOCIAZIONE', 'LOGO_ASSOCIAZIONE',
        'ANNO', 'DATA_CORRENTE', 'LINK_DISISCRIZIONE',
    ];

    /** @var array<string, list<string>> Placeholder extra per codice template */
    public const EXTRA = [
        'scadenza_tessera' => ['DATA_SCADENZA', 'NUMERO_TESSERA', 'LINK_RINNOVO', 'LINK_PAGAMENTO'],
        'rinnovo_tessera' => ['DATA_SCADENZA', 'NUMERO_TESSERA', 'LINK_RINNOVO', 'LINK_PAGAMENTO'],
        'nuova_preiscrizione' => ['TELEFONO', 'CODICE_FISCALE', 'LINK_ADMIN'],
        'scadenza_quota' => ['DATA_SCADENZA', 'IMPORTO', 'LINK_PAGAMENTO'],
        'pagamento_quota' => ['IMPORTO', 'DATA_PAGAMENTO'],
        'assemblea' => ['TITOLO_EVENTO', 'DATA_EVENTO', 'LUOGO_EVENTO'],
    ];

    /**
     * Elenco placeholder disponibili per un codice template.
     *
     * @return list<string>
     */
    public static function forCodice(?string $codice): array
    {
        return array_merge(self::COMUNI, self::EXTRA[$codice] ?? []);
    }

    /**
     * Sostituisce i placeholder {KEY} con i valori della mappa.
     * Le chiavi sconosciute restano invariate nel testo.
     *
     * @param  array<string, string>  $vars
     */
    public static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Z0-9_]+)\}/',
            fn (array $m) => array_key_exists($m[1], $vars) ? $vars[$m[1]] : $m[0],
            $template
        );
    }

    /**
     * Costruisce la mappa dei valori placeholder (tutti HTML-escaped).
     *
     * - sempre: ANNO, DATA_CORRENTE;
     * - dall'associazione: NOME_ASSOCIAZIONE, EMAIL_ASSOCIAZIONE, LOGO_ASSOCIAZIONE;
     * - dal socio (se fornito): NOME, COGNOME, EMAIL, NUMERO_SOCIO, DATA_ISCRIZIONE
     *   e LINK_DISISCRIZIONE (genera lazy il token opt-out se mancante);
     * - $extra sovrascrive/aggiunge chiavi (escaped a sua volta).
     *
     * @param  array<string, string|null>  $extra
     * @return array<string, string>
     */
    public static function buildValues(Associazione $associazione, ?Socio $socio = null, array $extra = []): array
    {
        $e = fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $vars = [
            'ANNO' => $e(now()->format('Y')),
            'DATA_CORRENTE' => $e(now()->format('d/m/Y')),
            'NOME_ASSOCIAZIONE' => $e($associazione->nome),
            'EMAIL_ASSOCIAZIONE' => $e($associazione->email),
            'LOGO_ASSOCIAZIONE' => $e($associazione->logo_url),
        ];

        if ($socio !== null) {
            $vars['NOME'] = $e($socio->nome);
            $vars['COGNOME'] = $e($socio->cognome);
            $vars['EMAIL'] = $e($socio->email);
            $vars['NUMERO_SOCIO'] = $e($socio->numero_socio);
            $vars['DATA_ISCRIZIONE'] = $e($socio->data_iscrizione ? \Illuminate\Support\Carbon::parse($socio->data_iscrizione)->format('d/m/Y') : '');
            $vars['LINK_DISISCRIZIONE'] = $e(self::unsubscribeUrl($socio));
        }

        foreach ($extra as $key => $value) {
            $vars[$key] = $e($value);
        }

        return $vars;
    }

    /**
     * URL pubblico di disiscrizione del socio; genera (lazy) e persiste il token se mancante.
     */
    public static function unsubscribeUrl(Socio $socio): string
    {
        if (empty($socio->email_opt_out_token)) {
            $socio->email_opt_out_token = bin2hex(random_bytes(32));
            $socio->saveQuietly();
        }

        return route('email.unsubscribe.show', [
            'socio' => $socio->id,
            'token' => $socio->email_opt_out_token,
        ]);
    }
}
