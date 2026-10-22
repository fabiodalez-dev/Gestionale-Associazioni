<?php

use App\Support\ExcelValueBinderSicuro;

/*
| Sovrascrive solo il binder dei valori di maatwebsite/excel (le altre chiavi
| restano quelle del pacchetto): niente formule eseguibili negli export.
*/
return [
    'value_binder' => [
        'default' => ExcelValueBinderSicuro::class,
    ],
];
