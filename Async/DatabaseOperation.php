<?php

namespace Voyager\Cache\Async;

use Voyager\Cache\DatabaseStore;
use Voyager\Database\IOPools\WorkerConnection;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;

/**
 * One database store operation, run in a pool worker on the caller's connection config. Only
 * bytes cross: a put carries the value the caller already encoded, and a get hands back the
 * stored bytes for the caller to decode.
 */
final readonly class DatabaseOperation implements ShouldPool
{
    /**
     * @param array<string, mixed> $config the caller's connection config
     * @param array|bool|null $serializable_classes what an increment may unserialize, as the caller's store allows
     */
    public function __construct(
        public string $connection,
        public array $config,
        public string $table,
        public string $prefix,
        public DatabaseOperationKind $kind,
        public string $key,
        public ?string $encoded = null,
        public ?int $expiration = null,
        public int $by = 0,
        public array|bool|null $serializable_classes = null,
    ) {}

    public function handle(): mixed
    {
        $store = new DatabaseStore(
            WorkerConnection::resolve($this->connection, $this->config), $this->table, $this->prefix,
            serializableClasses: $this->serializable_classes,
        );

        return match ($this->kind) {
            DatabaseOperationKind::GET => $store->getRaw($this->key),
            DatabaseOperationKind::PUT => $store->putRaw($this->key, (string) $this->encoded, (int) $this->expiration),
            DatabaseOperationKind::ADD => $store->addRaw($this->key, (string) $this->encoded, (int) $this->expiration),
            DatabaseOperationKind::INCREMENT => $store->increment($this->key, $this->by),
            DatabaseOperationKind::FORGET => $store->forget($this->key),
        };
    }
}
