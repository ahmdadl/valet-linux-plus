<?php

namespace Valet\Facades;

/**
 * Class SiteSleep.
 *
 * @method static void sleep(string|null $site = null, bool $all = false, bool $withServices = false)
 * @method static void wake(string|null $site = null, bool $all = false)
 * @method static bool isAsleep(string $site)
 * @method static array asleepSites()
 * @method static array statusRows()
 */
class SiteSleep extends Facade
{
}