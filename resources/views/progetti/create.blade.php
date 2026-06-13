@extends('layouts.app')

@section('title', 'Nuovo progetto')

@section('content')
<div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-kanban me-2"></i>Nuovo progetto</h1>
    <a href="{{ route('progetti.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Indietro</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('progetti.store') }}">
            @include('progetti._form', ['progetto' => null])
            <div class="d-flex justify-content-end gap-2 mt-2">
                <a href="{{ route('progetti.index') }}" class="btn btn-outline-secondary">Annulla</a>
                <button type="submit" class="btn btn-primary">Crea progetto</button>
            </div>
        </form>
    </div>
</div>
@endsection
