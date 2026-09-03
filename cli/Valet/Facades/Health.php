<?php

namespace Valet\Facades;

/**
 * Class Health.
 *
 * @method static array check(string $service)
 * @method static array checkAll()
 * @method static array checkNginx()
 * @method static array checkPhp()
 * @method static array checkMysql()
 * @method static array checkPostgres()
 * @method static array checkRedis()
 * @method static array checkMailpit()
 * @method static array checkCustom(string $name)
 */
class Health extends Facade
{
}
