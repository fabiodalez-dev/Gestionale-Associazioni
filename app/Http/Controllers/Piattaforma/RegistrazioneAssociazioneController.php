<?php

namespace App\Http\Controllers\Piattaforma;

use App\Forms\RegistrazioneAssociazioneForm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Piattaforma\RegistrazioneAssociazioneRequest;
use App\Models\RichiestaRegistrazione;
use App\Services\Piattaforma\RegistrazioneAssociazioni;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Iscrizione autonoma di un'associazione alla piattaforma (pagina pubblica).
 * Disponibile solo se il super admin ha scelto la modalità "revisione"
 * o "automatica" in Impostazioni di sistema → Registrazione.
 */
class RegistrazioneAssociazioneController extends Controller
{
    public function create(): View
    {
        abort_if(RichiestaRegistrazione::modalita() === 'disattivata', 404);

        return view('piattaforma.registrazione.create', [
            'form' => new RegistrazioneAssociazioneForm(RichiestaRegistrazione::class),
            'automatica' => RichiestaRegistrazione::modalita() === 'automatica',
        ]);
    }

    public function store(RegistrazioneAssociazioneRequest $request, RegistrazioneAssociazioni $registrazioni): RedirectResponse
    {
        abort_if(RichiestaRegistrazione::modalita() === 'disattivata', 404);

        $richiesta = $registrazioni->registra($request->validated(), $request->ip());

        if ($richiesta->stato === RichiestaRegistrazione::STATO_APPROVATA) {
            return redirect()->route('login')
                ->with('status', $richiesta->nome.' è attiva: accedi con '.$richiesta->admin_email.' e la password che hai scelto.');
        }

        return redirect()->route('registrazione.inviata')->with('registrazione_nome', $richiesta->nome);
    }

    public function inviata(): View
    {
        return view('piattaforma.registrazione.inviata', ['nome' => session('registrazione_nome')]);
    }
}
