<?php

namespace Valet\Facades;

/**
 * Class DashboardPrivilege.
 *
 * @method static bool installed()
 * @method static array{
 *     enabled: bool,
 *     helper_path: string,
 *     helper_installed: bool,
 *     helper_trusted: bool,
 *     sudoers_path: string,
 *     sudoers_installed: bool,
 *     granted_users: array<int, string>,
 *     install_command: string,
 *     verbs: array<int, string>
 * } status()
 * @method static array{ok: bool, message: string, data: array<string|int, mixed>} install()
 * @method static array{ok: bool, message: string, data: array<string|int, mixed>} remove()
 * @method static array{ok: bool, message: string, data: array<string|int, mixed>} run(string $slug, array $params = [])
 * @method static string installUser()
 */
class DashboardPrivilege extends Facade
{
}
