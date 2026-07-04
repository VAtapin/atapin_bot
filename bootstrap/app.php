<?php

use App\Http\Middleware\AuthorizeFamilyMedia;
use App\Http\Middleware\CacheFamilyReadResponse;
use App\Http\Middleware\CaptureTrafficAttribution;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\ResolveFamilyTree;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyTelegramMiniApp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            ResolveCustomDomain::class,
        ]);
        $middleware->web(append: [
            SetLocale::class,
            CaptureTrafficAttribution::class,
        ]);
        $middleware->alias([
            'family.tree' => ResolveFamilyTree::class,
            'telegram.webapp' => VerifyTelegramMiniApp::class,
            'family.media' => AuthorizeFamilyMedia::class,
            'family.cache' => CacheFamilyReadResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
