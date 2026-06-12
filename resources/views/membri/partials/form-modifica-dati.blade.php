{{-- Form proposta modifica dati anagrafici (f.19 — piano "Gestione 2").
     Variabili: $socio, $service (ModificaDatiService).
     I campi sono precompilati col valore corrente: la richiesta salverà
     solo quelli effettivamente diversi. --}}
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="md-nome">Nome</label>
        <input type="text" class="form-control @error('nome') is-invalid @enderror" id="md-nome" name="nome"
               maxlength="100" value="{{ old('nome', $socio->nome) }}">
        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="md-cognome">Cognome</label>
        <input type="text" class="form-control @error('cognome') is-invalid @enderror" id="md-cognome" name="cognome"
               maxlength="100" value="{{ old('cognome', $socio->cognome) }}">
        @error('cognome')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="md-email">Email</label>
        <input type="email" class="form-control @error('email') is-invalid @enderror" id="md-email" name="email"
               maxlength="255" value="{{ old('email', $socio->email) }}">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="md-telefono">Telefono</label>
        <input type="tel" class="form-control @error('telefono') is-invalid @enderror" id="md-telefono" name="telefono"
               maxlength="20" value="{{ old('telefono', $socio->telefono) }}">
        @error('telefono')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="md-codice-fiscale">Codice fiscale</label>
        <input type="text" class="form-control text-uppercase @error('codice_fiscale') is-invalid @enderror"
               id="md-codice-fiscale" name="codice_fiscale" maxlength="16"
               value="{{ old('codice_fiscale', $socio->codice_fiscale) }}">
        @error('codice_fiscale')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="md-data-nascita">Data di nascita</label>
        <input type="date" class="form-control @error('data_nascita') is-invalid @enderror" id="md-data-nascita"
               name="data_nascita" value="{{ old('data_nascita', $socio->data_nascita?->toDateString()) }}">
        @error('data_nascita')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="md-indirizzo">Indirizzo</label>
        <input type="text" class="form-control @error('indirizzo') is-invalid @enderror" id="md-indirizzo"
               name="indirizzo" maxlength="255" value="{{ old('indirizzo', $socio->indirizzo) }}">
        @error('indirizzo')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label" for="md-citta">Città</label>
        <input type="text" class="form-control @error('citta') is-invalid @enderror" id="md-citta" name="citta"
               maxlength="100" value="{{ old('citta', $socio->citta) }}">
        @error('citta')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-1">
        <label class="form-label" for="md-provincia">Prov.</label>
        <input type="text" class="form-control text-uppercase @error('provincia') is-invalid @enderror"
               id="md-provincia" name="provincia" maxlength="2" value="{{ old('provincia', $socio->provincia) }}">
        @error('provincia')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
        <label class="form-label" for="md-cap">CAP</label>
        <input type="text" class="form-control @error('cap') is-invalid @enderror" id="md-cap" name="cap"
               maxlength="5" inputmode="numeric" value="{{ old('cap', $socio->cap) }}">
        @error('cap')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
