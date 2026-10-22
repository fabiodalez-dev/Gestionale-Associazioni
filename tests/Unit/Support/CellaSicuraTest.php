<?php

namespace Tests\Unit\Support;

use App\Support\CellaSicura;
use App\Support\ExcelValueBinderSicuro;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class CellaSicuraTest extends TestCase
{
    public function test_neutralizza_le_formule(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", CellaSicura::valore('=HYPERLINK("http://x")'));
        $this->assertSame("'@SUM(A1)", CellaSicura::valore('@SUM(A1)'));
        $this->assertSame("'+cmd|' /C calc'!A0", CellaSicura::valore("+cmd|' /C calc'!A0"));
        $this->assertSame("'-2+3+cmd", CellaSicura::valore('-2+3+cmd'));
        $this->assertSame("'\tx", CellaSicura::valore("\tx"));
    }

    public function test_lascia_intatti_testi_numeri_e_telefoni(): void
    {
        foreach (['Rossi', 'Mario Rossi', '-25,00', '25.5', '+39 333 123 4567', '+39 (051) 12-34', '', 'a=b'] as $valore) {
            $this->assertSame($valore, CellaSicura::valore($valore));
        }
        $this->assertSame(42, CellaSicura::valore(42));
        $this->assertNull(CellaSicura::valore(null));
    }

    public function test_gli_export_excel_non_scrivono_formule(): void
    {
        $foglio = (new Spreadsheet)->getActiveSheet();
        $binder = new ExcelValueBinderSicuro;

        $binder->bindValue($foglio->getCell('A1'), '=1+1');
        $this->assertSame("'=1+1", $foglio->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $foglio->getCell('A1')->getDataType());

        $binder->bindValue($foglio->getCell('A2'), 'Rossi');
        $this->assertSame('Rossi', $foglio->getCell('A2')->getValue());

        $binder->bindValue($foglio->getCell('A3'), 25.5);
        $this->assertSame(DataType::TYPE_NUMERIC, $foglio->getCell('A3')->getDataType());
    }
}
