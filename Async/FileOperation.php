<?php

namespace Voyager\Cache\Async;

use Voyager\Cache\FileStore;
use Voyager\Filesystem\Filesystem;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;

/**
 * One file store operation, run in a pool worker. Only bytes cross: a put carries the value the
 * caller already serialized, and a get hands back the stored bytes for the caller to decode.
 */
final readonly class FileOperation implements ShouldPool
{
    public function __construct(
        public string $directory,
        public ?int $permission,
        public FileOperationKind $kind,
        public string $key,
        public ?string $serialized = null,
        public ?int $expires_at = null,
        public int $by = 0,
    ) {}

    public function handle(): mixed
    {
        $store = new FileStore(new Filesystem(), $this->directory, $this->permission);

        return match ($this->kind) {
            FileOperationKind::GET => $store->getRaw($this->key),
            FileOperationKind::PUT => $store->putRaw($this->key, (string) $this->serialized, (int) $this->expires_at),
            FileOperationKind::ADD => $store->addRaw($this->key, (string) $this->serialized, (int) $this->expires_at),
            FileOperationKind::INCREMENT => $store->increment($this->key, $this->by),
            FileOperationKind::FORGET => $store->forget($this->key),
        };
    }
}
