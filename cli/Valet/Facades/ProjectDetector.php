<?php

namespace Valet\Facades;

/**
 * Class ProjectDetector.
 *
 * @method static string|false detect(string $sitePath)
 * @method static string|false detectDriver(string $sitePath, string $siteName)
 * @method static bool isLaravel(string $sitePath)
 * @method static bool isSymfony(string $sitePath)
 * @method static bool isWordPress(string $sitePath)
 * @method static bool isBedrock(string $sitePath)
 * @method static bool isPlain(string $sitePath)
 */
class ProjectDetector extends Facade
{
}