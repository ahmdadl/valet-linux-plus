<?php

namespace Valet\Facades;

/**
 * Class Environment.
 *
 * @method static array<string, string> gather()
 * @method static string databaseUrl(bool $postgres = false)
 * @method static string export(array $vars, string $format = 'dotenv')
 * @method static void run(bool $json = false, ?string $export = null, bool $printDbUrl = false, bool $phpBin = false)
 * @method static array{path: string, created: bool, updated: bool} ensureAndWrite(string $sitePath, array $keys, bool $force = false)
 * @method static bool upsertDotEnvFile(string $envPath, array $keys, bool $overwrite = true)
 */
class Environment extends Facade
{
}
