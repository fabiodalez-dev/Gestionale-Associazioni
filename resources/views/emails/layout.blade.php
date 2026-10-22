{{--
    Layout email brandizzato per tenant (f.18 PIANO-VITA-SOCIALE):
    vista HTML condivisa applicata a TUTTE le notifiche MailMessage via
    TenantNotificationMailer::applica() — header con logo (fallback pulito
    senza logo) e nome associazione, colore brand, bottone azione,
    footer con © anno e link di disiscrizione quando c'è un socio.

    Variabili dal MailMessage (SimpleMessage::toArray): $greeting, $salutation,
    $introLines, $outroLines, $actionText, $actionUrl, $subject, $level.
    Variabili brand (viewData): $brandNome, $brandLogo, $brandColore,
    $unsubscribeUrl, $annoCorrente.
--}}
@php
    $brandNome = $brandNome ?? config('app.name');
    $brandColore = $brandColore ?? '#0D9488';
    $brandLogo = $brandLogo ?? null;
    $unsubscribeUrl = $unsubscribeUrl ?? null;
    $annoCorrente = $annoCorrente ?? now()->format('Y');
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $brandNome }}</title>
<style type="text/css">
body, table, td, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
body { margin: 0; padding: 0; width: 100% !important; background-color: #f4f4f4; font-family: Arial, Helvetica, sans-serif; }
@media only screen and (max-width: 620px) {
    .email-container { width: 100% !important; }
    .email-body { padding: 15px !important; }
}
</style>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f4;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f4f4;">
<tr><td align="center" style="padding:20px 10px;">

<table role="presentation" class="email-container" cellpadding="0" cellspacing="0" width="600" style="max-width:600px; margin:0 auto;">

    {{-- Header brand: logo (se presente) + nome associazione --}}
    <tr>
        <td style="background-color:{{ $brandColore }}; padding:20px 30px; text-align:center; border-radius:8px 8px 0 0;">
            @if (!empty($brandLogo))
                <img src="{{ $brandLogo }}" alt="{{ $brandNome }}" style="max-height:50px; width:auto; display:inline-block;">
            @endif
            <div style="color:#ffffff; font-size:20px; font-weight:bold; margin-top:{{ !empty($brandLogo) ? '8px' : '0' }};">{{ $brandNome }}</div>
        </td>
    </tr>

    {{-- Corpo --}}
    <tr>
        <td class="email-body" style="background-color:#ffffff; padding:30px; font-size:15px; line-height:1.6; color:#333333;">
            @if (!empty($greeting))
                <p style="margin:0 0 14px 0; font-size:17px; font-weight:bold;">{{ $greeting }}</p>
            @endif

            @foreach ($introLines ?? [] as $line)
                <p style="margin:0 0 12px 0;">{{ $line }}</p>
            @endforeach

            @if (!empty($actionText) && !empty($actionUrl))
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                    <tr><td align="center" style="padding:18px 0;">
                        <a href="{{ $actionUrl }}"
                           style="background-color:{{ $brandColore }}; color:#ffffff; display:inline-block; padding:12px 28px; border-radius:6px; font-size:15px; font-weight:bold; text-decoration:none;">{{ $actionText }}</a>
                    </td></tr>
                </table>
            @endif

            @foreach ($outroLines ?? [] as $line)
                <p style="margin:0 0 12px 0;">{{ $line }}</p>
            @endforeach

            @if (!empty($salutation))
                <p style="margin:18px 0 0 0;">{{ $salutation }}</p>
            @endif

            @if (!empty($actionText) && !empty($actionUrl))
                <p style="margin:24px 0 0 0; font-size:12px; color:#999999; border-top:1px solid #eeeeee; padding-top:12px;">
                    Se il pulsante "{{ $actionText }}" non funziona, copia e incolla questo link nel browser:<br>
                    <a href="{{ $actionUrl }}" style="color:{{ $brandColore }}; word-break:break-all;">{{ $displayableActionUrl ?? $actionUrl }}</a>
                </p>
            @endif
        </td>
    </tr>

    {{-- Footer --}}
    <tr>
        <td style="background-color:#f8f8f8; padding:20px 30px; text-align:center; font-size:12px; color:#999999; border-radius:0 0 8px 8px; border-top:1px solid #eeeeee;">
            <p style="margin:0 0 8px 0;">&copy; {{ $annoCorrente }} {{ $brandNome }}</p>
            @if (!empty($unsubscribeUrl))
                <p style="margin:0;">
                    <a href="{{ $unsubscribeUrl }}" style="color:#999999; text-decoration:underline;">Disiscriviti dalle email</a>
                </p>
            @endif
        </td>
    </tr>

</table>

</td></tr>
</table>
</body>
</html>
