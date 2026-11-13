<?php

use App\Http\Middleware\ApiLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

  ->withMiddleware(function (Middleware $middleware) {

    $middleware->statefulApi();


  $middleware->api(prepend: [
    ApiLogger::class,
]);


    $middleware->alias([
        'user.status' => \App\Http\Middleware\CheckUserStatus::class,
        'product.access' => \App\Http\Middleware\ProductAccessMiddleware::class,
        'permission' => \App\Http\Middleware\CheckPermission::class,
    ]);
})

    ->withExceptions(function (Exceptions $exceptions) {

        /*
        |---------------------------------------
        | Force JSON for API
        |---------------------------------------
        */
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*');
        });

        /*
        |---------------------------------------
        | Validation Error
        |---------------------------------------
        */
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        });

        /*
        |---------------------------------------
        | Authentication Error
        |---------------------------------------
        */
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please login first.',
                'errors' => null,
            ], 401);
        });

        /*
        |---------------------------------------
        | Authorization Error
        |---------------------------------------
        */
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to perform this action.',
                'errors' => null,
            ], 403);
        });

        /*
        |---------------------------------------
        | Model Not Found
        |---------------------------------------
        */
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            $model = class_basename($e->getModel());

            return response()->json([
                'success' => false,
                'message' => "{$model} not found.",
                'errors' => null,
            ], 404);
        });

        /*
        |---------------------------------------
        | Route Not Found
        |---------------------------------------
        */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'The requested endpoint does not exist.',
                'errors' => null,
            ], 404);
        });

        /*
        |---------------------------------------
        | Method Not Allowed
        |---------------------------------------
        */
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'HTTP method not allowed.',
                'errors' => null,
            ], 405);
        });

        /*
        |---------------------------------------
        | Too Many Requests
        |---------------------------------------
        */
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
                'errors' => $retryAfter ? ['retry_after' => (int) $retryAfter] : null,
            ], 429);
        });

        /*
        |---------------------------------------
        | Generic HTTP Exceptions (401, 403, etc.)
        |---------------------------------------
        */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'An HTTP error occurred.',
                'errors' => null,
            ], $e->getStatusCode());
        });

        /*
        |---------------------------------------
        | Fallback — Unhandled Exceptions (500)
        |---------------------------------------
        */
        $exceptions->render(function (Throwable $e, Request $request) {
            // In local/staging: expose full details for debugging
            // In production: hide internals
            $errors = app()->isLocal() ? [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] : null;

            return response()->json([
                'success' => false,
                'message' => app()->isLocal()
                    ? $e->getMessage()
                    : 'Something went wrong. Please try again later.',
                'errors' => $errors,
            ], 500);
        });
    })

    ->create();
