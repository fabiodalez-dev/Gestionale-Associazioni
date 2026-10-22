<?php

namespace App\Support;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Binder di tutti gli export Excel/CSV (config/excel.php): i testi che
 * inizierebbero una formula vengono scritti come testo neutralizzato.
 * Le formule non servono in nessun export dell'app.
 */
class ExcelValueBinderSicuro extends DefaultValueBinder
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && $value !== '' && CellaSicura::valore($value) !== $value) {
            $cell->setValueExplicit(CellaSicura::valore($value), DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
