{{-- Layout base email-safe (porting di templates/email_base.html del legacy) --}}
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $associazioneNome }}</title>
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

    <tr>
        <td style="background-color:#0D9488; padding:20px 30px; text-align:center; border-radius:8px 8px 0 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>
                <td style="text-align:center;">
                    @if (!empty($logoUrl))
                    <img src="{{ $logoUrl }}" alt="{{ $associazioneNome }}" style="max-height:50px; width:auto; display:inline-block;">
                    @endif
                    <div style="color:#ffffff; font-size:20px; font-weight:bold; margin-top:8px;">{{ $associazioneNome }}</div>
                </td>
            </tr></table>
        </td>
    </tr>

    <tr>
        <td class="email-body" style="background-color:#ffffff; padding:30px; font-size:15px; line-height:1.6; color:#333333;">
            {!! $body !!}
        </td>
    </tr>

    <tr>
        <td style="background-color:#f8f8f8; padding:20px 30px; text-align:center; font-size:12px; color:#999999; border-radius:0 0 8px 8px; border-top:1px solid #eeeeee;">
            <p style="margin:0 0 8px 0;">&copy; {{ $anno }} {{ $associazioneNome }}</p>
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
