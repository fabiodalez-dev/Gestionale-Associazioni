<?php

namespace App\Services\Email;

use Mews\Purifier\Facades\Purifier;

/**
 * Sanitizzazione centralizzata dell'HTML dei template email (miglioramento 7).
 *
 * L'output di GrapesJS è "getHtml() + <style>getCss()</style>": HTMLPurifier
 * rimuoverebbe il blocco <style>, quindi il foglio di stile in coda viene
 * estratto prima della pulizia e riattaccato dopo (il CSS non è eseguibile
 * come script; gli url javascript: negli attributi vengono comunque rimossi
 * dal purifier).
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
     * Sanitizza l'HTML preservando l'eventuale blocco <style> di GrapesJS.
     */
    public function clean(string $html): string
    {
        [$body, $styles] = $this->extractStyleBlocks($html);

        $clean = Purifier::clean($body, self::CONFIG);

        return $clean.$styles;
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
                // Il contenuto CSS non deve poter chiudere il tag e iniettare markup
                $css = str_ireplace(['</style', '<script', 'expression(', 'javascript:'], '', $m[1]);
                $styles .= '<style>'.$css.'</style>';

                return '';
            },
            $html
        );

        return [$body, $styles];
    }
}
