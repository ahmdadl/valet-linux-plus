<?php

namespace Valet\Facades;

/**
 * Class ServiceRegistry.
 *
 * @method static array       templates()
 * @method static array       customServices()
 * @method static array       allServices()
 * @method static bool        isCustomService(string $name)
 * @method static array|null  getServiceDefinition(string $name)
 * @method static void        addService(string $name, array $definition)
 * @method static bool        removeService(string $name)
 * @method static void        start(array $services)
 * @method static void        restart(array $services)
 * @method static void        stop(array $services)
 * @method static void        status()
 * @method static array       statusRows()
 */
class ServiceRegistry extends Facade
{
}
