<?php

namespace App\Support;

use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Support\Str;

/**
 * Dati strutturati Schema.org (JSON-LD) del mini-sito pubblico (#40, #44):
 * solo dati già pubblici sulla pagina, nessun campo vuoto.
 */
final class SchemaOrg
{
    /** @return array<string, mixed> */
    public static function associazione(Associazione $associazione): array
    {
        $indirizzo = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $associazione->indirizzo,
            'postalCode' => $associazione->cap,
            'addressLocality' => $associazione->citta,
            'addressRegion' => $associazione->provincia,
            'addressCountry' => 'IT',
        ], fn ($v) => filled($v));

        return self::pulisci([
            '@context' => 'https://schema.org',
            '@type' => 'NGO',
            'name' => $associazione->nome,
            'url' => route('pubblico.home.show', $associazione->slug),
            'logo' => $associazione->moduli()->mostra_logo ? $associazione->logoUrl() : null,
            'description' => self::testo($associazione->descrizione_pubblica, 300),
            'email' => $associazione->email,
            'telephone' => $associazione->telefono,
            // Solo l'indirizzo con almeno la città: un paese da solo non dice nulla
            'address' => filled($associazione->citta) ? $indirizzo : null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function evento(Evento $evento, Associazione $associazione, ?int $postiDisponibili): array
    {
        $url = route('pubblico.eventi.show', [$associazione->slug, $evento->id]);
        $prezzo = (float) $evento->prezzo;

        return self::pulisci([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $evento->titolo,
            'url' => $url,
            'description' => self::testo($evento->descrizione, 500),
            'image' => filled($evento->immagine_url) ? [url((string) $evento->immagine_url)] : null,
            'startDate' => $evento->data_evento->toIso8601String(),
            'endDate' => $evento->data_fine?->toIso8601String(),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => filled($evento->luogo) ? [
                '@type' => 'Place',
                'name' => $evento->luogo,
                'address' => $evento->luogo,
            ] : null,
            'organizer' => [
                '@type' => 'Organization',
                'name' => $associazione->nome,
                'url' => route('pubblico.home.show', $associazione->slug),
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => $url,
                'price' => number_format($prezzo, 2, '.', ''),
                'priceCurrency' => 'EUR',
                'availability' => $postiDisponibili === 0
                    ? 'https://schema.org/SoldOut'
                    : 'https://schema.org/InStock',
            ],
        ]);
    }

    /**
     * Briciole di pane: [etichetta => url], dall'home alla pagina corrente.
     *
     * @param  array<string, string>  $voci
     * @return array<string, mixed>
     */
    public static function briciole(array $voci): array
    {
        $elementi = [];
        $posizione = 1;
        foreach ($voci as $nome => $url) {
            $elementi[] = ['@type' => 'ListItem', 'position' => $posizione++, 'name' => $nome, 'item' => $url];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $elementi];
    }

    /**
     * JSON per un tag <script type="application/ld+json">: niente "</script>"
     * né "<!--" possibili dentro i testi.
     *
     * @param  array<string, mixed>  $dati
     */
    public static function json(array $dati): string
    {
        return (string) json_encode($dati, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    private static function testo(?string $testo, int $max): ?string
    {
        $pulito = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $testo)));

        return $pulito === '' ? null : Str::limit($pulito, $max);
    }

    /**
     * @param  array<string, mixed>  $dati
     * @return array<string, mixed>
     */
    private static function pulisci(array $dati): array
    {
        return array_filter($dati, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
