@extends('layouts.app')

@section('title', 'Modifica turno - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-pencil-square me-2"></i>Modifica turno</h1>
        <a href="{{ route('turni.show', $turno) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Dettaglio</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('turni.update', $turno) }}">
                @csrf @method('PUT')
                @include('volontari-turni.turni._form', ['turno' => $turno])
                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva modifiche</button>
                </div>
            </form>
        </div>
    </div>
@endsection
