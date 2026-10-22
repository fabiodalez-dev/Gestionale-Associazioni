<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #111827; background: #F3F4F6; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; padding: 32px;">
        <h1 style="font-size: 20px; color: #0D9488; margin-top: 0;">Conferma la tua email</h1>
        <p>Ciao {{ $richiesta->admin_nome }},</p>
        <p>per completare l'iscrizione di <strong>{{ \Illuminate\Support\Str::limit($richiesta->nome, 80) }}</strong> a {{ config('app.name', 'Gestione Associazioni') }} conferma che questo indirizzo è tuo:</p>
        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ $url }}" style="background: #0D9488; color: #FFFFFF; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;">Conferma l'email</a>
        </p>
        <p style="font-size: 13px; color: #6B7280;">Il link vale {{ \App\Services\Piattaforma\RegistrazioneAssociazioni::ORE_VALIDITA_LINK }} ore. Se il pulsante non funziona, copia questo indirizzo nel browser:<br>{{ $url }}</p>
        <p style="font-size: 13px; color: #6B7280;">Se non hai chiesto tu l'iscrizione, ignora questa email: senza conferma non succede nulla.</p>
    </div>
</body>
</html>
