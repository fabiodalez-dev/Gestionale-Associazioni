<?php

namespace App\Http\Controllers\AnagraficaPro;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * QR PNG del link di invito al mini-sito (f.22 PIANO-VITA-SOCIALE):
 * punta a /a/{slug}/iscriviti. Inline per l'anteprima nel box "Invita
 * al tuo mini-sito" delle configurazioni, attachment con ?download=1.
 */
class InvitoQrController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $associazione = Associazione::findOrFail(session('associazione_id'));
        Gate::authorize('manageSettings', $associazione);

        $url = Route::has('pubblico.iscriviti')
            ? route('pubblico.iscriviti', $associazione)
            : url('/a/'.$associazione->slug.'/iscriviti');

        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'outputBase64' => false,
            'addQuietzone' => true,
        ]);

        $png = (new QRCode($options))->render($url);

        $headers = ['Content-Type' => 'image/png'];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="invito-'.$associazione->slug.'.png"';
        }

        return response($png, 200, $headers);
    }
}
