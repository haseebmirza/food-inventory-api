<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
        $middleware->trustProxies(at: ['*']);
        $middleware->append(TrackRequest::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $error instanceof ValidationException => 422,
                $error instanceof AuthenticationException => 401,
                $error instanceof UniqueConstraintViolationException => 409,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 500,
            };
            $message = match (true) {
                $status >= 500 => 'An internal error occurred.',
                $status === 404 => 'Resource not found.',
                $error instanceof UniqueConstraintViolationException => 'The record already exists.',
                default => $error->getMessage() ?: 'Request failed.',
            };
            $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];

            return response()->json(['message' => $message, 'errors' => $error instanceof ValidationException ? $error->errors() : (object) []], $status, $headers);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
