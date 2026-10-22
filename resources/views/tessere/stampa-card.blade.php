<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stampa Tessera {{ $tessera->numero_tessera }}</title>
    <link href="{{ asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .card-container {
            width: 350px;
            height: 210px;
            border-radius: 15px;
            background: #fff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 20px;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        .card-header {
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .card-header .logo {
            width: 40px;
            height: 40px;
            margin-right: 10px;
            background-color: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #495057;
            font-size: 0.7rem;
            overflow: hidden;
        }
        .card-header .logo img { width: 100%; height: 100%; object-fit: cover; }
        .card-header h1 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
            color: #333;
        }
        .card-body {
            display: flex;
            flex-grow: 1;
        }
        .member-photo {
            width: 80px;
            height: 100px;
            background-color: #e9ecef;
            border-radius: 8px;
            margin-right: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            font-size: 0.8rem;
        }
        .member-details { font-size: 0.9rem; }
        .member-details .name {
            font-weight: 700;
            font-size: 1.1rem;
            color: #000;
        }
        .member-details p { margin: 4px 0; color: #555; }
        .card-footer {
            text-align: center;
            font-size: 0.7rem;
            color: #999;
            margin-top: auto;
        }
        .qr-code {
            width: 50px;
            height: 50px;
            margin-left: auto;
            object-fit: contain;
        }
        .print-btn {
            position: fixed; top: 20px; right: 20px; padding: 8px 20px;
            cursor: pointer; background-color: #FF7B11; color: #fff;
            border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 600;
            box-shadow: 0 2px 8px rgba(255,123,17,0.3);
        }
        @media print {
            body { background-color: #fff; }
            .card-container { box-shadow: none; border: 1px solid #ccc; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="card-container">
        <div class="card-header">
            <div class="logo">
                @if ($associazione?->logoUrl())
                    <img src="{{ $associazione->logoUrl('miniatura') }}" alt="Logo">
                @else
                    LOGO
                @endif
            </div>
            <h1>{{ $associazione?->nome }}</h1>
        </div>
        <div class="card-body">
            <div class="member-photo">Foto</div>
            <div class="member-details">
                <p class="name">{{ $socio?->nome }} {{ $socio?->cognome }}</p>
                <p><strong>N. Tessera:</strong> {{ $tessera->numero_tessera }}</p>
                <p><strong>Validità:</strong> {{ $tessera->anno_validita }}</p>
                <p><strong>Scadenza:</strong> {{ $tessera->data_scadenza?->format('d/m/Y') }}</p>
            </div>
            <img class="qr-code" src="{{ $qrDataUri }}" alt="QR Verifica">
        </div>
        <div class="card-footer">
            Tessera Associativa Personale
        </div>
    </div>

    <button class="no-print print-btn" onclick="window.print()"><i class="bi bi-printer me-1"></i> Stampa</button>

</body>
</html>
