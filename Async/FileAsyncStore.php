<?php

namespace Voyager\Cache\Async;

use Closure;
use Throwable;
use Voyager\Cache\FileStore;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\WorkerPools\WorkerPool;

/**
 * The file store's reads and writes as FileOperation gigs on a worker pool. A key has one gig out
 * at a time and its later operations wait their turn, so two gigs on one key never race on two
 * workers; different keys run side by side. Values are serialized here when the call is made, and
 * the TTL becomes an expiry timestamp here too: a queued put stores what it was given, for as long
 * as it was asked to, counted from the call.
 */
final class FileAsyncStore implements AsyncStore
{
    /**
     * @var array<string, list<array{operation: FileOperation, promise: Promise}>> key => operations waiting for the key's gig
     */
    private array $queued = [];

    /**
     * @var array<string, true> keys with a gig out
     */
    private array $out = [];

    /**
     * @param Closure(): WorkerPool $pool the pool gigs go to, resolved when the first one does
     */
    public function __construct(
        private readonly FileStore $store,
        private readonly Loop $loop,
        private readonly Closure $pool,
    ) {}

    public function get(string $key): Promise
    {
        return $this->queue($key, FileOperationKind::GET)->then(function (?string $serialized) use ($key): mixed {
            if (is_null($serialized)) {
                return null;
            }

            try {
                return $this->store->decode($serialized);
            } catch (Throwable) {
                // An entry that won't unserialize is dropped, as the blocking get drops it.
                $this->forget($key);

                return null;
            }
        });
    }

    public function put(string $key, mixed $value, int $seconds): Promise
    {
        return $this->queue($key, FileOperationKind::PUT, serialize($value), $this->store->expiresAt($seconds));
    }

    public function forever(string $key, mixed $value): Promise
    {
        return $this->queue($key, FileOperationKind::PUT, serialize($value), $this->store->expiresAt(0));
    }

    public function add(string $key, mixed $value, int $seconds): Promise
    {
        return $this->queue($key, FileOperationKind::ADD, serialize($value), $this->store->expiresAt($seconds));
    }

    public function increment(string $key, int $by): Promise
    {
        return $this->queue($key, FileOperationKind::INCREMENT, by: $by);
    }

    public function forget(string $key): Promise
    {
        return $this->queue($key, FileOperationKind::FORGET);
    }

    public function settle(string $key): void
    {
        if (isset($this->out[$key])) {
            $this->loop->until(fn (): bool => ! isset($this->out[$key]));
        }
    }

    private function queue(string $key, FileOperationKind $kind, ?string $serialized = null, ?int $expires_at = null, int $by = 0): Promise
    {
        $promise = $this->loop->promise();

        $this->queued[$key][] = [
            'operation' => new FileOperation(
                $this->store->getDirectory(), $this->store->getFilePermission(), $kind, $key, $serialized, $expires_at, $by,
            ),
            'promise' => $promise,
        ];

        if (! isset($this->out[$key])) {
            $this->send($key);
        }

        return $promise;
    }

    private function send(string $key): void
    {
        $next = isset($this->queued[$key]) ? array_shift($this->queued[$key]) : null;

        if (is_null($next)) {
            unset($this->queued[$key], $this->out[$key]);
            return;
        }

        $this->out[$key] = true;

        try {
            $gig = ($this->pool)()->submit($next['operation']);
        } catch (Throwable $e) {
            $next['promise']->reject($e);
            $this->send($key);
            return;
        }

        $gig->then(function (mixed $result) use ($key, $next): mixed {
            $next['promise']->resolve($result);
            $this->send($key);

            return $result;
        });

        $gig->error(function (Throwable $e) use ($key, $next): mixed {
            $next['promise']->reject($e);
            $this->send($key);

            return null;
        });
    }
}
