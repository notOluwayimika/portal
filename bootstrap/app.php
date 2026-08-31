<?php

use App\Exceptions\DutySeparationViolationException;
use App\Finance\Console\AuditLedgerCoherence;
use App\Finance\Console\CapturePaystackSandbox;
use App\Finance\Console\ImportOpeningBalances;
use App\Finance\Console\ReconcileAccounts;
use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\DenyGuardianBulkRecords;
use App\Http\Middleware\EnsureGuardianOwnsGuardianRecord;
use App\Http\Middleware\EnsureGuardianOwnsStudent;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetSchoolContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Finance module commands live in App\Finance\Console (the arch boundary keeps
    // Finance models private, so a command touching them cannot sit in
    // app/Console/Commands). Auto-discovery only scans app/Console/Commands, so the
    // module's commands are registered explicitly here.
    ->withCommands([
        ReconcileAccounts::class,
        AuditLedgerCoherence::class,
        CapturePaystackSandbox::class,
        ImportOpeningBalances::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // statefulApi() already handles EnsureFrontendRequestsAreStateful
        // DO NOT add it manually to api() as well
        $middleware->statefulApi();

        $middleware->web(append: [
            SetSchoolContext::class,
            // C7: after SetSchoolContext per the planned slot (ADR 0043 §3);
            // the requirement read is team-agnostic, so the ordering is not
            // load-bearing for correctness (c7-brief D1).
            EnsureTwoFactorEnrolled::class,
            // IMMEDIATELY after the 2FA gate, and this ordering IS load-bearing:
            // EnsureTwoFactorEnrolled reads $request->user(), so swapping the
            // user first would evaluate 2FA against the impersonated TARGET —
            // letting an operator enrol 2FA on someone else's account. Before
            // Inertia, so shared props resolve as the target. See the class.
            ApplyImpersonation::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            SetSchoolContext::class,
            EnsureTwoFactorEnrolled::class,
            // statefulApi() is enabled, so the SPA's /api calls carry the same
            // session — one mechanism impersonates both transports. Pure token
            // clients have no session and are never impersonated, which is the
            // behaviour we want. Same slot, same reason as the web group.
            ApplyImpersonation::class,
        ]);

        $middleware->alias([
            'tenant' => SetSchoolContext::class,
            'role' => EnsureRole::class,
            // Relationship, not ability: a parent may only address a student
            // they own. Attached BY NAME to every route carrying a student-owned
            // binding that the guardian role can reach, so the protection is
            // visible in `route:list` rather than buried in eight closures. See
            // the class.
            'guardian_ward' => EnsureGuardianOwnsStudent::class,
            // No relationship to check: the response covers a whole cohort, so
            // there is no student a parent could own. Attached BY NAME to the
            // three bulk-record routes the guardian role can reach. See the class.
            'guardian_no_bulk' => DenyGuardianBulkRecords::class,
            // Identity, not custody: a parent may address their OWN guardian row
            // and no other. Resolved server-side, never trusted from the request.
            'guardian_self' => EnsureGuardianOwnsGuardianRecord::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $e) {
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

        // User-level segregation-of-duties refusal (User::assignRole guard, Finance pairs only). A
        // well-formed request that the domain forbids — the same 422 shape as a validation failure,
        // carrying the exception's own actionable message (names user, pair, roles; Decision 2). The
        // 'roles' key matches the role-sync form field so the message renders inline there.
        $exceptions->renderable(function (DutySeparationViolationException $e, $request) {
            $errors = ['roles' => [$e->getMessage()]];

            if ($request->is('api/*')) {
                return response()->validation_error($errors);
            }

            return redirect()->back()->withErrors($errors)->withInput();
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
            return response()->not_found(class_basename($e->getModel()).' not found');
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
            // Illuminate\Http\Client\ConnectionException — an OUTBOUND HTTP dependency did not answer. That is
            // not a client error (503, not 400), and $e->getMessage() carries the outbound host/port/URL, so
            // the response body is a fixed generic string; the full message is still logged by the reporter.
            return response()->service_unavailable('A required service is unavailable. Please try again shortly.');
        });

        /*
        |----------------------------------------------------
        | Database
        |----------------------------------------------------
        */
        $exceptions->renderable(function (QueryException $e) {
            // errorInfo[1] is the MySQL DRIVER numeric code; errorInfo[0] is the SQLSTATE string. The previous
            // handler matched errorInfo[1] against SQLSTATEs (23000, '40001') and a SQL Server FK code (547) —
            // none of which errorInfo[1] can ever hold, and `match` compares with === so an int never equals a
            // string — so ALL THREE arms were dead and EVERY database error fell to a 400. This casts to int
            // per the house convention every other errorInfo consumer follows (TermController,
            // CurriculumController, GenerateInvoice, CreateFeeSchedule, ApproveDiscountPolicyChange).
            //
            // renderable() overrides HTTP rendering only — the default reporter (and the report() callback
            // above) still LOG the full exception; none of this suppresses that.
            return match ((int) ($e->errorInfo[1] ?? 0)) {
                1062 => response()->conflict('Duplicate entry detected.'),                       // ER_DUP_ENTRY
                1451 => response()->conflict('This record has dependent records and cannot be changed.'), // ER_ROW_IS_REFERENCED_2
                1205, 1213 => response()->conflict('The database is busy; please retry.'),       // ER_LOCK_WAIT_TIMEOUT / ER_LOCK_DEADLOCK — transient, retryable
                // 1452 (child → missing parent: a bug, the documented incident — see StudentSubjectCommentAuthorTest),
                // 1644 (SIGNAL '45000' from a house invariant trigger), 3819 (CHECK), and any unclassified failure
                // (connection 2002/2006, syntax, …) are SERVER faults, not bad requests: reaching an invariant
                // backstop means the application-layer guard that should run first is MISSING, and ops must SEE
                // it (a ledger-tamper trigger returning 400 never shows on a 5xx alert). Fixed generic message,
                // NEVER $e->getMessage() — that leaks table/column names and SQL fragments.
                default => response()->server_error('A database error occurred.'),
            };
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
