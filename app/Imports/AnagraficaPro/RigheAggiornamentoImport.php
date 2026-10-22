<?php

namespace App\Imports\AnagraficaPro;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lettura righe del file di aggiornamento soci (f.25): heading row + skip
 * righe vuote; la logica diff/applicazione è in ImportAggiornamentoService
 * (il file viene letto due volte: anteprima e applicazione).
 */
class RigheAggiornamentoImport implements Import, SkipsEmptyRows, WithHeadingRow {}
