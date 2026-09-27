<?php

use App\Http\Middleware\EnsureBrandIsReady;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'brand.ready' => EnsureBrandIsReady::class,
        ]);

        // عزل المستأجر: CurrentBrand يُضبط قبل ربط {mediaAsset} و{product}... وإلا جُلب
        // صف أي علامة، لأن نطاق BelongsToBrand لا يضيف شرطاً والبراند فارغ.
        // موضعه في قائمة الأولوية بعد المصادقة، فيبقى توجيهه لـlogin وbrand.profile كما هو.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureBrandIsReady::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
