<?php

namespace Voyager\Cache\Console;

use Voyager\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:cache-table', aliases: ['cache:table'])]
class CacheTableCommand extends MigrationGeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected ?string $name = 'make:cache-table';

    /**
     * The console command name aliases.
     *
     * @var array
     */
    protected ?array $aliases = ['cache:table'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected string $description = 'Create a migration for the cache database table';

    /**
     * Get the migration table name.
     *
     * @return string
     */
    protected function migrationTableName(): string
    {
        return 'cache';
    }

    /**
     * Get the path to the migration stub file.
     *
     * @return string
     */
    protected function migrationStubFile(): string
    {
        return __DIR__.'/stubs/cache.stub';
    }
}
