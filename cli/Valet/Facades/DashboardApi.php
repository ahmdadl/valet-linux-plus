<?php

namespace Valet\Facades;

/**
 * Class DashboardApi.
 *
 * @method static array<int, string> slugs()
 * @method static bool has(string $slug)
 * @method static array<string, mixed>|null definition(string $slug)
 * @method static array<int, array<string, mixed>> catalog()
 * @method static array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null} dispatch(\Valet\DashboardRequest $request)
 * @method static array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null} performPrivileged(string $slug, array $params)
 * @method static array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null} execute(string $slug, array $params)
 * @method static array<string, mixed> validateParams(string $slug, array $params)
 */
class DashboardApi extends Facade
{
}
