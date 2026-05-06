<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RequestMacroProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Request::macro('validatedExcept', function(array $keys = []){
            return Arr::except($this->validated(), $keys);
        });

        Request::macro('validatedOnly', function (array $keys = []) {
            return Arr::only($this->validated(), $keys);
        });

        Request::macro('validatedKeys', function (array $keys = []) {
            return array_diff(array_keys($this->validated() ?? []), $keys);
        });

        Request::macro('keysExcept', function (array $keys = []) {
            return array_diff($this->keys(), $keys);
        });
    }
}
