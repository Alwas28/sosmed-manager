<?php

use App\Http\Middleware\EnsureApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API memakai sesi web Laravel yang sama dengan Livewire, sehingga
        // cookie sesi harus tersedia di route /api/* (login, me, logout).
        $middleware->api(append: [StartSession::class]);

        $middleware->alias(['api.key' => EnsureApiKey::class]);
        $middleware->validateCsrfTokens(except: ['api/*']);
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : route('login'),
        );
        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : route('dashboard'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
