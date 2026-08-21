<?php

namespace Voyager\Cache;

use Closure;
use Voyager\Contracts\Cache\Factory as FactoryContract;
use Voyager\Contracts\Cache\Store;
use Voyager\Contracts\Events\Dispatcher as DispatcherContract;
use Voyager\NutsAndBolts\DataObjects\Arr;
use InvalidArgumentException;
use Mockery;
use Mockery\LegacyMockInterface;

/**
 * @mixin \Voyager\Cache\Repository
 * @mixin \Voyager\Contracts\Cache\LockProvider
 */
class CacheManager implements FactoryContract
{
    /**
     * The application instance.
     *
     * @var \Voyager\Contracts\System\Application
     */
    protected $app;

    /**
     * The array of resolved cache stores.
     *
     * @var array
     */
    protected $stores = [];

    /**
     * The registered custom driver creators.
     *
     * @var array
     */
    protected $customCreators = [];

    /**
     * Create a new Cache manager instance.
     *
     * @param  \Voyager\Contracts\System\Application  $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Get a cache store instance by name, wrapped in a repository.
     *
     * @param  string|null  $name
     * @return \Voyager\Contracts\Cache\Repository
     */
    public function store($name = null)
    {
        $name = $name ?? $this->getDefaultDriver();

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Get a cache driver instance.
     *
     * @param  string|null  $driver
     * @return \Voyager\Contracts\Cache\Repository
     */
    public function driver($driver = null)
    {
        return $this->store($driver);
    }

    /**
     * Get a memoized cache driver instance.
     *
     * @param  string|null  $driver
     * @return \Voyager\Contracts\Cache\Repository
     */
    public function memo($driver = null)
    {
        $driver = $driver ?? $this->getDefaultDriver();

        $bindingKey = "cache.__memoized:{$driver}";

        $isSpy = isset($this->app['cache']) && $this->app['cache'] instanceof LegacyMockInterface;

        $this->app->scopedIf($bindingKey, function () use ($driver, $isSpy) {
            $repository = $this->repository(
                new MemoizedStore($driver, $this->store($driver)), ['events' => false]
            );

            return $isSpy ? Mockery::spy($repository) : $repository;
        });

        return $this->app->make($bindingKey);
    }

    /**
     * Resolve the given store.
     *
     * @param  string  $name
     * @return \Voyager\Contracts\Cache\Repository
     *
     * @throws \InvalidArgumentException
     */
    public function resolve($name)
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Cache store [{$name}] is not defined.");
        }

        $config = Arr::add($config, 'store', $name);

        return $this->build($config);
    }

    /**
     * Build a cache repository with the given configuration.
     *
     * @param  array  $config
     * @return \Voyager\Cache\Repository
     *
     * @throws \InvalidArgumentException
     */
    public function build(array $config)
    {
        $config = Arr::add($config, 'store', $config['name'] ?? 'ondemand');

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array  $config
     * @return mixed
     */
    protected function callCustomCreator(array $config)
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create an instance of the APC cache driver.
     *
     * @param  array  $config
     * @return \Voyager\Cache\Repository
     */
    protected function createApcDriver(array $config)
    {
        $prefix = $this->getPrefix($config);

        return $this->repository(new ApcStore(new ApcWrapper, $prefix), $config);
    }

    /**
     * Create an instance of the array cache driver.
     *
     * @param  array  $config
     * @return \Voyager\Cache\Repository
     */
    protected function createArrayDriver(array $config)
    {
        return $this->repository(new ArrayStore(
            $config['serialize'] ?? false,
            $this->getSerializableClasses($config),
        ), $config);
    }

    /**
     * Create an instance of the failover cache driver.
     *
     * @param  array  $config
     * @return \Voyager\Cache\Repository
     */
    protected function createFailoverDriver(array $config)
    {
        return $this->repository(new FailoverStore(
            $this,
            $this->app->make(DispatcherContract::class),
            $config['stores']
        ), ['events' => false, ...$config]);
    }

    /**
     * Create an instance of the file cache driver.
     *
     * @param  array  $config
     * @return \Voyager\Cache\Repository
     */
    protected function createFileDriver(array $config)
    {
        return $this->repository(
            (new FileStore(
                $this->app['files'],
                $config['path'],
                $config['permission'] ?? null,
                $this->getSerializableClasses($config),
            ))
                ->setLockDirectory($config['lock_path'] ?? null),
            $config
        );
    }

    /**
     * Create an instance of the Null cache driver.
     *
     * @return \Voyager\Cache\Repository
     */
    protected function createNullDriver()
    {
        return $this->repository(new NullStore, []);
    }

    /**
     * Create an instance of the Redis cache driver.
     *
     * @param  array  $config
     * @return \Voyager\Cache\Repository
     */
    protected function createRedisDriver(array $config)
    {
        $redis = $this->app['redis'];

        $connection = $config['connection'] ?? 'default';

        $store = new RedisStore(
            $redis,
            $this->getPrefix($config),
            $connection,
            $this->getSerializableClasses($config),
        );

        return $this->repository(
            $store->setLockConnection($config['lock_connection'] ?? $connection),
            $config
        );
    }

    /**
     * Get the session store implementation.
     *
     * @return \Voyager\Contracts\Session\Session
     *
     * @throws \InvalidArgumentException
     */
    protected function getSession()
    {
        $session = $this->app['session'] ?? null;

        if (! $session) {
            throw new InvalidArgumentException('Session store requires session manager to be available in container.');
        }

        return $session;
    }

    /**
     * Create a new cache repository with the given implementation.
     *
     * @param  \Voyager\Contracts\Cache\Store  $store
     * @param  array  $config
     * @return \Voyager\Cache\Repository
     */
    public function repository(Store $store, array $config = [])
    {
        return tap(new Repository($store, Arr::only($config, ['store'])), function ($repository) use ($config) {
            if ($config['events'] ?? true) {
                $this->setEventDispatcher($repository);
            }
        });
    }

    /**
     * Set the event dispatcher on the given repository instance.
     *
     * @param  \Voyager\Cache\Repository  $repository
     * @return void
     */
    protected function setEventDispatcher(Repository $repository)
    {
        if (! $this->app->bound(DispatcherContract::class)) {
            return;
        }

        $repository->setEventDispatcher(
            $this->app[DispatcherContract::class]
        );
    }

    /**
     * Re-set the event dispatcher on all resolved cache repositories.
     *
     * @return void
     */
    public function refreshEventDispatcher()
    {
        array_map($this->setEventDispatcher(...), $this->stores);
    }

    /**
     * Get the cache prefix.
     *
     * @param  array  $config
     * @return string
     */
    protected function getPrefix(array $config)
    {
        return $config['prefix'] ?? $this->app['config']['cache.prefix'];
    }

    /**
     * Get the classes that should be allowed during unserialization.
     *
     * @param  array  $config
     * @return array|bool|null
     */
    protected function getSerializableClasses(array $config)
    {
        return $this->app['config']['cache.serializable_classes'] ?? null;
    }

    /**
     * Get the cache connection configuration.
     *
     * @param  string  $name
     * @return array|null
     */
    protected function getConfig($name)
    {
        return $name !== 'null'
            ? $this->app['config']["cache.stores.{$name}"]
            : ['driver' => 'null'];
    }

    /**
     * Get the default cache driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        return $this->app['config']['cache.default'] ?? 'null';
    }

    /**
     * Set the default cache driver name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultDriver($name)
    {
        $this->app['config']['cache.default'] = $name;
    }

    /**
     * Unset the given driver instances.
     *
     * @param  array|string|null  $name
     * @return $this
     */
    public function forgetDriver($name = null)
    {
        $name ??= $this->getDefaultDriver();

        foreach ((array) $name as $cacheName) {
            if (isset($this->stores[$cacheName])) {
                unset($this->stores[$cacheName]);
            }
        }

        return $this;
    }

    /**
     * Disconnect the given driver and remove from local cache.
     *
     * @param  string|null  $name
     * @return void
     */
    public function purge($name = null)
    {
        $name ??= $this->getDefaultDriver();

        unset($this->stores[$name]);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver
     * @param  \Closure  $callback
     *
     * @param-closure-this  $this  $callback
     *
     * @return $this
     */
    public function extend($driver, Closure $callback)
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     *
     * @param  \Voyager\Contracts\System\Application  $app
     * @return $this
     */
    public function setApplication($app)
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->store()->$method(...$parameters);
    }
}
