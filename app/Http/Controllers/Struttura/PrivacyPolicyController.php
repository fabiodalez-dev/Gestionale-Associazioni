<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use Illuminate\View\View;

/**
 * Privacy policy pubblica per tenant (spec struttura §7).
 * L'HTML è sanitizzato in scrittura (purifier) e stampato senza escape.
 */
class PrivacyPolicyController extends Controller
{
    private const UUID_REGEX = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    public function __invoke(string $associazione): View
    {
        abort_unless(preg_match(self::UUID_REGEX, $associazione) === 1, 400, 'Link non valido.');

        $assoc = Associazione::query()
            ->where('id', strtolower($associazione))
            ->where('attiva', true)
            ->first();

        abort_unless($assoc !== null, 404, 'Associazione non trovata.');

        return view('struttura.privacy-policy.show', ['associazione' => $assoc]);
    }
}
