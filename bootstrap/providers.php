<?php

use App\Finance\FinanceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RequestMacroProvider;
use App\Providers\ResponseMacroProvider;

return [
    AppServiceProvider::class,
    FinanceServiceProvider::class,
    FortifyServiceProvider::class,
    RequestMacroProvider::class,
    ResponseMacroProvider::class,
];
