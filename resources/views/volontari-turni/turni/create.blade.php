@extends('layouts.app')

@section('title', 'Nuovo turno - '.session('app_name', config('app.name')))

@section('content')
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-plus-circle me-2"></i>Nuovo turno</h1>
        <a href="{{ route('turni.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Turni</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('turni.store') }}">
                @csrf
                @include('volontari-turni.turni._form', ['turno' => null])
                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea turno</button>
                </div>
            </form>
        </div>
    </div>
@endsection
