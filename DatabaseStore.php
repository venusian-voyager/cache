<?php

namespace Voyager\Cache;

use Closure;
use RuntimeException;
use Voyager\Contracts\Cache\LockProvider;
use Voyager\Contracts\Cache\Store;
use Voyager\Database\Connection;
use Voyager\Database\PostgresConnection;
use Voyager\Database\QueryException;
use Voyager\Database\Query\Builder;
use Voyager\Database\SQLiteConnection;
use Voyager\Database\SqlServerConnection;
use Voyager\NutsAndBolts\Collection;
use Voyager\NutsAndBolts\Concerns\InteractsWithTime;
use Voyager\NutsAndBolts\DataObjects\Str;

/**
 * Cache entries as rows: key, serialized value, and an expiration timestamp. The raw methods
 * (getRaw, putRaw, addRaw) move already-encoded values, so an async caller encodes and decodes
 * on its side and a worker only moves bytes.
 */
class DatabaseStore implements LockProvider, Store
{
    use InteractsWithTime;

    /** Seconds forever() keeps a value: ten years, inside a 32-bit expiration column. */
    public const int FOREVER = 315360000;

    /**
     * The connection locks are taken on; the cache's own when none is set.
     */
    protected ?Connection $lockConnection = null;

    /**
     * @param  array{0: int, 1: int}  $lockLottery  odds that acquiring a lock prunes expired ones
     * @param  array|bool|null  $serializableClasses  the classes unserialize may build
     */
    public function __construct(
        protected Connection $connection,
        protected string $table,
        protected string $prefix = '',
        protected string $lockTable = 'cache_locks',
        protected array $lockLottery = [2, 100],
        protected int $defaultLockTimeoutInSeconds = 86400,
        protected array|bool|null $serializableClasses = null,
    ) {}

    public function get($key): mixed
    {
        return $this->many([$key])[$key];
    }

    public function many(array $keys): array
    {
        if (count($keys) === 0) {
            return [];
        }

        return array_map(
            fn (?string $raw): mixed => is_null($raw) ? null : $this->decode($raw),
            $this->manyRaw($keys),
        );
    }

    /**
     * The stored bytes, or null when there is none or it expired; an expired entry is removed.
     */
    public function getRaw(string $key): ?string
    {
        return $this->manyRaw([$key])[$key];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    protected function manyRaw(array $keys): array
    {
        $rows = $this->table()
            ->whereIn('key', array_map(fn (string $key): string => $this->prefix.$key, $keys))
            ->get()
            ->map(fn ($row): object => (object) $row);

        $now = $this->currentTime();

        [$live, $expired] = $rows->partition(fn (object $row): bool => $row->expiration > $now);

        if ($expired->isNotEmpty()) {
            $this->forgetManyIfExpired($expired->pluck('key')->all(), prefixed: true);
        }

        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $live->firstWhere('key', $this->prefix.$key)?->value;
        }

        return $results;
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->putMany([$key => $value], $seconds);
    }

    public function putMany(array $values, $seconds): bool
    {
        $expiration = $this->expiresAt($seconds);

        $rows = [];

        foreach ($values as $key => $value) {
            $rows[] = ['key' => $this->prefix.$key, 'value' => $this->encode($value), 'expiration' => $expiration];
        }

        return $this->table()->upsert($rows, 'key') > 0;
    }

    /**
     * Stores already-encoded bytes that expire at the given timestamp. If that moment has passed,
     * the entry is gone rather than written: a put whose TTL ran out while it waited.
     */
    public function putRaw(string $key, string $encoded, int $expiration): bool
    {
        if ($expiration <= $this->currentTime()) {
            $this->forget($key);

            return true;
        }

        return $this->table()->upsert([['key' => $this->prefix.$key, 'value' => $encoded, 'expiration' => $expiration]], 'key') > 0;
    }

    public function add($key, $value, $seconds): bool
    {
        return $this->addRaw($key, $this->encode($value), $this->expiresAt($seconds));
    }

    /**
     * Stores already-encoded bytes unless an unexpired entry is there. A moment already passed
     * adds nothing.
     */
    public function addRaw(string $key, string $encoded, int $expiration): bool
    {
        if ($expiration <= $this->currentTime() || ! is_null($this->getRaw($key))) {
            return false;
        }

        return $this->insertIfAbsent(['key' => $this->prefix.$key, 'value' => $encoded, 'expiration' => $expiration]);
    }

    /**
     * Inserts the row unless its key is taken. SQL Server has no insert-or-ignore, so there a
     * duplicate key is caught instead.
     *
     * @param  array{key: string, value: string, expiration: int}  $row
     */
    protected function insertIfAbsent(array $row): bool
    {
        if (! $this->connection instanceof SqlServerConnection) {
            return $this->table()->insertOrIgnore($row) > 0;
        }

        try {
            return $this->table()->insert($row);
        } catch (QueryException) {
            // another writer added it between the check and the insert
            return false;
        }
    }

    public function increment($key, $value = 1): bool|int
    {
        return $this->incrementOrDecrement($key, $value, fn (int $current, int $by): int => $current + $by);
    }

    public function decrement($key, $value = 1): bool|int
    {
        return $this->incrementOrDecrement($key, $value, fn (int $current, int $by): int => $current - $by);
    }

    /**
     * The row is locked for the read and the write, so two increments never read the same value.
     * A key with no live value counts from zero and keeps its count forever, as the array, file
     * and redis stores do; a value that isn't a number is left alone and answers false. Two
     * callers counting a missing key both try the insert: the one that loses it finds the row
     * the winner wrote and counts on from there.
     *
     * @param  Closure(int, int): int  $callback
     */
    protected function incrementOrDecrement(string $key, int $value, Closure $callback): int|false
    {
        return $this->connection->transaction(function () use ($key, $value, $callback): int|false {
            $prefixed = $this->prefix.$key;

            // a lost insert means another caller just wrote the row: the second pass counts on it
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $row = $this->table()->where('key', $prefixed)->lockForUpdate()->first();
                $row = is_null($row) ? null : (object) $row;

                if (! is_null($row) && $row->expiration > $this->currentTime()) {
                    $current = $this->decode($row->value);

                    if (! is_numeric($current)) {
                        return false;
                    }

                    $new = $callback((int) $current, $value);

                    $this->table()->where('key', $prefixed)->update(['value' => $this->encode($new)]);

                    return $new;
                }

                $new = $callback(0, $value);
                $fresh = ['value' => $this->encode($new), 'expiration' => $this->expiresAt(self::FOREVER)];

                if (! is_null($row)) {
                    $this->table()->where('key', $prefixed)->update($fresh);

                    return $new;
                }

                if ($this->insertIfAbsent(['key' => $prefixed, ...$fresh])) {
                    return $new;
                }
            }

            throw new RuntimeException("Cache key [{$key}] was inserted and removed by other callers while it was being counted.");
        });
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, self::FOREVER);
    }

    public function lock($name, $seconds = 0, $owner = null): DatabaseLock
    {
        return new DatabaseLock(
            $this->lockConnection ?? $this->connection,
            $this->lockTable,
            $this->prefix.$name,
            $seconds,
            $owner,
            $this->lockLottery,
            $this->defaultLockTimeoutInSeconds,
        );
    }

    public function restoreLock($name, $owner): DatabaseLock
    {
        return $this->lock($name, 0, $owner);
    }

    public function forget($key): bool
    {
        return $this->forgetMany([$key]);
    }

    public function forgetIfExpired(string $key): bool
    {
        return $this->forgetManyIfExpired([$key]);
    }

    /**
     * A key goes with the timestamp flexible() keeps beside it. Whether anything was removed.
     *
     * @param  list<string>  $keys
     */
    protected function forgetMany(array $keys): bool
    {
        return $this->table()->whereIn('key', (new Collection($keys))->flatMap(fn (string $key): array => [
            $this->prefix.$key,
            "{$this->prefix}illuminate:cache:flexible:created:{$key}",
        ])->all())->delete() > 0;
    }

    /**
     * @param  list<string>  $keys
     */
    protected function forgetManyIfExpired(array $keys, bool $prefixed = false): bool
    {
        $this->table()
            ->whereIn('key', (new Collection($keys))->flatMap(fn (string $key): array => $prefixed ? [
                $key,
                $this->prefix.'illuminate:cache:flexible:created:'.Str::chopStart($key, $this->prefix),
            ] : [
                "{$this->prefix}{$key}",
                "{$this->prefix}illuminate:cache:flexible:created:{$key}",
            ])->all())
            ->where('expiration', '<=', $this->currentTime())
            ->delete();

        return true;
    }

    public function flush(): bool
    {
        $this->table()->delete();

        return true;
    }

    /**
     * The value as the table stores it. A serialized string with a NUL byte can't go into a
     * Postgres text column or a SQLite string, so there it is base64 encoded.
     */
    public function encode(mixed $value): string
    {
        $result = serialize($value);

        if (($this->connection instanceof PostgresConnection || $this->connection instanceof SQLiteConnection)
            && str_contains($result, "\0")) {
            $result = base64_encode($result);
        }

        return $result;
    }

    public function decode(string $value): mixed
    {
        if (($this->connection instanceof PostgresConnection || $this->connection instanceof SQLiteConnection)
            && ! Str::contains($value, [':', ';'])) {
            $value = base64_decode($value);
        }

        return is_null($this->serializableClasses)
            ? unserialize($value)
            : unserialize($value, ['allowed_classes' => $this->serializableClasses]);
    }

    /** The expiration put() would write for $seconds from now. */
    public function expiresAt(int $seconds): int
    {
        return $this->currentTime() + $seconds;
    }

    protected function table(): Builder
    {
        return $this->connection->table($this->table);
    }

    public function getSerializableClasses(): array|bool|null
    {
        return $this->serializableClasses;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getLockTable(): string
    {
        return $this->lockTable;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function getLockConnection(): ?Connection
    {
        return $this->lockConnection;
    }

    public function setLockConnection(?Connection $connection): static
    {
        $this->lockConnection = $connection;

        return $this;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }
}
