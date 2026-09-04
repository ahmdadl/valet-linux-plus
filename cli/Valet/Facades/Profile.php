<?php

namespace Valet\Facades;

/**
 * Class Profile.
 *
 * @method static string projectPath(?string $sitePath = null)
 * @method static string globalDirectory()
 * @method static string globalPath(string $name)
 * @method static array listProfiles()
 * @method static array show(?string $name = null)
 * @method static array save(?string $name = null, ?array $data = null)
 * @method static array use(string $name, bool $apply = false)
 * @method static string delete(?string $name = null)
 * @method static array apply(array $data)
 * @method static array captureCurrent()
 * @method static void validate(array $data)
 * @method static array schema()
 * @method static void runList()
 * @method static void runShow(?string $name = null)
 * @method static void runSave(?string $name = null)
 * @method static void runUse(string $name, bool $apply = false)
 * @method static void runDelete(?string $name = null)
 */
class Profile extends Facade
{
}
