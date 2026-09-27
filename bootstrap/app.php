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

        // العلامة الحالية قبل ربط النماذج بالمسار ({contentItem}، {mediaAsset}…): النطاق العام
        // BelongsToBrand يقرأ CurrentBrand، وبدونه يُربط محتوى أي علامة بمعرّفه فيُفتح
        // ويُعدّل ويُحذف من حساب آخر (tests/Feature/BrandIsolationBindingTest.php)
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureBrandIsReady::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
