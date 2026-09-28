<?php

namespace Voyager\Cache\Async;

use Closure;
use UnitEnum;
use DateInterval;
use DateTimeInterface;
use Voyager\Cache\Repository;
use Voyager\Cache\Signals\CacheHit;
use Voyager\Cache\Signals\KeyWritten;
use Voyager\Cache\Signals\CacheMissed;
use Voyager\Cache\Signals\WritingKey;
use Voyager\Cache\Signals\KeyForgotten;
use Voyager\Cache\Signals\RetrievingKey;
use Voyager\Cache\Signals\ForgettingKey;
use Voyager\Cache\Signals\KeyWriteFailed;
use Voyager\Cache\Signals\KeyForgetFailed;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;

use function Voyager\NutsAndBolts\Helpers\enum_value;

/**
 * A repository's operations as promises. What the repository decides happens here, in the
 * calling process: defaults, the TTL in seconds, a put with no time left becoming a forget, pull
 * as a get then a forget, remember's callback, and the signals, each dispatched once. Only the
 * store's I/O goes async, through the store's AsyncStore. A store with no I/O of its own (array,
 * null) has none: its operations run right here and hand back a settled promise.
 */
final class AsyncRepository
{
    public function __construct(
        private readonly Repository $repository,
        private readonly Loop $loop,
        private readonly ?AsyncStore $store,
    ) {}

    /** @return Promise the value, or $default when there is none */
    public function get(UnitEnum|string $key, mixed $default = null): Promise
    {
        if (is_null($this->store)) {
            return $this->settled($this->repository->get($key, $default));
        }

        $key = enum_value($key);
        $this->signal(new RetrievingKey($this->repository->getName(), $key));

        return $this->store->get($key)->then(function (mixed $value) use ($key, $default): mixed {
            if (is_null($value)) {
                $this->signal(new CacheMissed($this->repository->getName(), $key));

                return value($default);
            }

            $this->signal(new CacheHit($this->repository->getName(), $key, $value));

            return $value;
        });
    }

    /** @return Promise whether a value is there */
    public function has(UnitEnum|string $key): Promise
    {
        return $this->get($key)->then(fn (mixed $value): bool => ! is_null($value));
    }

    /** @return Promise the value, or $default; the key is forgotten after */
    public function pull(UnitEnum|string $key, mixed $default = null): Promise
    {
        if (is_null($this->store)) {
            return $this->settled($this->repository->pull($key, $default));
        }

        return $this->get($key, $default)->then(function (mixed $value) use ($key): mixed {
            // Queued behind the get on the same key, so it runs after the value was read.
            $this->forget($key);

            return $value;
        });
    }

    /** @return Promise whether it was written; no TTL writes it forever */
    public function put(UnitEnum|string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): Promise
    {
        if (is_null($this->store)) {
            return $this->settled($this->repository->put($key, $value, $ttl));
        }

        $key = enum_value($key);

        if (is_null($ttl)) {
            return $this->forever($key, $value);
        }

        $seconds = $this->repository->seconds($ttl);

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        $this->signal(new WritingKey($this->repository->getName(), $key, $value, $seconds));

        return $this->store->put($key, $value, $seconds)->then(function (bool $written) use ($key, $value, $seconds): bool {
            $this->signal($written
                ? new KeyWritten($this->repository->getName(), $key, $value, $seconds)
                : new KeyWriteFailed($this->repository->getName(), $key, $value, $seconds));

            return $written;
        });
    }

    /** @return Promise whether it was written */
    public function forever(UnitEnum|string $key, mixed $value): Promise
    {
        if (is_null($this->store)) {
            return $this->settled($this->repository->forever($key, $value));
        }

        $key = enum_value($key);
        $this->signal(new WritingKey($this->repository->getName(), $key, $value));

        return $this->store->forever($key, $value)->then(function (bool $written) use ($key, $value): bool {
            $this->signal($written
                ? new KeyWritten($this->repository->getName(), $key, $value)
                : new KeyWriteFailed($this->repository->getName(), $key, $value));

            return $written;
        });
    }

    /** @return Promise whether it was added: false when a value was already there */
    public function add(UnitEnum|string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): Promise
    {
        if (is_null($this->store)) {
            return $this->settled($this->repository->add($key, $value, $ttl));
        }

        $key = enum_value($key);

        // No TTL: the blocking add's get-then-forever, one after the other on the key.
        if (is_null($ttl)) {
            return $this->get($key)->then(fn (mixed $existing): mixed => is_null($existing) ? $this->forever($key, $value) : false);
        }

        $seconds = $this->repository->seconds($ttl);

        return $seconds <= 0 ? $this->settled(false) : $this->store->add($key, $value, $seconds);
    }

    /** @return Promise the new value */
    public function increment(UnitEnum|string $key, int $by = 1): Promise
    {
        return is_null($this->store)
            ? $this->settled($this->repository->increment($key, $by))
            : $this->store->increment(enum_value($key), $by);
    }

    /** @return Promise the new value */
    public function decrement(UnitEnum|string $key, int $by = 1): Promise
    {
        return is_null($this->store)
            ? $this->settled($this->repository->decrement($key, $by))
            : $this->store->increment(enum_value($key), -$by);
    }

    /** @return Promise whether something was removed */
    public function forget(UnitEnum|string $key): Promise
    {
        if (is_null($this->store)) {
            return $this->settled($this->repository->forget($key));
        }

        $key = enum_value($key);
        $this->signal(new ForgettingKey($this->repository->getName(), $key));

        return $this->store->forget($key)->then(function (bool $forgotten) use ($key): bool {
            $this->signal($forgotten
                ? new KeyForgotten($this->repository->getName(), $key)
                : new KeyForgetFailed($this->repository->getName(), $key));

            return $forgotten;
        });
    }

    /**
     * The cached value, or $callback's result, stored for $ttl. Not atomic, like the blocking
     * remember: two misses at once both run the callback, and the later write wins.
     *
     * @param Closure(): mixed $callback runs here, in the calling process
     * @return Promise the value
     */
    public function remember(UnitEnum|string $key, Closure|DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): Promise
    {
        if (is_null($this->store)) {
            return $this->settled($this->repository->remember($key, $ttl, $callback));
        }

        return $this->get($key)->then(function (mixed $cached) use ($key, $ttl, $callback): mixed {
            if (! is_null($cached)) {
                return $cached;
            }

            $value = $callback();

            return $this->put($key, $value, value($ttl, $value))->then(fn (): mixed => $value);
        });
    }

    private function settled(mixed $value): Promise
    {
        $promise = $this->loop->promise();
        $promise->resolve($value);

        return $promise;
    }

    private function signal(object $signal): void
    {
        $this->repository->getEventDispatcher()?->dispatch($signal);
    }
}
