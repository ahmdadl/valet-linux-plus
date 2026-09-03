<?php

namespace Valet\Facades;

/**
 * Class ProjectContext.
 *
 * @method static array{site: string, url: string, driver: string|false, framework: string|false} fromCwd()
 * @method static string|false envPath()
 * @method static string|false sitePath()
 * @method static string|false driver()
 * @method static string|false framework()
 * @method static string|false url()
 * @method static string|false site()
 */
class ProjectContext extends Facade
{
}