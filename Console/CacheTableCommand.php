<?php

namespace Voyager\Cache\Console;

use Voyager\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:cache-table', aliases: ['cache:table'])]
class CacheTableCommand extends MigrationGeneratorCommand
{
    protected ?string $name = 'make:cache-table';

    protected ?array $aliases = ['cache:table'];

    protected string $description = 'Create a migration for the cache database table';

    protected function migrationTableName(): string
    {
        return 'cache';
    }

    protected function migrationStubFile(): string
    {
        return __DIR__.'/stubs/cache.stub';
    }
}
