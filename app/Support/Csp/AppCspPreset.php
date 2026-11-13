<?php

namespace App\Support\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;
use Spatie\Csp\Scheme;

/**
 * Policy CSP dell'app.
 *
 * Tutti gli asset (Bootstrap, Chart.js, GrapesJS, html5-qrcode…) sono
 * vendorizzati in public/assets/vendor: nessun CDN esterno è ammesso per gli
 * script (eccezione stile/font per le icone di GrapesJS, vedi ICONE_GRAPESJS).
 *
 * script-src: 'self' + nonce della richiesta (ogni <script> inline delle view
 * porta @cspNonce, gli handler on* sono stati sostituiti da attributi data-*
 * gestiti in assets/js/azioni-dichiarative.js). Con CSP_NONCE_ENABLED=false si
 * ricade su 'unsafe-inline' come nelle versioni precedenti.
 *
 * style-src resta con 'unsafe-inline': le view usano molti attributi style=""
 * (e Bootstrap/Chart.js/GrapesJS ne scrivono a runtime), che un nonce non può
 * coprire; il rischio residuo è l'iniezione di CSS, non di codice.
 */
class AppCspPreset implements Preset
{
    /**
     * Unica eccezione esterna: l'editor GrapesJS (email e comunicazioni) carica
     * da sé le icone Font Awesome 4.7 da cdnjs. Ammesse solo come stile e font,
     * solo da quel percorso: mai come script.
     */
    private const ICONE_GRAPESJS = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/';

    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::SELF)
            ->add(Directive::OBJECT, Keyword::NONE)
            ->add(Directive::CONNECT, Keyword::SELF)
            ->add(Directive::WORKER, Keyword::SELF)
            ->add(Directive::IMG, [Keyword::SELF, Scheme::DATA])
            ->add(Directive::FONT, [Keyword::SELF, Scheme::DATA, self::ICONE_GRAPESJS])
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE, self::ICONE_GRAPESJS])
            ->add(Directive::STYLE_ELEM, [Keyword::SELF, Keyword::UNSAFE_INLINE, self::ICONE_GRAPESJS])
            ->add(Directive::SCRIPT, [Keyword::SELF, CspNonce::sorgenteScript()]);
    }
}
