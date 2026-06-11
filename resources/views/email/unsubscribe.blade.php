{{-- Pagina pubblica standalone di disiscrizione (brandizzata, nessun layout app) --}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disiscrizione email — {{ $associazioneNome }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, Helvetica, sans-serif; color: #333; }
        .container { max-width: 480px; margin: 60px auto; padding: 0 15px; }
        .card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .header { background-color: #FF7B11; color: #fff; padding: 24px 30px; text-align: center; font-size: 20px; font-weight: bold; }
        .body { padding: 30px; font-size: 15px; line-height: 1.6; text-align: center; }
        .footer { padding: 16px 30px; background: #f8f8f8; border-top: 1px solid #eee; text-align: center; font-size: 12px; color: #999; }
        .btn { display: inline-block; background-color: #FF7B11; color: #fff; border: 0; border-radius: 6px; padding: 12px 28px; font-size: 15px; font-weight: bold; cursor: pointer; }
        .muted { color: #888; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">{{ $associazioneNome }}</div>
        <div class="body">
            @if ($state === 'invalid')
                <h2>Link non valido</h2>
                <p>Il link di disiscrizione non è valido o è scaduto.</p>
            @elseif ($state === 'already')
                <h2>Già disiscritto</h2>
                <p>L'indirizzo <strong>{{ $socio->email }}</strong> risulta già disiscritto dalle comunicazioni email.</p>
            @elseif ($state === 'done')
                <h2>Disiscrizione avvenuta</h2>
                <p>L'indirizzo <strong>{{ $socio->email }}</strong> non riceverà più comunicazioni email da {{ $associazioneNome }}.</p>
                <p class="muted">Per riattivare le comunicazioni contatta direttamente l'associazione.</p>
            @else
                <h2>Conferma disiscrizione</h2>
                <p>Vuoi davvero disiscrivere l'indirizzo <strong>{{ $socio->email }}</strong> dalle comunicazioni email di {{ $associazioneNome }}?</p>
                <form method="POST" action="{{ route('email.unsubscribe.confirm', ['socio' => $socio->id, 'token' => $socio->email_opt_out_token]) }}">
                    @csrf
                    <input type="hidden" name="confirm_unsubscribe" value="1">
                    <button type="submit" class="btn">Conferma Disiscrizione</button>
                </form>
            @endif
        </div>
        <div class="footer">{{ $associazioneNome }}</div>
    </div>
</div>
</body>
</html>
