<?php

namespace App\Finance\Providers;

use App\Finance\Services\PaystackClient;
use App\Finance\Services\PaystackSignature;
use Illuminate\Support\ServiceProvider;

/**
 * Finance's composition root.
 *
 * IT LIVES INSIDE `app/Finance` BECAUSE THE ARCH RULE IS RIGHT. `App\Finance\Services` is private to
 * the module (tests/Arch/ArchitectureBoundaryTest.php, "Finance services are private to the Finance
 * module"), so binding them from `AppServiceProvider` would have made the whole of Finance's service
 * layer visible at the application root — one `use` statement at a time, each individually harmless.
 * The provider is registered from `bootstrap/app.php`, which names the CLASS and never its
 * dependencies, so the boundary holds.
 *
 * Both Paystack services take the secret as a constructor scalar, which the container cannot
 * autowire, and both REFUSE TO CONSTRUCT on an empty one. These bindings are what make that refusal
 * reachable: without them the container throws an unresolvable-primitive error instead, which reads
 * as a wiring bug rather than as "the key is missing" — the wrong diagnosis to hand someone on a
 * webhook path while payments queue up unverified.
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PaystackSignature::class,
            fn () => new PaystackSignature(config('services.paystack.secret_key')),
        );

        $this->app->bind(
            PaystackClient::class,
            fn () => new PaystackClient(
                config('services.paystack.secret_key'),
                (string) config('services.paystack.base_url'),
            ),
        );
    }
}
