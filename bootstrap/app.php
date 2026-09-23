<?php

use App\Http\Middleware\ApiResponseHeaders;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', ApiResponseHeaders::class);
        $middleware->alias(['role' => RoleMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof ValidationException
                || $e instanceof AuthenticationException || $e instanceof AuthorizationException) {
                return null;
            }
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                $status = $e->getStatusCode();

                return response()->json(['message' => $status === 404 ? 'Ressource introuvable.' : ($e->getMessage() ?: 'Requête refusée.')], $status, $e->getHeaders());
            }

            return response()->json(['message' => 'Erreur interne du serveur.'], 500);
        });
    })->create();
