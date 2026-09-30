<?php

namespace Voyager\Cache\Async;

use Throwable;
use Voyager\Cache\DatabaseStore;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Database\IOPools\Offload;
use Voyager\Database\IOPools\ConnectionLanes;

/**
 * The database store's operations as DatabaseOperation gigs, offloaded like any query on the
 * store's connection: a get is a read and runs beside other reads, everything else is a write
 * and waits for the calls before it. That orders one key's operations, and it's the same order
 * a blocking query on the connection keeps. Values are encoded here when the call is made, and
 * the TTL becomes an expiration here too: a queued put stores what it was given, for as long as
 * it was asked to, counted from the call.
 */
final class DatabaseAsyncStore implements AsyncStore
{
    public function __construct(
        private readonly DatabaseStore $store,
        private readonly Loop $loop,
    ) {}

    public function get(string $key): Promise
    {
        return $this->send(DatabaseOperationKind::GET, $key)->then(function (?string $encoded) use ($key): mixed {
            if (is_null($encoded)) {
                return null;
            }

            try {
                return $this->store->decode($encoded);
            } catch (Throwable) {
                // An entry that won't unserialize is dropped, as a blocking get never returns it.
                $this->forget($key);

                return null;
            }
        });
    }

    public function put(string $key, mixed $value, int $seconds): Promise
    {
        return $this->send(DatabaseOperationKind::PUT, $key, $this->store->encode($value), $this->store->expiresAt($seconds));
    }

    public function forever(string $key, mixed $value): Promise
    {
        return $this->put($key, $value, DatabaseStore::FOREVER);
    }

    public function add(string $key, mixed $value, int $seconds): Promise
    {
        return $this->send(DatabaseOperationKind::ADD, $key, $this->store->encode($value), $this->store->expiresAt($seconds));
    }

    public function increment(string $key, int $by): Promise
    {
        return $this->send(DatabaseOperationKind::INCREMENT, $key, by: $by);
    }

    public function forget(string $key): Promise
    {
        return $this->send(DatabaseOperationKind::FORGET, $key);
    }

    /** Waits for every offloaded call on the store's connection, this key's among them. */
    public function settle(string $key): void
    {
        ConnectionLanes::current()?->settle((string) $this->store->getConnection()->getName(), true);
    }

    private function send(DatabaseOperationKind $kind, string $key, ?string $encoded = null, ?int $expiration = null, int $by = 0): Promise
    {
        try {
            $offload = Offload::for($this->store->getConnection(), null);
        } catch (Throwable $e) {
            $promise = $this->loop->promise();
            $promise->reject($e);

            return $promise;
        }

        return $offload->send($kind->writes(), fn (): DatabaseOperation => new DatabaseOperation(
            $offload->connection, $offload->config, $this->store->getTable(), $this->store->getPrefix(), $kind, $key, $encoded, $expiration, $by,
            $this->store->getSerializableClasses(),
        ));
    }
}
