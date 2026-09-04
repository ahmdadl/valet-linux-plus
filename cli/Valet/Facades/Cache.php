<?php

namespace Valet\Facades;

/**
 * Class Cache.
 *
 * @method static array status()
 * @method static void runStatus(bool $json = false)
 * @method static array paths()
 * @method static void runPath()
 * @method static array clear(bool $composer = false, bool $npm = false, bool $valet = false, bool $yes = false)
 * @method static void runClear(bool $composer = false, bool $npm = false, bool $valet = false, bool $yes = false)
 * @method static array doctor()
 * @method static void runDoctor()
 * @method static string|null composerCacheDir()
 * @method static array valetTempPaths()
 */
class Cache extends Facade
{
}
