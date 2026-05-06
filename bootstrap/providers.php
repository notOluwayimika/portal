<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RequestMacroProvider;
use App\Providers\ResponseMacroProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    RequestMacroProvider::class,
    ResponseMacroProvider::class,
];
