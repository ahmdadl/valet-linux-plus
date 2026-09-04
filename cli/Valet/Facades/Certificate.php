<?php

namespace Valet\Facades;

/**
 * Class Certificate.
 *
 * @method static array list()
 * @method static array info(string $site)
 * @method static array renew(?string $site = null, bool $force = false)
 * @method static array trust(bool $check = false)
 * @method static array parseCertificate(string $pem)
 * @method static void runList(bool $json = false)
 * @method static void runInfo(?string $site = null)
 * @method static void runRenew(?string $site = null, bool $force = false)
 * @method static void runTrust(bool $check = false)
 */
class Certificate extends Facade
{
}
