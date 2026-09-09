<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            if (app()->isProduction()) {
                Log::warning("N+1: Lazy loading {$relation} on ".get_class($model));

                return;
            }

            throw new LazyLoadingViolationException($model, $relation);
        });
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(config('api.throttle.auth_per_ip'))->by('auth-ip:'.$request->ip()),
            Limit::perMinute(config('api.throttle.auth_per_account'))->by('auth-account:'.hash('sha256', mb_strtolower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip())),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('api.throttle.api_per_user'))->by((string) $request->user()->id));
    }
}
