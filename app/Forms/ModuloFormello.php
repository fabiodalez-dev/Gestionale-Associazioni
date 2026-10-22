<?php

namespace App\Forms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Metalogico\Formello\Formello;

/**
 * Base dei form Formello dell'app.
 *
 * I form vengono resi solo come campi (view formello.campi) dentro il <form> della
 * pagina, che mantiene azione, pulsanti e stile del layout: per questo create()/edit()
 * non definiscono azione e metodo. La validazione resta nelle FormRequest.
 *
 * $contesto porta i dati extra che servono a definire i campi (associazione,
 * elenchi di scelta, campi obbligatori…) ed è disponibile già dentro fields().
 * La chiave 'error_bag' indica una bag di errori con nome diversa da quella predefinita.
 */
abstract class ModuloFormello extends Formello
{
    /** @var array<string, mixed> */
    protected array $contesto = [];

    /**
     * @param  array<string, mixed>  $contesto
     */
    public function __construct(Model|string $model, array $contesto = [])
    {
        $this->contesto = $contesto;

        // 'error_bag': legge gli errori da una bag con nome (es. Fortify 'updatePassword')
        $errori = null;
        if (filled($contesto['error_bag'] ?? null)) {
            $sessione = session('errors');
            $errori = (new ViewErrorBag)->put('default', $sessione instanceof ViewErrorBag
                ? $sessione->getBag((string) $contesto['error_bag'])
                : new MessageBag);
        }

        parent::__construct($model, $errori);
    }

    protected function create(): array
    {
        return [];
    }

    protected function edit(): array
    {
        return [];
    }
}
