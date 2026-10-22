<?php

namespace App\Settings;

use Illuminate\Support\Str;

/**
 * Aspetto e contenuto dei moduli pubblici dell'associazione (preiscrizione,
 * iscrizione online, informativa privacy, mini-sito): colore, logo, testi,
 * consensi aggiuntivi e campi mostrati nella preiscrizione.
 */
class ModuliSettings extends ImpostazioniAssociazione
{
    public const COLORE_DEFAULT = '#FF7B11';

    /** Campi anagrafici che si possono nascondere nella preiscrizione. */
    public const CAMPI_NASCONDIBILI = ['telefono', 'data_nascita', 'codice_fiscale', 'indirizzo', 'citta', 'provincia', 'cap'];

    public string $colore_primario;

    public bool $mostra_logo;

    public ?string $titolo_preiscrizione;

    /** HTML già purificato in scrittura. */
    public ?string $intro_preiscrizione_html;

    public ?string $messaggio_conferma;

    /** Consensi aggiuntivi: elenco di chiave, testo, obbligatorio, link_url. */
    public ?array $consensi;

    /** Campi anagrafici nascosti nella preiscrizione. */
    public ?array $campi_nascosti_preiscrizione;

    /** Id dei campi personalizzati mostrati nella preiscrizione (null = tutti). */
    public ?array $campi_personalizzati_preiscrizione;

    public static function group(): string
    {
        return 'moduli';
    }

    public static function colonne(): array
    {
        return [];
    }

    /**
     * @return list<array{chiave: string, testo: string, obbligatorio: bool, link_url: ?string}>
     */
    public function elencoConsensi(): array
    {
        return array_values($this->consensi ?? []);
    }

    public function campoVisibile(string $campo): bool
    {
        return ! in_array($campo, $this->campi_nascosti_preiscrizione ?? [], true);
    }

    /** Testo bianco o scuro, in base alla luminosità del colore. */
    public function coloreTesto(): string
    {
        [$r, $g, $b] = $this->rgb();
        $luminanza = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        return $luminanza > 0.6 ? '#1F2937' : '#FFFFFF';
    }

    /** "r, g, b" per le variabili --bs-*-rgb di Bootstrap. */
    public function coloreRgb(): string
    {
        return implode(', ', $this->rgb());
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(): array
    {
        $hex = ltrim($this->colore_primario, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    protected static function normalizza(array $valori): array
    {
        $colore = (string) ($valori['colore_primario'] ?? '');
        $testo = fn (string $k) => filled($valori[$k] ?? null) ? trim((string) $valori[$k]) : null;

        $consensi = [];
        foreach ((array) ($valori['consensi'] ?? []) as $consenso) {
            $testoConsenso = trim((string) ($consenso['testo'] ?? ''));
            if ($testoConsenso === '') {
                continue;
            }
            $chiave = Str::slug((string) ($consenso['chiave'] ?? '')) ?: Str::slug(Str::limit($testoConsenso, 40, ''));
            $link = trim((string) ($consenso['link_url'] ?? ''));
            $consensi[$chiave] = [
                'chiave' => $chiave,
                'testo' => Str::limit($testoConsenso, 500, ''),
                'obbligatorio' => filter_var($consenso['obbligatorio'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'link_url' => preg_match('~^https?://~i', $link) ? $link : null,
            ];
        }

        $nascosti = array_values(array_intersect(self::CAMPI_NASCONDIBILI, (array) ($valori['campi_nascosti_preiscrizione'] ?? [])));
        $personalizzati = $valori['campi_personalizzati_preiscrizione'] ?? null;

        return [
            'colore_primario' => preg_match('/^#[0-9a-fA-F]{6}$/', $colore) === 1 ? strtoupper($colore) : self::COLORE_DEFAULT,
            'mostra_logo' => filter_var($valori['mostra_logo'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'titolo_preiscrizione' => $testo('titolo_preiscrizione'),
            'intro_preiscrizione_html' => $testo('intro_preiscrizione_html'),
            'messaggio_conferma' => $testo('messaggio_conferma'),
            'consensi' => $consensi === [] ? null : array_values($consensi),
            'campi_nascosti_preiscrizione' => $nascosti === [] ? null : $nascosti,
            'campi_personalizzati_preiscrizione' => is_array($personalizzati) ? array_values(array_map('strval', $personalizzati)) : null,
        ];
    }
}
