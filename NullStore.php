<?php

namespace Voyager\Cache;

use Voyager\Contracts\Cache\LockProvider;

class NullStore extends TaggableStore implements LockProvider
{
    use RetrievesMultipleKeys;

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key
     * @return void
     */
    public function get($key): mixed
    {
        return null;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     * @return bool
     */
    public function put($key, $value, $seconds): bool
    {
        return false;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return false
     */
    public function increment($key, $value = 1): bool|int
    {
        return false;
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return false
     */
    public function decrement($key, $value = 1): bool|int
    {
        return false;
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return bool
     */
    public function forever($key, $value): bool
    {
        return false;
    }

    /**
     * Get a lock instance.
     *
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     * @return \Voyager\Contracts\Cache\Lock
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new NoLock($name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param  string  $name
     * @param  string  $owner
     * @return \Voyager\Contracts\Cache\Lock
     */
    public function restoreLock($name, $owner): \Voyager\Contracts\Cache\Lock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget($key): bool
    {
        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush(): bool
    {
        return true;
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return '';
    }
}
