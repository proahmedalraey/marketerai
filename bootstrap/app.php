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

        // عزل المستأجر: CurrentBrand يُضبط قبل ربط النماذج بالمسار ({contentItem}، {mediaAsset}،
        // {product}…)، وإلا جاء نطاق BelongsToBrand فارغاً فيُفتح ويُعدَّل ويُحذف صف أي علامة بمعرّفه.
        // موضعه في قائمة الأولوية بعد المصادقة، فيبقى توجيهه لـlogin وbrand.profile كما هو.
        // (tests/Feature/BrandRouteBindingIsolationTest.php و BrandIsolationBindingTest.php)
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureBrandIsReady::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
