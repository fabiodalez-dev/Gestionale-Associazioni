@extends('layouts.app')

@section('title', 'Documentazione API - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><i class="bi bi-book me-2"></i>Documentazione API v1</h1>
            <p class="text-muted mb-0">Base URL: <code>{{ $baseUrl }}</code></p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ url('/docs/api') }}" class="btn btn-outline-primary" target="_blank" rel="noopener">
                <i class="bi bi-filetype-json me-1"></i>Reference OpenAPI (Scramble)
            </a>
            <a href="{{ route('api-keys.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-key me-1"></i>Chiavi API
            </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-shield-lock me-1"></i>Autenticazione</div>
        <div class="card-body">
            <p>Ogni richiesta (eccetto <code>GET /tessere/verify</code>) richiede una credenziale, in una di due forme:</p>
            <ol>
                <li>
                    <strong>Chiave API</strong> (64 caratteri esadecimali, generata dalla pagina Chiavi API):<br>
                    <code>Authorization: Bearer {api_key}</code> &nbsp;oppure&nbsp; <code>X-API-Key: {api_key}</code>
                </li>
                <li>
                    <strong>Token personale Sanctum</strong> (con abilities):<br>
                    <code>Authorization: Bearer {token}</code>
                </li>
            </ol>
            <p class="mb-0">
                Le chiavi <em>scopate</em> accedono solo ai dati della loro associazione; le chiavi <em>globali</em>
                (solo super admin) accedono a tutti i tenant e possono filtrare con <code>?associazione_id={uuid}</code>.
                Rate limit: 120 richieste/minuto per credenziale (30/min per IP su <code>/tessere/verify</code>).
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-person-check me-1"></i>Permessi</div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <thead><tr><th>Permesso</th><th>Cosa abilita</th></tr></thead>
                <tbody>
                    <tr><td><code>tessere:read</code></td><td>GET /tessere, /tessere/{id}, /tessere/search</td></tr>
                    <tr><td><code>soci:read</code></td><td>GET /soci, /soci/{id}, /soci/search</td></tr>
                    <tr><td><code>soci:write</code></td><td>PUT /soci/{id}</td></tr>
                    <tr><td><code>eventi:read</code></td><td>GET /eventi, /eventi/{id}</td></tr>
                    <tr><td><code>eventi:write</code></td><td>POST /eventi/{id}/checkin</td></tr>
                    <tr><td><code>quote:read</code></td><td>GET /quote, /quote/{socio_id}</td></tr>
                    <tr><td><code>soci:*</code> / <code>tessere:*</code> / …</td><td>Wildcard di categoria (read + write)</td></tr>
                    <tr><td><code>*</code></td><td>Accesso totale</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-exclamation-octagon me-1"></i>Formato errori</div>
        <div class="card-body">
<pre class="bg-light p-3 rounded"><code>{ "success": false, "error": "Messaggio in italiano", "code": "codice_macchina" }</code></pre>
            <table class="table table-sm mb-0">
                <thead><tr><th>HTTP</th><th>Codici</th></tr></thead>
                <tbody>
                    <tr><td>400</td><td><code>invalid_tessera_id</code>, <code>missing_query</code>, <code>invalid_id</code>, <code>missing_socio</code>, <code>socio_not_active</code>, <code>invalid_stato</code>, <code>empty_body</code>, <code>no_valid_fields</code>, <code>invalid_json</code>, <code>invalid_filter</code></td></tr>
                    <tr><td>401</td><td><code>auth_missing</code>, <code>auth_invalid</code></td></tr>
                    <tr><td>403</td><td><code>key_disabled</code>, <code>association_disabled</code>, <code>key_expired</code>, <code>permission_denied</code>, <code>global_only</code></td></tr>
                    <tr><td>404</td><td><code>not_found</code>, <code>tessera_not_found</code>, <code>socio_not_found</code></td></tr>
                    <tr><td>405</td><td><code>method_not_allowed</code></td></tr>
                    <tr><td>409</td><td><code>duplicate_email</code></td></tr>
                    <tr><td>429</td><td><code>rate_limited</code></td></tr>
                    <tr><td>500</td><td><code>db_error</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-list-ul me-1"></i>Endpoint</div>
        <div class="card-body">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Endpoint</th><th>Descrizione</th><th>Note</th></tr></thead>
                <tbody>
                    <tr><td><code>GET /tessere/verify?tessera_id={uuid}</code></td><td>Verifica tessera (QR)</td><td><span class="badge bg-success">Pubblico</span> <code>valid = stato Attiva e non scaduta</code></td></tr>
                    <tr><td><code>GET /tessere</code></td><td>Lista paginata</td><td><code>page</code>, <code>limit</code> (1-100), <code>anno</code>, <code>stato</code></td></tr>
                    <tr><td><code>GET /tessere/search?q=</code></td><td>Ricerca (max 50)</td><td>q min 1 carattere</td></tr>
                    <tr><td><code>GET /tessere/{id}</code></td><td>Dettaglio</td><td>404 se di altro tenant</td></tr>
                    <tr><td><code>GET /soci</code></td><td>Lista paginata</td><td><code>stato</code> whitelist</td></tr>
                    <tr><td><code>GET /soci/search?q=</code></td><td>Ricerca (max 50)</td><td>q min 2 caratteri</td></tr>
                    <tr><td><code>GET /soci/{id}</code></td><td>Dettaglio + tags + ultime 5 tessere</td><td></td></tr>
                    <tr><td><code>PUT /soci/{id}</code></td><td>Aggiornamento parziale</td><td>Whitelist: nome, cognome, email, telefono, indirizzo, citta, provincia, cap, stato, note</td></tr>
                    <tr><td><code>GET /eventi</code></td><td>Lista paginata</td><td><code>futuri=1</code>, <code>oggi=1</code></td></tr>
                    <tr><td><code>GET /eventi/{id}</code></td><td>Dettaglio + partecipanti</td><td></td></tr>
                    <tr><td><code>POST /eventi/{id}/checkin</code></td><td>Check-in socio</td><td>Body: <code>socio_id</code> | <code>tessera_id</code> | <code>numero_tessera</code> | <code>numero_socio</code> (idempotente)</td></tr>
                    <tr><td><code>GET /quote</code></td><td>Lista paginata</td><td><code>anno</code>, <code>stato</code></td></tr>
                    <tr><td><code>GET /quote/{socio_id}</code></td><td>Quote di un socio</td><td>Senza paginazione</td></tr>
                    <tr><td><code>GET /associazioni</code></td><td>Elenco associazioni attive</td><td><span class="badge bg-warning text-dark">Solo chiavi globali</span></td></tr>
                </tbody>
            </table>
            <p class="text-muted small mt-2 mb-0">
                Le liste supportano anche filtri e ordinamenti avanzati in stile JSON:API
                (<code>filter[stato]=Attiva</code>, <code>sort=-anno_validita</code>) — vedi la reference OpenAPI.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-code-slash me-1"></i>Esempi client</div>
        <div class="card-body">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-curl" type="button" role="tab">cURL</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-js" type="button" role="tab">JavaScript</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-py" type="button" role="tab">Python</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-php" type="button" role="tab">PHP</button></li>
            </ul>
            <div class="tab-content pt-3">
                <div class="tab-pane fade show active" id="tab-curl" role="tabpanel">
<pre class="bg-light p-3 rounded mb-0"><code>curl -H "Authorization: Bearer LA_TUA_API_KEY" \
  "{{ $baseUrl }}/soci?page=1&limit=20&stato=Attivo"</code></pre>
                </div>
                <div class="tab-pane fade" id="tab-js" role="tabpanel">
<pre class="bg-light p-3 rounded mb-0"><code>const res = await fetch('{{ $baseUrl }}/tessere/search?q=rossi', {
  headers: { Authorization: 'Bearer LA_TUA_API_KEY' }
});
const data = await res.json();</code></pre>
                </div>
                <div class="tab-pane fade" id="tab-py" role="tabpanel">
<pre class="bg-light p-3 rounded mb-0"><code>import requests

r = requests.get(
    "{{ $baseUrl }}/quote",
    headers={"Authorization": "Bearer LA_TUA_API_KEY"},
    params={"anno": 2026},
)
print(r.json())</code></pre>
                </div>
                <div class="tab-pane fade" id="tab-php" role="tabpanel">
<pre class="bg-light p-3 rounded mb-0"><code>$ch = curl_init('{{ $baseUrl }}/eventi?futuri=1');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer LA_TUA_API_KEY']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$data = json_decode(curl_exec($ch), true);</code></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-qr-code me-1"></i>QR code e app mobile</div>
        <div class="card-body">
            <p>
                Il QR code stampato sulla tessera contiene un URL con il parametro <code>t</code> uguale all'UUID
                della tessera. Il flusso dell'app di scansione è:
            </p>
            <ol>
                <li>Scansiona il QR ed estrai il parametro <code>t</code> (UUID tessera).</li>
                <li><strong>Verifica</strong> (senza autenticazione): <code>GET {{ $baseUrl }}/tessere/verify?tessera_id={t}</code> → campo <code>valid</code>.</li>
                <li><strong>Check-in evento</strong> (con API key, permesso <code>eventi:write</code>):
                    <code>POST {{ $baseUrl }}/eventi/{evento_id}/checkin</code> con body <code>{"tessera_id": "{t}"}</code>.
                    La risposta include <code>already_checked_in</code> (il check-in è idempotente).</li>
            </ol>
            <p class="mb-0 text-muted small">
                Per la scansione in browser è possibile usare la libreria <code>html5-qrcode</code>; la pagina
                <a href="{{ route('eventi.checkin') }}">/checkin</a> integrata implementa già l'intero flusso.
            </p>
        </div>
    </div>
@endsection
