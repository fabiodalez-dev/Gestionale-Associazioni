<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-cpu me-1"></i>Server</h5></div>
            <table class="table table-sm mb-0">
                <tbody>
                    @foreach ($info['server'] as $voce => $valore)
                        <tr><th class="ps-3 fw-medium">{{ $voce }}</th><td>{{ $valore }}</td></tr>
                    @endforeach
                    <tr>
                        <th class="ps-3 fw-medium">Estensioni PHP</th>
                        <td>
                            @foreach ($info['estensioni'] as $estensione => $caricata)
                                <span class="badge {{ $caricata ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }} me-1">{{ $estensione }}</span>
                            @endforeach
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-file-earmark-lock me-1"></i>File .env</h5></div>
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><th class="ps-3 fw-medium">Percorso</th><td><code class="small">{{ $info['env']['percorso'] }}</code></td></tr>
                    <tr><th class="ps-3 fw-medium">Scrivibile</th><td>{!! $info['env']['scrivibile'] ? '<span class="badge bg-success">sì</span>' : '<span class="badge bg-danger">no</span>' !!}</td></tr>
                    <tr><th class="ps-3 fw-medium">Backup salvati</th><td>{{ $info['env']['backup'] }}</td></tr>
                    <tr><th class="ps-3 fw-medium">APP_KEY</th><td>{!! $info['env']['app_key'] ? '<span class="badge bg-success">impostata</span>' : '<span class="badge bg-danger">mancante</span>' !!} <span class="text-muted small">non modificabile da qui: cifra sessioni e dati salvati</span></td></tr>
                </tbody>
            </table>
        </div>
        @if (! empty($info['altre']))
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-list-ul me-1"></i>Altre variabili nel .env</h5></div>
                <div class="card-body">
                    @foreach ($info['altre'] as $variabile)
                        <code class="d-inline-block me-2 mb-1">{{ $variabile }}</code>
                    @endforeach
                    <p class="small text-muted mt-2 mb-0">Non gestite da questa pagina: vengono conservate così come sono. I valori non vengono mostrati.</p>
                </div>
            </div>
        @endif
    </div>
</div>
