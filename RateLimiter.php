<?php

namespace Voyager\Cache;

use Closure;
use Voyager\Contracts\Cache\Repository as Cache;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Redis\Connections\PhpRedisConnection;
use Voyager\NutsAndBolts\Collection;
use Voyager\NutsAndBolts\Concerns\InteractsWithTime;

use function Voyager\NutsAndBolts\Helpers\enum_value;

class RateLimiter
{
    use InteractsWithTime;

    /**
     * The cache store implementation.
     *
     * @var \Voyager\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * The configured limit object resolvers.
     *
     * @var array
     */
    protected $limiters = [];

    /**
     * Cleaned key => how many async limiter calls on it are in flight.
     *
     * @var array<string, int>
     */
    private array $awaiting = [];

    /** The loop those async calls run on. */
    private ?Loop $awaiting_loop = null;

    /**
     * Create a new rate limiter instance.
     *
     * @param  \Voyager\Contracts\Cache\Repository  $cache
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Register a named rate limiter configuration.
     *
     * @param  \UnitEnum|string  $name
     * @param  \Closure  $callback
     * @return $this
     */
    public function for($name, Closure $callback)
    {
        $resolvedName = $this->resolveLimiterName($name);

        $this->limiters[$resolvedName] = $callback;

        return $this;
    }

    /**
     * Get the given named rate limiter.
     *
     * @param  \UnitEnum|string  $name
     * @return \Closure|null
     */
    public function limiter($name)
    {
        $resolvedName = $this->resolveLimiterName($name);

        $limiter = $this->limiters[$resolvedName] ?? null;

        if (! is_callable($limiter)) {
            return;
        }

        return function (...$args) use ($limiter) {
            $result = $limiter(...$args);

            if (! is_array($result)) {
                return $result;
            }

            $duplicates = (new Collection($result))->duplicates('key');

            if ($duplicates->isEmpty()) {
                return $result;
            }

            foreach ($result as $limit) {
                if ($duplicates->contains($limit->key)) {
                    $limit->key = $limit->fallbackKey();
                }
            }

            return $result;
        };
    }

    /**
     * This limiter's operations as promises, over the cache's async operations.
     *
     * @return \Voyager\Cache\AsyncRateLimiter
     *
     * @throws \RuntimeException the cache has no async operations
     */
    public function async(): AsyncRateLimiter
    {
        if (! $this->cache instanceof Repository) {
            throw new \RuntimeException('Only a Voyager cache repository has async operations: this limiter\'s cache is '.get_class($this->cache).'.');
        }

        return new AsyncRateLimiter($this, $this->cache, $this->cache->async()->loop());
    }

    /**
     * Attempts to execute a callback if it's not limited.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @param  \Closure  $callback
     * @param  \DateTimeInterface|\DateInterval|int  $decaySeconds
     * @return mixed
     */
    public function attempt($key, $maxAttempts, Closure $callback, $decaySeconds = 60)
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        if (is_null($result = $callback())) {
            $result = true;
        }

        return tap($result, function () use ($key, $decaySeconds) {
            $this->hit($key, $decaySeconds);
        });
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return bool
     */
    public function tooManyAttempts($key, $maxAttempts)
    {
        $this->settleAwaited($key);

        if ($this->attempts($key) >= $maxAttempts) {
            if ($this->cacheHas($this->cleanRateLimiterKey($key).':timer')) {
                return true;
            }

            $this->resetAttempts($key);
        }

        return false;
    }

    /**
     * Increment (by 1) the counter for a given key for a given decay time.
     *
     * @param  string  $key
     * @param  \DateTimeInterface|\DateInterval|int  $decaySeconds
     * @return int
     */
    public function hit($key, $decaySeconds = 60)
    {
        return $this->increment($key, $decaySeconds);
    }

    /**
     * Increment the counter for a given key for a given decay time by a given amount.
     *
     * @param  string  $key
     * @param  \DateTimeInterface|\DateInterval|int  $decaySeconds
     * @param  int  $amount
     * @return int
     */
    public function increment($key, $decaySeconds = 60, $amount = 1)
    {
        $this->settleAwaited($key);

        $key = $this->cleanRateLimiterKey($key);

        $this->cacheAdd(
            $key.':timer', $this->availableAt($decaySeconds), $decaySeconds
        );

        $added = $this->withoutSerializationOrCompression(
            fn () => $this->cacheAdd($key, 0, $decaySeconds)
        );

        $hits = (int) $this->cacheIncrement($key, $amount);

        if (! $added && $hits == $amount) {
            $this->withoutSerializationOrCompression(
                fn () => $this->cachePut($key, $amount, $decaySeconds)
            );
        }

        return $hits;
    }

    /**
     * Decrement the counter for a given key for a given decay time by a given amount.
     *
     * @param  string  $key
     * @param  \DateTimeInterface|\DateInterval|int  $decaySeconds
     * @param  int  $amount
     * @return int
     */
    public function decrement($key, $decaySeconds = 60, $amount = 1)
    {
        return $this->increment($key, $decaySeconds, $amount * -1);
    }

    /**
     * Get the number of attempts for the given key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function attempts($key)
    {
        $this->settleAwaited($key);

        $key = $this->cleanRateLimiterKey($key);

        return $this->withoutSerializationOrCompression(fn () => $this->cacheGet($key, 0));
    }

    /**
     * Reset the number of attempts for the given key.
     *
     * @param  string  $key
     * @return bool
     */
    public function resetAttempts($key)
    {
        $this->settleAwaited($key);

        $key = $this->cleanRateLimiterKey($key);

        return $this->cacheForget($key);
    }

    /**
     * Get the number of retries left for the given key.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return int
     */
    public function remaining($key, $maxAttempts)
    {
        $key = $this->cleanRateLimiterKey($key);

        $attempts = $this->attempts($key);

        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Get the number of retries left for the given key.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return int
     */
    public function retriesLeft($key, $maxAttempts)
    {
        return $this->remaining($key, $maxAttempts);
    }

    /**
     * Clear the hits and lockout timer for the given key.
     *
     * @param  string  $key
     * @return void
     */
    public function clear($key)
    {
        $this->settleAwaited($key);

        $key = $this->cleanRateLimiterKey($key);

        $this->resetAttempts($key);

        $this->cacheForget($key.':timer');
    }

    /**
     * Get the number of seconds until the "key" is accessible again.
     *
     * @param  string  $key
     * @return int
     */
    public function availableIn($key)
    {
        $this->settleAwaited($key);

        $key = $this->cleanRateLimiterKey($key);

        return max(0, $this->cacheGet($key.':timer') - $this->currentTime());
    }

    /**
     * Clean the rate limiter key from unicode characters.
     *
     * @param  string  $key
     * @return string
     */
    public function cleanRateLimiterKey($key)
    {
        return preg_replace('/&([a-z])[a-z]+;/i', '$1', htmlentities($key));
    }

    /**
     * Note an async limiter call on $key in flight on $loop; the closure returned ends it. Until
     * then this limiter's blocking calls on the key wait for it, so they see all of its writes.
     *
     * @internal Called by AsyncRateLimiter.
     */
    public function awaitCall($key, Loop $loop): Closure
    {
        $key = $this->cleanRateLimiterKey($key);
        $this->awaiting_loop = $loop;
        $this->awaiting[$key] = ($this->awaiting[$key] ?? 0) + 1;

        return function () use ($key): void {
            if (--$this->awaiting[$key] === 0) {
                unset($this->awaiting[$key]);
            }
        };
    }

    /** Wait for the async limiter calls in flight on $key, if there are any. */
    private function settleAwaited($key): void
    {
        $key = $this->cleanRateLimiterKey($key);

        if (isset($this->awaiting[$key])) {
            $this->awaiting_loop->until(fn (): bool => ! isset($this->awaiting[$key]));
        }
    }

    /**
     * The limiter's cache operations. The algorithm above reaches the cache only through these,
     * so the async limiter can run the same algorithm over the async cache.
     */
    protected function cacheAdd($key, $value, $ttl)
    {
        return $this->cache->add($key, $value, $ttl);
    }

    protected function cacheIncrement($key, $amount)
    {
        return $this->cache->increment($key, $amount);
    }

    protected function cacheGet($key, $default = null)
    {
        return $this->cache->get($key, $default);
    }

    protected function cacheHas($key)
    {
        return $this->cache->has($key);
    }

    protected function cachePut($key, $value, $ttl)
    {
        return $this->cache->put($key, $value, $ttl);
    }

    protected function cacheForget($key)
    {
        return $this->cache->forget($key);
    }

    /**
     * Execute the given callback without serialization or compression when applicable.
     *
     * @param  callable  $callback
     * @return mixed
     */
    protected function withoutSerializationOrCompression(callable $callback)
    {
        $store = $this->cache->getStore();

        if (! $store instanceof RedisStore) {
            return $callback();
        }

        $connection = $store->connection();

        if (! $connection instanceof PhpRedisConnection) {
            return $callback();
        }

        return $connection->withoutSerializationOrCompression($callback);
    }

    /**
     * Resolve the rate limiter name.
     *
     * @param  \UnitEnum|string  $name
     * @return string
     */
    private function resolveLimiterName($name): string
    {
        return (string) enum_value($name);
    }
}
