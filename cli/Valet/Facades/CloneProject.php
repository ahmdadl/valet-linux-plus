<?php

namespace Valet\Facades;

/**
 * Class CloneProject.
 *
 * @method static array<int, string> run(string $repository, ?string $directory = null, ?string $branch = null, bool $link = false, bool $init = false, bool $db = false, bool $migrate = false, bool $secure = false, ?string $isolate = null, bool $open = false, bool $ssh = false, bool $https = false, bool $force = false)
 * @method static string resolveRepositoryUrl(string $repository, bool $ssh = false, bool $https = false)
 * @method static string resolveTargetDirectory(string $url, ?string $directory)
 */
class CloneProject extends Facade
{
}
