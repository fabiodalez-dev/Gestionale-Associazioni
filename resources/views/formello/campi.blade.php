{{--
    Campi di un form Formello nella griglia Bootstrap (il tag <form> e i pulsanti restano nella pagina).
    Parametri: form (ModuloFormello), solo (opzionale, elenco dei campi da rendere in questo punto).
--}}
<div class="row">
    @foreach ($form->getFields() as $nomeCampo => $campo)
        @continue(isset($solo) && ! in_array($nomeCampo, $solo, true))
        <div class="col-md-{{ $campo['config']['columns'] ?? 12 }} mb-3" data-formello-field="{{ $nomeCampo }}">
            {!! $form->renderField($nomeCampo) !!}
        </div>
    @endforeach
</div>
