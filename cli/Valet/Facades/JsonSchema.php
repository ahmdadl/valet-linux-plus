<?php

namespace Valet\Facades;

/**
 * Class JsonSchema.
 *
 * @method static array get(string $command)
 * @method static array all()
 * @method static bool isValid(string $command)
 * @method static array envelope(string $command, array $data)
 */
class JsonSchema extends Facade
{
    public const VERSION = 1;
}
