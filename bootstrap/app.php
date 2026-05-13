<?php
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Mailer\Exception\TransportException;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // statefulApi() already handles EnsureFrontendRequestsAreStateful
        // DO NOT add it manually to api() as well
        $middleware->statefulApi();

        $middleware->web(append: [
            SetTenantContext::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            SetTenantContext::class
        ]);

        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $e) {
            \Log::error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        });

        /*
        |----------------------------------------------------
        | Validation
        |----------------------------------------------------
        */
        $exceptions->renderable(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->validation_error($e->errors());
            }

            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        });

        /*
        |----------------------------------------------------
        | Authentication / Authorization
        |----------------------------------------------------
        */
        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->unauthorized('Unauthenticated.');
            }

            return redirect()->route('login');
        });

        $exceptions->renderable(function (AuthorizationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->forbidden($e->getMessage());
            }

            abort(403);
        });

        /*
        |----------------------------------------------------
        | Not Found
        |----------------------------------------------------
        */
        $exceptions->renderable(function (ModelNotFoundException $e) {
            return response()->not_found(class_basename($e->getModel()) . ' not found');
        });

        $exceptions->renderable(function (NotFoundHttpException $e) {
            return response()->not_found('Resource not found');
        });

        $exceptions->renderable(function (RouteNotFoundException $e) {
            return response()->not_found();
        });

        /*
        |----------------------------------------------------
        | HTTP / Request Errors
        |----------------------------------------------------
        */
        $exceptions->renderable(function (MethodNotAllowedHttpException $e) {
            return response()->error('HTTP method not allowed');
        });

        $exceptions->renderable(function (ConnectionException $e) {
            return response()->error($e->getMessage());
        });

        /*
        |----------------------------------------------------
        | Database
        |----------------------------------------------------
        */
        $exceptions->renderable(function (QueryException $e) {
            return handleDatabase($e);
        });

        /*
        |----------------------------------------------------
        | Mail
        |----------------------------------------------------
        */
        $exceptions->renderable(function (TransportException $e) {
            Log::error('Mail error', [
                'message' => $e->getMessage(),
            ]);

            if (str_contains($e->getMessage(), 'getaddrinfo')) {
                return response()->server_error(
                    'Cannot connect to mail server',
                    additional: ['code' => 'MAIL_CONN_001']
                );
            }

            return response()->service_unavailable('Mail service unavailable');
        });
    })->create();

/*
|--------------------------------------------------------
| DB handler helper (kept outside for clarity)
|--------------------------------------------------------
*/
function handleDatabase(QueryException $e)
{
    $code = $e->errorInfo[1] ?? null;

    return match ($code) {
        23000 => response()->conflict('Duplicate entry detected'),
        547 => response()->error('Record has dependencies'),
        '40001' => response()->error('Transaction conflict, retry'),
        default => response()->error('Database error'),
    };
}
