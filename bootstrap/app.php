<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A merchant's subdomain switches the request to its own database before anything reads it
        $middleware->prepend(\App\Http\Middleware\ResolveMerchant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
