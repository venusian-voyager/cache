<?php

namespace Voyager\Cache;

use Closure;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;

/**
 * The same repository, every call queued on the loop and answered by a promise.
 * Sync callers keep using the Repository; this is for code that would rather not stall its turn.
 */
final class DeferredRepository
{
    public function __construct(
        private readonly Repository $repository,
        private readonly Loop $loop,
    ) {}

    public function get(string $key, mixed $default = null): Promise
    {
        return $this->later(fn () => $this->repository->get($key, $default));
    }

    public function has(string $key): Promise
    {
        return $this->later(fn () => $this->repository->has($key));
    }

    public function pull(string $key, mixed $default = null): Promise
    {
        return $this->later(fn () => $this->repository->pull($key, $default));
    }

    public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): Promise
    {
        return $this->later(fn () => $this->repository->put($key, $value, $ttl));
    }

    public function add(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): Promise
    {
        return $this->later(fn () => $this->repository->add($key, $value, $ttl));
    }

    public function forever(string $key, mixed $value): Promise
    {
        return $this->later(fn () => $this->repository->forever($key, $value));
    }

    public function increment(string $key, int $value = 1): Promise
    {
        return $this->later(fn () => $this->repository->increment($key, $value));
    }

    public function decrement(string $key, int $value = 1): Promise
    {
        return $this->later(fn () => $this->repository->decrement($key, $value));
    }

    public function forget(string $key): Promise
    {
        return $this->later(fn () => $this->repository->forget($key));
    }

    public function remember(string $key, \DateTimeInterface|\DateInterval|int|null $ttl, Closure $callback): Promise
    {
        return $this->later(fn () => $this->repository->remember($key, $ttl, $callback));
    }

    private function later(Closure $work): Promise
    {
        return $this->loop->defer($work);
    }
}
