<?php

namespace App\Providers;

use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response as StatusCode;

/**
 * Class ResponseMacroProvider
 */
class ResponseMacroProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Response macros

        Response::macro('ok', function (mixed $data, $headers = []) {
            $data = is_string($data) ? ['message' => $data] : $data;

            return Response::json($data, StatusCode::HTTP_OK, $headers);
        });

        Response::macro('success', function ($data, $headers = []) {
            return Response::json(['data' => $data], StatusCode::HTTP_OK, $headers);
        });

        Response::macro('created', function (mixed $data, $headers = []) {
            $data = is_string($data) ? ['message' => $data] : $data;

            return Response::json($data, StatusCode::HTTP_CREATED, $headers);
        });

        Response::macro('no_content', function ($headers = []) {
            return Response::noContent();
        });

        Response::macro('error', function ($error, $headers = []) {
            return Response::json(['message' => $error], StatusCode::HTTP_BAD_REQUEST, $headers);
        });

        /*
         * Business-rule / validation failures: 422, app-wide.
         *
         * DECIDED 2026-07-21 (was 400). 422 is HTTP-correct for a well-formed but
         * semantically invalid request, it is Laravel's own default, and every test
         * author in this repo reached for it independently — the app was the outlier.
         *
         * This is the ONLY production caller's status: bootstrap/app.php renders
         * ValidationException through here. A genuine `abort(400)` would be untouched
         * by this change — there are none in app/, so no legitimate 400 exists to
         * preserve.
         *
         * The `errors` key is returned, not just logged. Dropping it made every 422 a
         * dead end for the client, and the frontend already depends on it:
         * student-form.tsx and add-standalone-guardian-modal.tsx both branch on
         * `status === 422` and read `response.data.errors`, while score-entry-page,
         * pending-reviews and subject-result-status-panel read
         * `response.data.errors.<field>[0]`. All of that was unreachable code — the
         * status never matched and the payload was never sent.
         */
        Response::macro('validation_error', function ($errors, $message = 'There are validation errors', $headers = []) {
            \Log::error('Validation errors occurred:', ['errors' => $errors]);

            return Response::json(
                ['message' => $message, 'errors' => $errors],
                StatusCode::HTTP_UNPROCESSABLE_ENTITY,
                $headers,
            );
        });

        Response::macro('unauthorized', function ($error, $headers = []) {
            return Response::json(['message' => $error], StatusCode::HTTP_UNAUTHORIZED, $headers);
        });

        Response::macro('not_allowed', function ($error, $headers = []) {
            return Response::json(['message' => $error], StatusCode::HTTP_METHOD_NOT_ALLOWED, $headers);
        });

        Response::macro('payment_required', function ($error, $headers = []) {
            return Response::json(['message' => $error], StatusCode::HTTP_PAYMENT_REQUIRED, $headers);
        });

        Response::macro('upx_entity', function ($error, $headers = []) {
            return Response::json(['message' => $error], StatusCode::HTTP_UNPROCESSABLE_ENTITY, $headers);
        });

        Response::macro('forbidden', function ($error, $headers = []) {
            return Response::json(['message' => $error], StatusCode::HTTP_FORBIDDEN, $headers);
        });

        Response::macro('found', function ($data = null, $headers = []) {
            return Response::json(['message' => $data ?? 'Resource Found'], StatusCode::HTTP_FOUND, $headers);
        });

        Response::macro('not_found', function ($data = null, $headers = []) {
            return Response::json(['message' => $data ?? 'Resource not found'], StatusCode::HTTP_NOT_FOUND, $headers);
        });

        Response::macro('server_error', function ($error, $additional = [], $headers = []) {
            return Response::json(['message' => $error, ...$additional], StatusCode::HTTP_INTERNAL_SERVER_ERROR, $headers);
        });

        Response::macro('too_many_requests', function ($data = null, $headers = []) {
            return Response::json(['message' => $data ?? 'Too many requests'], StatusCode::HTTP_TOO_MANY_REQUESTS, $headers);
        });

        Response::macro('conflict', function ($data = null, $headers = []) {
            return Response::json(['message' => $data ?? 'Conflict'], StatusCode::HTTP_CONFLICT, $headers);
        });

        Response::macro('service_unavailable', function ($error, $additional = [], $headers = []) {
            return Response::json(['message' => $error, ...$additional], StatusCode::HTTP_SERVICE_UNAVAILABLE, $headers);
        });

        Response::macro('account_locked', function ($error, $additional = [], $headers = []) {
            return Response::json(['message' => $error, ...$additional], StatusCode::HTTP_LOCKED, $headers);
        });
    }
}
