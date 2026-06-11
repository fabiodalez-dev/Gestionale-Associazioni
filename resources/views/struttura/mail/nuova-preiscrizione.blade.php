<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #111827; background: #F3F4F6; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; padding: 32px;">
        <h1 style="font-size: 20px; color: #FF7B11; margin-top: 0;">Nuova richiesta di pre-iscrizione</h1>
        <p>È arrivata una nuova richiesta di pre-iscrizione per <strong>{{ $associazione->nome }}</strong>:</p>
        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <tr><td style="padding: 6px 0; color: #6B7280;">Nome</td><td style="padding: 6px 0;"><strong>{{ $socio->nome_completo }}</strong></td></tr>
            <tr><td style="padding: 6px 0; color: #6B7280;">Numero socio</td><td style="padding: 6px 0;">{{ $socio->numero_socio }}</td></tr>
            <tr><td style="padding: 6px 0; color: #6B7280;">Email</td><td style="padding: 6px 0;">{{ $socio->email }}</td></tr>
            <tr><td style="padding: 6px 0; color: #6B7280;">Telefono</td><td style="padding: 6px 0;">{{ $socio->telefono ?: '—' }}</td></tr>
            <tr><td style="padding: 6px 0; color: #6B7280;">Codice fiscale</td><td style="padding: 6px 0;">{{ $socio->codice_fiscale ?: '—' }}</td></tr>
            <tr><td style="padding: 6px 0; color: #6B7280;">Data richiesta</td><td style="padding: 6px 0;">{{ optional($socio->data_iscrizione)->format('d/m/Y') }}</td></tr>
        </table>
        <p style="margin-top: 20px;">Il socio è in stato <strong>In Attesa</strong>: accedi al gestionale per approvare o rifiutare la richiesta.</p>
    </div>
</body>
</html>
