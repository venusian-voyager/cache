<?php

namespace Voyager\Cache;

use Voyager\Contracts\NutsAndBolts\DeferrableProvider;
use Voyager\Filesystem\Filesystem;
use Voyager\NutsAndBolts\ServiceProvider;

class CacheServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if (! $this->app->isBound('files')) {
            $this->app->registerSingleton('files', fn () => new Filesystem);
        }

        $this->app->registerSingleton('cache', function ($app) {
            return new CacheManager($app);
        });

        $this->app->registerSingleton('cache.store', function ($app) {
            return $app['cache']->driver();
        });

        $this->app->registerSingleton(RateLimiter::class, function ($app) {
            return new RateLimiter($app->make('cache')->driver(
                $app['config']->get('cache.limiter')
            ));
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            'cache', 'cache.store', RateLimiter::class,
        ];
    }
}
