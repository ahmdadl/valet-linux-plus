<?php

namespace Valet\Facades;

/**
 * Class Snapshot.
 *
 * @method static string create(?string $name = null, bool $withDb = false, ?string $notes = null)
 * @method static array list(?string $site = null)
 * @method static void runList()
 * @method static void restore(string $name, bool $force = false)
 * @method static void delete(string $name)
 */
class Snapshot extends Facade
{
}
