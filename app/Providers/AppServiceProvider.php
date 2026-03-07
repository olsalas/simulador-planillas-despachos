<?php

namespace App\Providers;

use App\Contracts\RoutingProvider;
use App\Domain\Routing\Providers\HereRoutingProvider;
use App\Domain\Routing\Providers\MockRoutingProvider;
use App\Domain\Routing\Support\FlexiblePolylineDecoder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FlexiblePolylineDecoder::class, fn () => new FlexiblePolylineDecoder());

        $this->app->bind(HereRoutingProvider::class, function ($app) {
            return new HereRoutingProvider(
                config('services.here.api_key'),
                $app->make(FlexiblePolylineDecoder::class),
            );
        });

        $this->app->bind(MockRoutingProvider::class, fn () => new MockRoutingProvider());

        $this->app->bind(RoutingProvider::class, function ($app) {
            $configuredProvider = (string) config('routing.provider', 'auto');
            $hasHereKey = filled(config('services.here.api_key'));

            if (($configuredProvider === 'auto' || $configuredProvider === 'here') && $hasHereKey) {
                return $app->make(HereRoutingProvider::class);
            }

            if ($configuredProvider === 'here' && ! $hasHereKey) {
                Log::warning('ROUTING_PROVIDER is set to here but HERE_API_KEY is missing. Falling back to mock provider.');
            }

            return $app->make(MockRoutingProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('upload-csv', fn (User $user): bool => $user->can_upload_csv);

        Vite::prefetch(concurrency: 3);
    }
}
