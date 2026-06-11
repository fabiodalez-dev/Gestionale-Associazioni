<?php

use App\Providers\ApiV1ServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    ApiV1ServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    PaymentServiceProvider::class,
];
