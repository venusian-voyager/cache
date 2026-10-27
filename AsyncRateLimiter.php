<?php

namespace Voyager\Cache;

use Closure;
use Voyager\Cache\Async\AwaitedRateLimiter;
use Voyager\Contracts\Cache\Repository as Cache;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;

/**
 * The rate limiter's operations as promises. Each runs the blocking limiter's own algorithm in a
 * loop task over the cache's async operations, so a request waiting on its limit suspends
 * instead of holding the loop. Named limiters and key cleaning stay with the limiter this came
 * from.
 */
final class AsyncRateLimiter
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Cache $cache,
        private readonly Loop $loop,
    ) {}

    /**
     * Run $callback, then hit the key, unless it has had too many attempts: then false.
     * A callback that returns a promise is awaited; null becomes true.
     *
     * @return Promise<mixed>
     */
    public function attempt($key, $maxAttempts, Closure $callback, $decaySeconds = 60): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->attempt(
            $key, $maxAttempts, function () use ($callback) {
                $result = $callback();

                return $result instanceof Promise ? $result->wait() : $result;
            }, $decaySeconds
        ));
    }

    /** @return Promise<bool> */
    public function tooManyAttempts($key, $maxAttempts): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->tooManyAttempts($key, $maxAttempts));
    }

    /** @return Promise<int> the hits so far */
    public function hit($key, $decaySeconds = 60): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->hit($key, $decaySeconds));
    }

    /** @return Promise<int> the hits so far */
    public function increment($key, $decaySeconds = 60, $amount = 1): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->increment($key, $decaySeconds, $amount));
    }

    /** @return Promise<int> the hits so far */
    public function decrement($key, $decaySeconds = 60, $amount = 1): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->decrement($key, $decaySeconds, $amount));
    }

    /** @return Promise<mixed> */
    public function attempts($key): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->attempts($key));
    }

    /** @return Promise<bool> */
    public function resetAttempts($key): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->resetAttempts($key));
    }

    /** @return Promise<int> */
    public function remaining($key, $maxAttempts): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->remaining($key, $maxAttempts));
    }

    /** @return Promise<int> */
    public function retriesLeft($key, $maxAttempts): Promise
    {
        return $this->remaining($key, $maxAttempts);
    }

    /** @return Promise<null> */
    public function clear($key): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->clear($key));
    }

    /** @return Promise<int> seconds until the key is available again */
    public function availableIn($key): Promise
    {
        return $this->run($key, fn (AwaitedRateLimiter $limiter) => $limiter->availableIn($key));
    }

    /** The named limiter, from the limiter this came from. */
    public function limiter($name): ?Closure
    {
        return $this->limiter->limiter($name);
    }

    public function cleanRateLimiterKey($key): string
    {
        return $this->limiter->cleanRateLimiterKey($key);
    }

    /** Run $use in a loop task, the limiter's blocking calls on $key waiting for it meanwhile. */
    private function run($key, Closure $use): Promise
    {
        $done = $this->limiter->awaitCall($key, $this->loop);

        return AwaitedRateLimiter::run($this->loop, $this->cache, function (AwaitedRateLimiter $limiter) use ($use, $done) {
            try {
                return $use($limiter);
            } finally {
                $done();
            }
        });
    }
}
