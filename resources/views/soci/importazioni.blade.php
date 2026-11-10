@extends('layouts.app')

@section('title', 'Storico importazioni - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-clock-history me-2"></i>Storico importazioni</h1>
        <a href="{{ route('soci.import') }}" class="btn btn-outline-secondary"><i class="bi bi-upload me-1"></i>Nuova importazione</a>
    </div>

    <p class="text-muted small">Le importazioni restano in elenco per dodici mesi. Il report degli errori si apre in Excel: correggi le righe e reimporta il file.</p>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Data</th><th>File</th><th>Da</th><th class="text-end">Righe</th><th class="text-end">Create</th><th class="text-end">Aggiornate</th><th class="text-end">Scartate</th><th class="text-end">Durata</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($importazioni as $importazione)
                        <tr>
                            <td class="text-nowrap">{{ $importazione->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $importazione->nome_file }}</td>
                            <td>{{ $importazione->utente?->nome_completo ?? $importazione->utente?->email ?? '—' }}</td>
                            <td class="text-end">{{ $importazione->righe_lette }}</td>
                            <td class="text-end">{{ $importazione->create }}</td>
                            <td class="text-end">{{ $importazione->aggiornate }}</td>
                            <td class="text-end">@if ($importazione->scartate > 0)<span class="badge bg-warning text-dark">{{ $importazione->scartate }}</span>@else 0 @endif</td>
                            <td class="text-end text-muted small">{{ number_format($importazione->durata_ms / 1000, 1, ',', '.') }} s</td>
                            <td class="text-end">
                                @if ($importazione->scartate > 0)
                                    <a href="{{ route('soci.importazioni.errori', $importazione->id) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv me-1"></i>Errori</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">Nessuna importazione.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $importazioni->links() }}</div>
@endsection
