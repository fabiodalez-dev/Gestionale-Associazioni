{{-- Corpo HTML della newsletter da annunci (f.8): markup email-safe,
     avvolto nel layout brandizzato (EmailLayout) prima dell'invio.
     I corpi degli annunci sono già purificati al salvataggio. --}}
<h1 style="font-size:22px; margin:0 0 16px;">{{ $titolo }}</h1>

@foreach ($annunci as $annuncio)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
        <tr>
            <td style="border-bottom:1px solid #e9e9e9; padding-bottom:16px;">
                <h2 style="font-size:18px; margin:0 0 8px;">{{ $annuncio->titolo }}</h2>
                @if ($annuncio->pubblicato_dal)
                    <p style="font-size:12px; color:#888888; margin:0 0 8px;">{{ $annuncio->pubblicato_dal->format('d/m/Y') }}</p>
                @endif
                <div style="font-size:14px; line-height:1.6;">{!! $annuncio->corpo_html !!}</div>
            </td>
        </tr>
    </table>
@endforeach
