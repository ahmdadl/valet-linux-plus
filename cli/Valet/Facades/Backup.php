<?php

namespace Valet\Facades;

/**
 * Class Backup.
 *
 * @method static string backup(?string $output = null, bool $withDb = false)
 * @method static void   restore(string $archive, bool $force = false)
 * @method static array  listBackups()
 */
class Backup extends Facade
{
}
