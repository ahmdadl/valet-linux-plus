<?php

namespace Valet\Facades;

/**
 * Class Environment.
 *
 * @method static array<string, string> gather()
 * @method static string databaseUrl(bool $postgres = false)
 * @method static string export(array $vars, string $format = 'dotenv')
 * @method static void run(bool $json = false, ?string $export = null, bool $printDbUrl = false)
 */
class Environment extends Facade
{
}
