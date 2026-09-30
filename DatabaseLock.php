<?php

namespace Voyager\Cache;

use Throwable;
use Voyager\Database\Connection;
use Voyager\Database\DetectsConcurrencyErrors;
use Voyager\Database\QueryException;

/**
 * A lock as a row in the lock table: the key's primary key makes the insert the acquisition, and
 * an expired or already-owned row is taken over by update.
 */
class DatabaseLock extends Lock
{
    use DetectsConcurrencyErrors;

    /**
     * @param  array{0: int, 1: int}|null  $lottery  odds that an acquisition prunes expired locks
     */
    public function __construct(
        protected Connection $connection,
        protected string $table,
        string $name,
        int $seconds,
        ?string $owner = null,
        protected ?array $lottery = [2, 100],
        protected int $defaultTimeoutInSeconds = 86400,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        try {
            $this->connection->table($this->table)->insert([
                'key' => $this->name,
                'owner' => $this->owner,
                'expiration' => $this->expiresAt(),
            ]);

            $acquired = true;
        } catch (QueryException) {
            $acquired = $this->connection->table($this->table)
                ->where('key', $this->name)
                ->where(fn ($query) => $query->where('owner', $this->owner)->orWhere('expiration', '<=', $this->currentTime()))
                ->update([
                    'owner' => $this->owner,
                    'expiration' => $this->expiresAt(),
                ]) >= 1;
        }

        if (count($this->lottery ?? []) === 2 && random_int(1, $this->lottery[1]) <= $this->lottery[0]) {
            $this->pruneExpiredLocks();
        }

        return $acquired;
    }

    /**
     * A lock with no seconds still expires, after the default timeout, so a crashed owner's lock
     * frees itself.
     */
    protected function expiresAt(?int $seconds = null): int
    {
        $seconds ??= $this->seconds;

        return $this->currentTime() + ($seconds > 0 ? $seconds : $this->defaultTimeoutInSeconds);
    }

    public function release(): bool
    {
        if (! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        $this->connection->table($this->table)
            ->where('key', $this->name)
            ->where('owner', $this->owner)
            ->delete();

        return true;
    }

    public function forceRelease(): void
    {
        $this->connection->table($this->table)
            ->where('key', $this->name)
            ->delete();
    }

    /**
     * Pushes the expiration out while this owner still holds the lock.
     *
     * @param  int|null  $seconds  the lock's own seconds when null
     */
    public function refresh($seconds = null): bool
    {
        return $this->connection->table($this->table)
            ->where('key', $this->name)
            ->where('owner', $this->owner)
            ->where('expiration', '>', $this->currentTime())
            ->update(['expiration' => $this->expiresAt($seconds)]) >= 1;
    }

    public function pruneExpiredLocks(): void
    {
        try {
            $this->connection->table($this->table)
                ->where('expiration', '<=', $this->currentTime())
                ->delete();
        } catch (Throwable $e) {
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }
        }
    }

    protected function getCurrentOwner(): ?string
    {
        $row = $this->connection->table($this->table)->where('key', $this->name)->first();

        return is_null($row) ? null : ((object) $row)->owner;
    }

    public function getConnectionName(): ?string
    {
        return $this->connection->getName();
    }
}
