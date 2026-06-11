@extends('layouts.app')

@section('title', 'Nuovo Socio - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-person-plus me-2"></i>Nuovo Socio</h1>
        <a href="{{ route('soci.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
    </div>

    <form method="POST" action="{{ route('soci.store') }}">
        @csrf
        @include('soci.partials.form', ['socio' => null])
        <div class="mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea Socio</button>
        </div>
    </form>
@endsection
