<?php

namespace App\Providers;

use App\Services\AI\AiManager;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\Settings\AiSettings;
use App\Support\JobSummary;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiManager::class);
        $this->app->singleton(CreditService::class);
        $this->app->singleton(AiSettings::class);
    }

    public function boot(): void
    {
        // إعدادات المنصة (مفاتيح الذكاء الاصطناعي وغيرها) لمدير المنصة وحده
        Gate::define('manage-platform', fn (User $user) => (bool) $user->is_admin);

        /*
         * لوحة «إنتاجاتي» في كل صفحة: العمليات تُطلق من صفحة وتنتهي والتاجر في غيرها،
         * فمكان متابعتها ليس صفحة بعينها. الاستعلام هنا لا في العرض، ولا يُنفَّذ
         * إلا حين تُرسم اللوحة فعلاً (مستخدم داخل علامة).
         */
        View::composer('partials.operations', function ($view) {
            $view->with('operations', JobSummary::collect(GenerationJob::recent()->get()));
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
