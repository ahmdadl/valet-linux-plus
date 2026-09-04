<?php

namespace Valet\Facades;

use Illuminate\Support\Collection;

/**
 * Class Ngrok.
 *
 * @method static void                      secure(string $url, string $stub = null)
 * @method static void                      unsecure(string $url, bool $preserveUnsecureConfig = false)
 * @method static Collection<int, string>   secured()
 * @method static void                      regenerateSecuredSitesConfig()
 * @method static void                      reSecureForNewDomain(string $oldDomain, string $domain)
 * @method static array                     trustCaCertificate(bool $checkOnly = false)
 * @method static string                    certificateFilePath(?string $site = null, string $extension = 'crt')
 */
class SiteSecure extends Facade
{
}
