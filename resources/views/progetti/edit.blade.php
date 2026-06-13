@extends('layouts.app')

@section('title', 'Modifica progetto')

@section('content')
<div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-pencil-square me-2"></i>Modifica progetto</h1>
    <a href="{{ route('progetti.show', $progetto) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Indietro</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('progetti.update', $progetto) }}">
            @method('PUT')
            @include('progetti._form', ['progetto' => $progetto])
            <div class="d-flex justify-content-end gap-2 mt-2">
                <a href="{{ route('progetti.show', $progetto) }}" class="btn btn-outline-secondary">Annulla</a>
                <button type="submit" class="btn btn-primary">Salva modifiche</button>
            </div>
        </form>
    </div>
</div>
@endsection
