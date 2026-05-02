<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response as StatusCode;
use Illuminate\Support\Facades\Response;

/**
 * Class ResponseMacroProvider
 * @package App\Providers
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

        Response::macro('validation_error', function ($errors, $message = 'There are validation errors', $headers = []) {
            return Response::json(['message' => $message], StatusCode::HTTP_BAD_REQUEST, $headers);
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
