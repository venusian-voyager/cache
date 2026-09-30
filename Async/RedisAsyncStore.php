<?php

namespace Voyager\Cache\Async;

use Voyager\Cache\LuaScripts;
use Voyager\Cache\RedisStore;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Redis\Sockets\RedisPipe;

/**
 * The redis store's commands on a RedisPipe, the loop's own socket: the same commands the blocking
 * store sends, with its prefix and its encoding, so both paths read and write the same bytes. The
 * pipe answers in the order it sent, which keeps each key's operations in order for free.
 */
final class RedisAsyncStore implements AsyncStore
{
    /**
     * @var array<string, int> key => commands on it still waiting for their reply
     */
    private array $out = [];

    public function __construct(
        private readonly RedisStore $store,
        private readonly RedisPipe $pipe,
        private readonly Loop $loop,
    ) {}

    public function get(string $key): Promise
    {
        return $this->send($key, ['GET', $this->key($key)], "GET {$key}")
            ->then(fn (?string $raw): mixed => is_null($raw) ? null : $this->store->decode($raw));
    }

    public function put(string $key, mixed $value, int $seconds): Promise
    {
        return $this->send($key, ['SETEX', $this->key($key), (string) max(1, $seconds), $this->store->encode($value)], "SETEX {$key}")
            ->then(fn (mixed $reply): bool => $reply === 'OK');
    }

    public function forever(string $key, mixed $value): Promise
    {
        return $this->send($key, ['SET', $this->key($key), $this->store->encode($value)], "SET {$key}")
            ->then(fn (mixed $reply): bool => $reply === 'OK');
    }

    public function add(string $key, mixed $value, int $seconds): Promise
    {
        return $this->send($key, ['EVAL', LuaScripts::add(), '1', $this->key($key), $this->store->encode($value), (string) max(1, $seconds)], "add {$key}")
            ->then(fn (mixed $reply): bool => ! is_null($reply));
    }

    public function increment(string $key, int $by): Promise
    {
        return $this->send($key, ['INCRBY', $this->key($key), (string) $by], "INCRBY {$key}");
    }

    public function forget(string $key): Promise
    {
        return $this->send($key, ['DEL', $this->key($key)], "DEL {$key}")
            ->then(fn (int $removed): bool => $removed > 0);
    }

    public function settle(string $key): void
    {
        if (isset($this->out[$key])) {
            $this->loop->until(fn (): bool => ! isset($this->out[$key]));
        }
    }

    /** The key as Redis holds it: the connection's prefix, then the store's. */
    private function key(string $key): string
    {
        return $this->pipe->prefix().$this->store->getPrefix().$key;
    }

    /**
     * @param list<string> $arguments
     */
    private function send(string $key, array $arguments, string $describe): Promise
    {
        $this->out[$key] = ($this->out[$key] ?? 0) + 1;

        $reply = $this->pipe->send($arguments, $describe);
        $done = function () use ($key): void {
            if (--$this->out[$key] === 0) {
                unset($this->out[$key]);
            }
        };

        $reply->then(function (mixed $value) use ($done): mixed {
            $done();

            return $value;
        });
        $reply->error(function () use ($done): mixed {
            $done();

            return null;
        });

        return $reply;
    }
}
