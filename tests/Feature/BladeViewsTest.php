<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Controllo statico di tutte le viste Blade: errori che altrimenti emergono
 * solo aprendo la pagina.
 * - il PHP compilato deve essere sintatticamente valido;
 * - nessuna direttiva strutturale deve restare come testo (blocco non chiuso,
 *   direttive attaccate come "@endif@if");
 * - nessun file deve mescolare "@php(...)" inline e blocchi "@php … @endphp":
 *   se l'inline precede il blocco, Blade ingloba nel blocco l'HTML in mezzo.
 */
class BladeViewsTest extends TestCase
{
    private const STRUTTURALI = 'endif|endforeach|endforelse|endsection|endphp|endpush|endonce|endcan|endauth|endisset|endunless|else|elseif|php|foreach|if|section';

    /**
     * @return array<string, string> percorso relativo => sorgente
     */
    private function viste(): array
    {
        $viste = [];
        $iteratore = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
        foreach ($iteratore as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $viste[substr($file->getPathname(), strlen(base_path()) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($viste);

        return $viste;
    }

    public function test_tutte_le_viste_compilano_in_php_valido_senza_direttive_residue(): void
    {
        $problemi = [];

        foreach ($this->viste() as $percorso => $sorgente) {
            $compilato = Blade::compileString($sorgente);

            try {
                token_get_all($compilato, TOKEN_PARSE);
            } catch (ParseError $e) {
                $problemi[] = "{$percorso}: errore di sintassi ({$e->getMessage()}, riga {$e->getLine()} del compilato)";
            }

            $senzaEscape = (string) preg_replace('/@@\w+/', '', $compilato);
            if (preg_match_all('/(?<![\w@.])@('.self::STRUTTURALI.')\b/', $senzaEscape, $trovate)) {
                $problemi[] = "{$percorso}: direttive non compilate @".implode(', @', array_unique($trovate[1]));
            }
        }

        $this->assertSame([], $problemi, implode("\n", $problemi));
    }

    public function test_nessuna_vista_mescola_php_inline_e_a_blocco(): void
    {
        $misti = array_keys(array_filter(
            $this->viste(),
            fn (string $sorgente) => preg_match('/@php\s*\(/', $sorgente) === 1 && preg_match('/@php\s*$/m', $sorgente) === 1,
        ));

        $this->assertSame([], $misti, "Usare una sola forma di @php per file:\n".implode("\n", $misti));
    }
}
