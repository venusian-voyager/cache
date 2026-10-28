<?php

namespace Voyager\Cache;

class FileLock extends CacheLock
{
    /**
     * The cache store implementation.
     *
     * @var \Voyager\Cache\FileStore
     */
    protected $store;

    /**
     * Attempt to acquire the lock.
     *
     * @return bool
     */
    public function acquire()
    {
        return $this->store->add($this->name, $this->owner, $this->seconds);
    }

    /**
     * Attempt to refresh the lock for the given number of seconds.
     *
     * @param  int|null  $seconds
     * @return bool
     */
    public function refresh($seconds = null)
    {
        return $this->store->refreshIfOwned(
            $this->name,
            $this->owner,
            $seconds ?? $this->seconds
        );
    }
}
