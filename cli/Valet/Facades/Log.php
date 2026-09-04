<?php

namespace Valet\Facades;

/**
 * Class Log.
 *
 * @method static void tail(string $service, int $lines = 50)
 * @method static void aggregate(array $services, int $lines = 50, bool $follow = false, ?string $grep = null)
 * @method static array collect(array $services, int $lines = 50, ?string $grep = null)
 * @method static void openMail()
 */
class Log extends Facade
{
}
