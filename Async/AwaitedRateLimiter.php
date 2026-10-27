<?php

namespace Voyager\Cache\Async;

use Closure;
use Fiber;
use LogicException;
use Voyager\Cache\RateLimiter;
use Voyager\Contracts\Cache\Repository as Cache;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Task;

/**
 * The blocking rate limiter's algorithm over the async cache: each cache operation is the async
 * one, awaited. Awaiting inside the loop task this was made in suspends that task and never the
 * loop, so one exists only inside a task run() starts, and refuses to work anywhere else.
 *
 * @internal Reached through AsyncRateLimiter.
 */
final class AwaitedRateLimiter extends RateLimiter
{
    /** Whether the counter operations in flight bypass the connection's serializer. */
    private bool $raw = false;

    private function __construct(
        Cache $cache,
        private readonly AsyncRepository $async,
        private readonly Fiber $task,
    ) {
        parent::__construct($cache);
    }

    /**
     * Start a loop task that hands $use a limiter bound to that task.
     *
     * @param  \Closure(self): mixed  $use
     * @return \Voyager\Contracts\IOPools\Task
     */
    public static function run(Loop $loop, Cache $cache, Closure $use): Task
    {
        return $loop->async(function () use ($cache, $use) {
            return $use(new self($cache, $cache->async(), Fiber::getCurrent()));
        });
    }

    protected function cacheAdd($key, $value, $ttl)
    {
        return $this->awaited()->add($key, $value, $ttl)->wait();
    }

    protected function cacheIncrement($key, $amount)
    {
        return $this->awaited()->increment($key, $amount)->wait();
    }

    protected function cacheGet($key, $default = null)
    {
        return $this->awaited()->get($key, $default)->wait();
    }

    protected function cacheHas($key)
    {
        return $this->awaited()->has($key)->wait();
    }

    protected function cachePut($key, $value, $ttl)
    {
        return $this->awaited()->put($key, $value, $ttl)->wait();
    }

    protected function cacheForget($key)
    {
        return $this->awaited()->forget($key)->wait();
    }

    /**
     * The counter's operations go through the raw view, as the blocking limiter runs them with
     * the connection's serializer off. The flag is this limiter's own, and this limiter serves
     * one task, so no other fiber's operations see it.
     */
    protected function withoutSerializationOrCompression(callable $callback)
    {
        $this->raw = true;

        try {
            return $callback();
        } finally {
            $this->raw = false;
        }
    }

    /**
     * The async cache to await on, once this is known to be running in its own task.
     *
     * @throws \LogicException
     */
    private function awaited(): AsyncRepository
    {
        if (Fiber::getCurrent() !== $this->task) {
            throw new LogicException('An awaited rate limiter works only inside the loop task it was made for: use RateLimiter::async().');
        }

        return $this->raw ? $this->async->raw() : $this->async;
    }
}
