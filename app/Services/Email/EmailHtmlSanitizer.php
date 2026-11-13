<?php

namespace App\Services\Email;

use Mews\Purifier\Facades\Purifier;

/**
 * Sanitizzazione centralizzata dell'HTML dei template email e degli annunci (miglioramento 7).
 *
 * L'output di GrapesJS è "getHtml() + <style>getCss()</style>": HTMLPurifier
 * rimuoverebbe il blocco <style>, quindi il foglio di stile viene estratto
 * prima della pulizia, ricostruito regola per regola con una whitelist
 * (selettori semplici, @media, proprietà di layout e tipografia, valori
 * senza url(), @import, expression(), escape o binding) e riattaccato dopo.
 * Le regole o le dichiarazioni che non passano vengono scartate in silenzio:
 * il resto dell'impaginazione resta intatto.
 */
class EmailHtmlSanitizer
{
    /**
     * @var array<string, mixed> Config HTMLPurifier orientata all'HTML email
     */
    private const CONFIG = [
        'HTML.Allowed' => 'div[style|class|id|align],p[style|class|align],span[style|class],a[href|style|class|target],img[src|alt|style|class|width|height|align],h1[style|class],h2[style|class],h3[style|class],h4[style|class],h5[style|class],h6[style|class],table[style|class|width|cellpadding|cellspacing|border|align|bgcolor],thead,tbody,tfoot,tr[style|class|align|bgcolor],td[style|class|width|height|colspan|rowspan|align|valign|bgcolor],th[style|class|width|colspan|rowspan|align|valign],ul[style|class],ol[style|class],li[style|class],strong,b,em,i,u,s,br,hr[style|class],blockquote[style|class],pre[style|class],code,small,sub,sup',
        'CSS.AllowTricky' => true,
        'Attr.AllowedFrameTargets' => ['_blank', '_self'],
        'AutoFormat.RemoveEmpty' => false,
    ];

    /**
     * Proprietà CSS ammesse nei blocchi <style>: layout, box model, colori e
     * tipografia, cioè quello che producono GrapesJS e il preset newsletter.
     * Escluse di proposito: content (testo iniettato con ::before), position
     * con valori diversi da static/relative, z-index, behavior, -moz-binding,
     * filter, cursor/pointer-events e tutto ciò che carica risorse esterne.
     */
    private const PROPRIETA = [
        'color', 'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-collapse', 'border-spacing',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
        'display', 'float', 'clear', 'vertical-align', 'box-sizing', 'box-shadow', 'opacity', 'visibility',
        'overflow', 'overflow-x', 'overflow-y', 'position',
        'table-layout', 'caption-side', 'empty-cells',
        'text-align', 'text-decoration', 'text-transform', 'text-indent', 'text-shadow', 'text-overflow',
        'letter-spacing', 'word-spacing', 'word-break', 'word-wrap', 'overflow-wrap', 'white-space', 'direction',
        'line-height', 'font', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'font-smoothing',
        'list-style', 'list-style-type', 'list-style-position',
        'outline', 'outline-color', 'outline-style', 'outline-width',
        'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
        'align-items', 'align-self', 'align-content', 'justify-content', 'gap', 'row-gap', 'column-gap', 'order',
    ];

    /** Funzioni CSS ammesse nei valori (niente url(), expression(), image-set(), var()...). */
    private const FUNZIONI = ['rgb', 'rgba', 'hsl', 'hsla', 'calc', 'linear-gradient', 'radial-gradient'];

    /**
     * Sanitizza l'HTML preservando l'eventuale blocco <style> di GrapesJS.
     */
    public function clean(string $html): string
    {
        [$body, $styles] = $this->extractStyleBlocks($html);

        $clean = Purifier::clean($body, self::CONFIG);

        return $clean.$styles;
    }

    /**
     * Ricostruisce un foglio di stile tenendo solo regole e dichiarazioni sicure.
     */
    public function sanitizeCss(string $css): string
    {
        // Commenti e delimitatori HTML legacy fuori: possono nascondere token
        $css = (string) preg_replace('#/\*.*?(\*/|$)#s', '', $css);
        $css = str_replace(['<!--', '-->'], '', $css);

        return $this->regole($css, false);
    }

    /**
     * Separa i blocchi <style>...</style> dal resto dell'HTML.
     *
     * @return array{0: string, 1: string}
     */
    private function extractStyleBlocks(string $html): array
    {
        $styles = '';

        $body = (string) preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            function (array $m) use (&$styles): string {
                $css = $this->sanitizeCss($m[1]);
                if ($css !== '') {
                    $styles .= '<style>'.$css.'</style>';
                }

                return '';
            },
            $html
        );

        return [$body, $styles];
    }

    /**
     * Elenco di regole "selettore { dichiarazioni }" e, al primo livello,
     * blocchi @media. Ogni altra at-rule (@import, @font-face, @charset,
     *
     * @namespace, @keyframes...) viene scartata con tutto il suo contenuto.
     */
    private function regole(string $css, bool $dentroMedia): string
    {
        $out = '';
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            $apertura = strpos($css, '{', $i);
            $puntoVirgola = strpos($css, ';', $i);

            // At-rule senza blocco (@import url(...); @charset ...;): salta fino al ';'
            $preambolo = ltrim(substr($css, $i, ($apertura === false ? $len : $apertura) - $i));
            if (str_starts_with($preambolo, '@') && $puntoVirgola !== false && ($apertura === false || $puntoVirgola < $apertura)) {
                $i = $puntoVirgola + 1;

                continue;
            }

            if ($apertura === false) {
                break;
            }

            $chiusura = $this->graffaDiChiusura($css, $apertura);
            $prelude = trim(substr($css, $i, $apertura - $i));
            $corpo = substr($css, $apertura + 1, ($chiusura ?? $len) - $apertura - 1);
            $i = $chiusura === null ? $len : $chiusura + 1;

            if ($prelude === '') {
                continue;
            }

            if ($prelude[0] === '@') {
                if (! $dentroMedia && preg_match('/^@media[a-zA-Z0-9\s(),:.\-]*$/', $prelude) === 1) {
                    $interno = $this->regole($corpo, true);
                    if ($interno !== '') {
                        $out .= $this->spazi($prelude).'{'.$interno.'}';
                    }
                }

                continue;
            }

            if (! $this->selettoreValido($prelude) || str_contains($corpo, '{')) {
                continue;
            }

            $dichiarazioni = $this->dichiarazioni($corpo);
            if ($dichiarazioni !== '') {
                $out .= $this->spazi($prelude).'{'.$dichiarazioni.'}';
            }
        }

        return $out;
    }

    /**
     * Posizione della graffa che chiude quella aperta in $apertura (blocchi annidati inclusi).
     */
    private function graffaDiChiusura(string $css, int $apertura): ?int
    {
        $profondita = 0;
        $len = strlen($css);

        for ($j = $apertura; $j < $len; $j++) {
            if ($css[$j] === '{') {
                $profondita++;
            } elseif ($css[$j] === '}') {
                $profondita--;
                if ($profondita === 0) {
                    return $j;
                }
            }
        }

        return null;
    }

    /**
     * Selettori semplici: tag, classi, id, pseudo-classi, combinatori e
     * attributi. Niente escape (\), tag HTML, at-rule o graffe.
     */
    private function selettoreValido(string $selettore): bool
    {
        return strlen($selettore) <= 1000
            && preg_match('/^[a-zA-Z0-9_\-\s#.,:*>+~()\[\]="\'^$|]+$/', $selettore) === 1;
    }

    private function dichiarazioni(string $corpo): string
    {
        $valide = [];

        foreach (explode(';', $corpo) as $dichiarazione) {
            $duePunti = strpos($dichiarazione, ':');
            if ($duePunti === false) {
                continue;
            }

            $proprieta = strtolower(trim(substr($dichiarazione, 0, $duePunti)));
            $valore = trim(substr($dichiarazione, $duePunti + 1));

            $importante = false;
            if (preg_match('/^(.*?)\s*!\s*important$/is', $valore, $m) === 1) {
                $importante = true;
                $valore = trim($m[1]);
            }

            if (! $this->proprietaAmmessa($proprieta) || ! $this->valoreAmmesso($proprieta, $valore)) {
                continue;
            }

            $valide[] = $proprieta.':'.$this->spazi($valore).($importante ? ' !important' : '');
        }

        return implode(';', $valide);
    }

    private function proprietaAmmessa(string $proprieta): bool
    {
        if (in_array($proprieta, self::PROPRIETA, true)) {
            return true;
        }

        // Prefissi dei motori (-webkit-font-smoothing, -ms-text-size-adjust...) solo
        // per proprietà già ammesse; le proprietà di Outlook (mso-*) sono innocue
        if (preg_match('/^-(webkit|moz|ms)-([a-z-]+)$/', $proprieta, $m) === 1) {
            return in_array($m[2], self::PROPRIETA, true) || in_array($m[2], ['text-size-adjust'], true);
        }

        return preg_match('/^mso-[a-z-]+$/', $proprieta) === 1;
    }

    private function valoreAmmesso(string $proprieta, string $valore): bool
    {
        if ($valore === '' || strlen($valore) > 500) {
            return false;
        }

        // Caratteri ammessi: niente \ (escape che nasconderebbero url/expression),
        // niente ':' (javascript:), '<', '@', ';', graffe
        if (preg_match('/^[a-zA-Z0-9\s#%.,()\-+\/\'"*]+$/', $valore) !== 1) {
            return false;
        }

        preg_match_all('/([a-zA-Z_-]+)\s*\(/', $valore, $funzioni);
        foreach ($funzioni[1] as $funzione) {
            if (! in_array(strtolower($funzione), self::FUNZIONI, true)) {
                return false;
            }
        }

        // Parentesi spaiate o "(" senza funzione davanti: valore sospetto
        if (substr_count($valore, '(') !== substr_count($valore, ')') || substr_count($valore, '(') !== count($funzioni[1])) {
            return false;
        }

        if ($proprieta === 'position') {
            return in_array(strtolower($valore), ['static', 'relative'], true);
        }

        return true;
    }

    private function spazi(string $testo): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($testo));
    }
}
