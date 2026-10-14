<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Single place that maps exceptions to the API error envelope:
 *   { "success": false, "message": "...", "errors": { ... } }
 * Never leaks SQL, stack traces or class names. Unexpected errors are still reported/logged by Laravel.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Expected business errors are not bugs; keep them out of the error log.
        $exceptions->dontReport([ApiException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function toResponse(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->getMessage(), $e->status(), $e->errors(), $e->headers()),
            $e instanceof ValidationException => ApiResponse::error('The given data was invalid.', 422, $e->errors()),
            $e instanceof AuthenticationException => ApiResponse::error('Unauthenticated.', 401),
            $e instanceof AuthorizationException => ApiResponse::error($e->getMessage() ?: 'This action is unauthorized.', 403),
            // Two requests raced past validation; the database unique key is the final arbiter.
            $e instanceof UniqueConstraintViolationException => ApiResponse::error('A record with the same unique value already exists.', 409),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error('Resource not found.', 404),
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'Too many requests. Please try again later.',
                429,
                isset($e->getHeaders()['Retry-After']) ? ['retry_after' => (int) $e->getHeaders()['Retry-After']] : [],
                $e->getHeaders(),
            ),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => ApiResponse::error(
                self::safeHttpMessage($e->getStatusCode(), $e->getMessage()),
                $e->getStatusCode(),
                [],
                $e->getHeaders(),
            ),
            default => ApiResponse::error('Something went wrong. Please try again.', 500),
        };
    }

    private static function safeHttpMessage(int $status, string $message): string
    {
        return match ($status) {
            403 => 'This action is unauthorized.',
            405 => 'Method not allowed.',
            default => $message !== '' ? $message : 'Request could not be processed.',
        };
    }
}
