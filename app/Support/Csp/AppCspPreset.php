<?php

namespace App\Support\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;
use Spatie\Csp\Scheme;

/**
 * Policy CSP dell'app (replica della CSP legacy, miglioramento 5):
 * asset Bootstrap locali + CDN jsdelivr/cdnjs, stili e script inline
 * (Bootstrap/GSAP/TinyMCE/GrapesJS richiedono unsafe-inline).
 */
class AppCspPreset implements Preset
{
    public function configure(Policy $policy): void
    {
        $cdns = [
            'https://cdn.jsdelivr.net',
            'https://cdnjs.cloudflare.com',
        ];

        $policy
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::SELF)
            ->add(Directive::OBJECT, Keyword::NONE)
            ->add(Directive::CONNECT, Keyword::SELF)
            ->add(Directive::WORKER, Keyword::SELF)
            ->add(Directive::IMG, [Keyword::SELF, Scheme::DATA])
            ->add(Directive::FONT, [Keyword::SELF, Scheme::DATA, ...$cdns])
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE, ...$cdns])
            ->add(Directive::STYLE_ELEM, [Keyword::SELF, Keyword::UNSAFE_INLINE, ...$cdns])
            ->add(Directive::SCRIPT, [Keyword::SELF, Keyword::UNSAFE_INLINE, ...$cdns]);
    }
}
