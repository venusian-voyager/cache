<?php

namespace Voyager\Cache\Async;

use Voyager\Contracts\IOPools\Promise;

/**
 * A store's I/O without blocking the loop. Keys arrive as the store sees them, values as PHP
 * values: each driver encodes them the way its blocking store does, so both paths read and write
 * the same bytes. Operations on one key complete in the order they were made.
 */
interface AsyncStore
{
    /** @return Promise the value, or null when there is none */
    public function get(string $key): Promise;

    /** @return Promise whether it was written */
    public function put(string $key, mixed $value, int $seconds): Promise;

    /** @return Promise whether it was written */
    public function forever(string $key, mixed $value): Promise;

    /** @return Promise whether it was added: false when an unexpired value was there */
    public function add(string $key, mixed $value, int $seconds): Promise;

    /** @return Promise the new value */
    public function increment(string $key, int $by): Promise;

    /** @return Promise whether something was removed */
    public function forget(string $key): Promise;

    /**
     * Blocks until every operation made on $key has completed: a blocking call on the key waits
     * here first, so it sees the async writes made before it.
     */
    public function settle(string $key): void;

    /**
     * This store with values written and read without a connection's own serializer and
     * compression, sharing what is outstanding. A store with no such layer returns itself.
     */
    public function raw(): AsyncStore;
}
